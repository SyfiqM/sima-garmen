<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Helpers\Audit;
use App\Helpers\NumberingService;
use App\Models\PengeluaranBahan;
use App\Models\PengeluaranBahanItem;
use App\Models\Tahap;
use App\Models\User;
use App\Models\WoBahan;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class MaterialIssueService
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly ReservationService $reservationService,
    ) {}

    public function store(array $data, int $userId): PengeluaranBahan
    {
        return DB::transaction(function () use ($data, $userId) {
            $workOrder = WorkOrder::whereKey($data['wo_id'])->lockForUpdate()->firstOrFail();
            $tahap = Tahap::findOrFail($data['tahap_id']);
            $tipe = $data['tipe'];
            $items = $data['items'];
            $perluPersetujuan = false;

            // Bagian 6.2 rancangan: pengeluaran hanya untuk WO siap_produksi/produksi. Tanpa ini order
            // menunggu_bahan bisa mengeluarkan bahan lalu masih bisa dibatalkan/direvisi (melanggar 4.3).
            if ($tipe === 'keluar' && ! in_array($workOrder->order()->firstOrFail()->status->value, ['siap_produksi', 'produksi'], true)) {
                throw new BusinessRuleException('Pengeluaran bahan hanya untuk order berstatus siap produksi atau produksi.', 'STATUS_TIDAK_VALID', 422);
            }

            $alasanDokumen = $data['alasan_lebih'] ?? collect($items)->pluck('alasan_lebih')->first(fn ($alasan) => is_string($alasan) && trim($alasan) !== '');

            foreach ($items as $item) {
                $woBahan = WoBahan::whereKey($item['wo_bahan_id'])
                    ->where('wo_id', $workOrder->id)
                    ->where('tahap_id', $tahap->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($tipe === 'keluar') {
                    $sisaReservasi = max(0, round((float) $woBahan->qty_reservasi - (float) $woBahan->qty_keluar, 3));
                    if ((float) $item['qty'] > $sisaReservasi) {
                        $perluPersetujuan = true;
                        $this->validasiPengeluaranLebih($woBahan, (float) $item['qty'], $item['alasan_lebih'] ?? $alasanDokumen);
                    }
                } else {
                    $this->validasiRetur($workOrder, $woBahan, (float) $item['qty']);
                }
            }

            $dokumen = PengeluaranBahan::create([
                'no_pb' => NumberingService::next('PB'),
                'wo_id' => $workOrder->id,
                'tahap_id' => $tahap->id,
                'tipe' => $tipe,
                'tanggal' => $data['tanggal'],
                'catatan' => $data['catatan'] ?? null,
                'alasan_lebih' => $alasanDokumen,
                'status' => 'aktif',
                'status_persetujuan' => $perluPersetujuan ? 'menunggu' : 'tidak_perlu',
                'dibuat_oleh' => $userId,
            ]);

            foreach ($items as $item) {
                $woBahan = WoBahan::whereKey($item['wo_bahan_id'])->lockForUpdate()->firstOrFail();
                $detail = PengeluaranBahanItem::create([
                    'pengeluaran_id' => $dokumen->id,
                    'wo_bahan_id' => $woBahan->id,
                    'bahan_id' => $woBahan->bahan_id,
                    'qty' => $item['qty'],
                ]);

                if (! $perluPersetujuan) {
                    $this->applyItem($dokumen, $detail, $woBahan, $userId);
                }
            }

            if (class_exists(Audit::class)) {
                Audit::log($userId, 'pengeluaran.create', 'PengeluaranBahan', $dokumen->id, null, $dokumen->toArray(), $alasanDokumen);
            }

            if ($perluPersetujuan) {
                NotificationService::toRoles(
                    ['manajer'],
                    'Pengeluaran butuh persetujuan',
                    "Pengeluaran {$dokumen->no_pb} untuk {$workOrder->no_wo} melebihi reservasi dan menunggu persetujuan.",
                    null,
                    'peringatan',
                    $userId,
                );
            }

            return $dokumen->load('pengeluaranBahanItems.bahan', 'tahap', 'wo');
        });
    }

    public function bahanUntukWo(int $workOrderId, int $tahapId): array
    {
        return WoBahan::with('bahan.satuan')
            ->where('wo_id', $workOrderId)
            ->where('tahap_id', $tahapId)
            ->get()
            ->map(fn (WoBahan $woBahan) => [
                'wo_bahan_id' => $woBahan->id,
                'bahan' => $woBahan->bahan,
                'qty_rencana' => (float) $woBahan->qty_rencana,
                'qty_reservasi' => (float) $woBahan->qty_reservasi,
                'qty_keluar' => (float) $woBahan->qty_keluar,
                'qty_retur' => (float) $woBahan->qty_retur,
                'qty_boleh_keluar' => max(0, round((float) $woBahan->qty_reservasi - (float) $woBahan->qty_keluar, 3)),
            ])
            ->all();
    }

    public function setujui(int $id, int $userId): PengeluaranBahan
    {
        return DB::transaction(function () use ($id, $userId) {
            $dokumen = PengeluaranBahan::with('pengeluaranBahanItems')
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($dokumen->status->value !== 'aktif' || $dokumen->status_persetujuan !== 'menunggu' || $dokumen->tipe->value !== 'keluar') {
                throw new BusinessRuleException('Pengeluaran ini tidak menunggu persetujuan.', 'STATUS_TIDAK_VALID', 409);
            }

            foreach ($dokumen->pengeluaranBahanItems as $detail) {
                $woBahan = WoBahan::whereKey($detail->wo_bahan_id)->lockForUpdate()->firstOrFail();
                $this->validasiPengeluaranLebih($woBahan, (float) $detail->qty, $dokumen->alasan_lebih);
                $this->applyItem($dokumen, $detail, $woBahan, $userId);
            }

            $dokumen->update(['status_persetujuan' => 'disetujui', 'disetujui_oleh' => $userId]);
            Audit::log($userId, 'pengeluaran.setujui', 'PengeluaranBahan', $dokumen->id, null, $dokumen->toArray(), $dokumen->alasan_lebih);

            return $dokumen->load('pengeluaranBahanItems.bahan');
        });
    }

    public function void(int $id, string $alasan, int $userId): PengeluaranBahan
    {
        return DB::transaction(function () use ($id, $alasan, $userId) {
            $dokumen = PengeluaranBahan::with('pengeluaranBahanItems')
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($dokumen->status->value !== 'aktif') {
                throw new BusinessRuleException('Dokumen sudah void.', 'STATUS_TIDAK_VALID', 409);
            }

            $user = User::findOrFail($userId);
            $batasVoidJam = (int) (DB::table('pengaturan')->where('key', 'batas_void_jam')->value('value') ?? 24);
            $dibuatDalamBatas = $dokumen->created_at->diffInHours(now()) <= $batasVoidJam;
            if ((! $dibuatDalamBatas || $dokumen->dibuat_oleh !== $userId) && ! $user->hasRole('admin', 'manajer')) {
                throw new BusinessRuleException('Anda tidak berhak melakukan void dokumen ini.', 'TIDAK_ADA_HAK', 403);
            }

            if ($dokumen->status_persetujuan !== 'menunggu') {
                foreach ($dokumen->pengeluaranBahanItems as $detail) {
                    $woBahan = WoBahan::whereKey($detail->wo_bahan_id)->lockForUpdate()->firstOrFail();
                    if ($dokumen->tipe->value === 'keluar') {
                        $this->stockService->mutasi($detail->bahan_id, 'koreksi', (float) $detail->qty, 'mutasi', $dokumen->id, "Void {$dokumen->no_pb}", $userId);
                        $this->reservationService->batalkanPengeluaran($woBahan, (float) $detail->qty);
                    } else {
                        $this->stockService->mutasi($detail->bahan_id, 'koreksi', -(float) $detail->qty, 'mutasi', $dokumen->id, "Void retur {$dokumen->no_pb}", $userId);
                        $woBahan->qty_retur = round((float) $woBahan->qty_retur - (float) $detail->qty, 3);
                        $woBahan->save();
                    }
                }
            }

            $dokumen->update(['status' => 'void', 'void_alasan' => $alasan, 'void_oleh' => $userId, 'void_at' => now()]);
            $this->reservationService->alokasiUlang();
            Audit::log($userId, 'pengeluaran.void', 'PengeluaranBahan', $dokumen->id, null, $dokumen->toArray(), $alasan);

            return $dokumen;
        });
    }

    private function validasiPengeluaranLebih(WoBahan $woBahan, float $qty, ?string $alasan): void
    {
        if ($alasan === null || mb_strlen(trim($alasan)) < 5) {
            throw new BusinessRuleException('Alasan pengeluaran melebihi reservasi wajib diisi minimal 5 karakter.', 'ALASAN_WAJIB', 422);
        }

        $toleransi = (float) (DB::table('pengaturan')->where('key', 'toleransi_bahan_persen')->value('value') ?? 5);
        $batasTotal = round((float) $woBahan->qty_rencana * (1 + ($toleransi / 100)), 3);
        if (round((float) $woBahan->qty_keluar + $qty, 3) > $batasTotal) {
            throw new BusinessRuleException('Pengeluaran melebihi batas toleransi bahan.', 'MELEBIHI_TOLERANSI', 422);
        }
    }

    private function validasiRetur(WorkOrder $workOrder, WoBahan $woBahan, float $qty): void
    {
        $tahapSelesai = DB::table('wo_tahap')
            ->where('wo_id', $workOrder->id)
            ->where('tahap_id', $woBahan->tahap_id)
            ->where('status', 'selesai')
            ->exists();

        if (! $tahapSelesai && ! in_array($workOrder->status->value, ['dihentikan', 'ditutup_kurang'], true)) {
            throw new BusinessRuleException('Retur hanya dapat dicatat setelah tahap selesai atau WO dihentikan/ditutup kurang.', 'STATUS_TIDAK_VALID', 422);
        }

        if (round((float) $woBahan->qty_retur + $qty, 3) > (float) $woBahan->qty_keluar) {
            throw new BusinessRuleException('Qty retur melebihi qty bahan yang telah dikeluarkan.', 'QTY_MELEBIHI_BATAS', 422);
        }
    }

    private function applyItem(PengeluaranBahan $dokumen, PengeluaranBahanItem $detail, WoBahan $woBahan, int $userId): void
    {
        $qty = (float) $detail->qty;
        if ($dokumen->tipe->value === 'keluar') {
            $bahan = $woBahan->bahan()->lockForUpdate()->firstOrFail();
            $sisaReservasi = max(0, round((float) $woBahan->qty_reservasi - (float) $woBahan->qty_keluar, 3));
            $kelebihan = max(0, round($qty - $sisaReservasi, 3));
            if ($kelebihan > round((float) $bahan->stok - (float) $bahan->stok_dipesan, 3)) {
                throw new BusinessRuleException("Stok tersedia {$bahan->kode} tidak mencukupi.", 'STOK_TIDAK_CUKUP', 422);
            }

            $this->reservationService->catatPengeluaran($woBahan, $qty);
            $this->stockService->mutasi($detail->bahan_id, 'keluar', -$qty, 'pengeluaran', $dokumen->id, "Pengeluaran No: {$dokumen->no_pb}", $userId);

            $order = $dokumen->wo->order;
            if ($order->status->value === 'siap_produksi') {
                $order->update(['status' => 'produksi']);
            }
        } else {
            $this->stockService->mutasi($detail->bahan_id, 'retur', $qty, 'pengeluaran', $dokumen->id, "Retur No: {$dokumen->no_pb}", $userId);
            $woBahan->qty_retur = round((float) $woBahan->qty_retur + $qty, 3);
            $woBahan->save();
            $this->reservationService->alokasiUlang();
        }
    }
}