<?php

namespace App\Services;

use App\Enums\DocStatus;
use App\Enums\RepairStatus;
use App\Enums\WoStatus;
use App\Exceptions\BusinessRuleException;
use App\Helpers\Audit;
use App\Models\QcInspeksi;
use App\Models\QcInspeksiCacat;
use App\Models\QcInspeksiItem;
use App\Models\QcRepair;
use App\Models\Tahap;
use App\Models\User;
use App\Models\WoItem;
use App\Models\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class QcService
{
    /**
     * Mencatat hasil inspeksi QC (100% inspeksi per pcs, bagian 4.9 rancangan).
     *
     * Setiap item: qty_diperiksa = qty_lolos + qty_repair + qty_reject,
     * qty_diperiksa <= qc_tersedia, dan jumlah cacat per tindakan harus sama
     * dengan qty_repair / qty_reject.
     */
    public function catatInspeksi(WorkOrder $wo, array $items, string $tanggal, int $userId, ?string $catatan = null): QcInspeksi
    {
        $user = User::findOrFail($userId);
        if (! $user->hasRole('admin', 'qc')) {
            throw new BusinessRuleException('Hanya QC atau Admin yang dapat mencatat inspeksi.', 'TIDAK_ADA_HAK', 403);
        }
        if (count($items) < 1) {
            throw new BusinessRuleException('Minimal satu item inspeksi.', 'VALIDASI_GAGAL');
        }

        $repairBaru = [];

        $qc = DB::transaction(function () use ($wo, $items, $tanggal, $userId, $catatan, &$repairBaru) {
            $wo = WorkOrder::whereKey($wo->id)->lockForUpdate()->firstOrFail();
            if ($wo->status !== WoStatus::Aktif) {
                throw new BusinessRuleException('Inspeksi hanya dapat dicatat untuk WO yang aktif.', 'STATUS_TIDAK_VALID');
            }

            $qc = QcInspeksi::create([
                'no_qc' => NumberingService::next('QC'),
                'wo_id' => $wo->id,
                'tanggal' => $tanggal,
                'catatan' => $catatan,
                'status' => 'aktif',
                'qc_id' => $userId,
            ]);

            foreach ($items as $item) {
                $woItem = WoItem::where('id', $item['wo_item_id'] ?? 0)->lockForUpdate()->firstOrFail();
                if ((int) $woItem->wo_id !== (int) $wo->id) {
                    throw new BusinessRuleException('Item tidak termasuk dalam Work Order ini.', 'VALIDASI_GAGAL');
                }

                $diperiksa = (int) ($item['qty_diperiksa'] ?? 0);
                $lolos = (int) ($item['qty_lolos'] ?? 0);
                $repair = (int) ($item['qty_repair'] ?? 0);
                $reject = (int) ($item['qty_reject'] ?? 0);

                if ($diperiksa < 1) {
                    throw new BusinessRuleException('Qty diperiksa minimal 1.', 'VALIDASI_GAGAL');
                }
                if ($diperiksa !== $lolos + $repair + $reject) {
                    throw new BusinessRuleException('Total diperiksa harus sama dengan jumlah lolos, repair, dan reject.', 'VALIDASI_GAGAL');
                }

                // qc_tersedia = qty_finishing + qty_repair_selesai - qty_qc_diperiksa
                $qcTersedia = $woItem->qty_finishing + $woItem->qty_repair_selesai - $woItem->qty_qc_diperiksa;
                if ($diperiksa > $qcTersedia) {
                    throw new BusinessRuleException('Qty diperiksa melebihi barang yang siap diinspeksi (maks: '.max(0, $qcTersedia).').', 'QTY_MELEBIHI_BATAS');
                }

                $cacat = $this->validasiCacat($item['cacat'] ?? [], $repair, $reject);

                $qcItem = QcInspeksiItem::create([
                    'qc_inspeksi_id' => $qc->id,
                    'wo_item_id' => $woItem->id,
                    'qty_diperiksa' => $diperiksa,
                    'qty_lolos' => $lolos,
                    'qty_repair' => $repair,
                    'qty_reject' => $reject,
                ]);

                foreach ($cacat as $c) {
                    QcInspeksiCacat::create([
                        'qc_inspeksi_item_id' => $qcItem->id,
                        'jenis_cacat_id' => $c['jenis_cacat_id'],
                        'qty' => $c['qty'],
                        'tindakan' => $c['tindakan'],
                    ]);
                }

                if ($repair > 0) {
                    $tahapTujuanId = $this->resolveTahapTujuan($item['tahap_tujuan_id'] ?? null);
                    QcRepair::create([
                        'qc_inspeksi_item_id' => $qcItem->id,
                        'wo_item_id' => $woItem->id,
                        'qty' => $repair,
                        'tahap_tujuan_id' => $tahapTujuanId,
                        'status' => 'open',
                        'qty_selesai' => 0,
                    ]);
                    $woItem->qty_repair_open += $repair;
                    $repairBaru[] = ['tahap_id' => $tahapTujuanId, 'qty' => $repair, 'no_qc' => $qc->no_qc, 'no_wo' => $wo->no_wo];
                }

                $woItem->qty_qc_diperiksa += $diperiksa;
                $woItem->qty_qc_lolos += $lolos;
                $woItem->qty_qc_reject += $reject;
                $woItem->save();
            }

            Audit::log($userId, 'qc.inspeksi', 'QcInspeksi', $qc->id, null, ['no_qc' => $qc->no_qc, 'wo_id' => $wo->id, 'jumlah_item' => count($items)], $catatan);

            return $qc;
        });

        // Notifikasi dikirim setelah transaksi berhasil (bagian 4.15: ada repair open)
        foreach ($repairBaru as $r) {
            NotificationService::toRoles(
                ['supervisor'],
                'Ada repair baru',
                "{$r['qty']} pcs dari {$r['no_qc']} ({$r['no_wo']}) perlu diperbaiki.",
                null,
                'peringatan',
                null,
                $r['tahap_id'],
            );
        }

        return $qc->fresh(['qcInspeksiItems.qcInspeksiCacat', 'qcInspeksiItems.qcRepair']);
    }

    /**
     * Menyelesaikan repair (boleh sebagian). Pcs yang selesai kembali ke antrean QC.
     */
    public function selesaikanRepair(int $repairId, int $qtySelesai, int $userId, ?string $catatan = null): QcRepair
    {
        $user = User::findOrFail($userId);
        if ($qtySelesai < 1) {
            throw new BusinessRuleException('Qty selesai minimal 1.', 'VALIDASI_GAGAL');
        }

        return DB::transaction(function () use ($repairId, $qtySelesai, $userId, $catatan, $user) {
            $repair = QcRepair::whereKey($repairId)->lockForUpdate()->firstOrFail();

            $berhak = $user->hasRole('admin')
                || ($user->hasRole('supervisor') && (int) $user->tahap_id === (int) $repair->tahap_tujuan_id);
            if (! $berhak) {
                throw new BusinessRuleException('Hanya supervisor tahap tujuan atau Admin yang dapat menyelesaikan repair.', 'TIDAK_ADA_HAK', 403);
            }

            if ($repair->status === RepairStatus::Selesai) {
                throw new BusinessRuleException('Repair sudah selesai.', 'STATUS_TIDAK_VALID', 409);
            }

            $woItem = WoItem::whereKey($repair->wo_item_id)->lockForUpdate()->firstOrFail();
            $wo = WorkOrder::whereKey($woItem->wo_id)->lockForUpdate()->firstOrFail();
            if ($wo->status !== WoStatus::Aktif) {
                throw new BusinessRuleException('WO sudah tidak aktif.', 'STATUS_TIDAK_VALID');
            }

            $sisaOpen = $repair->qty - $repair->qty_selesai;
            if ($qtySelesai > $sisaOpen) {
                throw new BusinessRuleException("Qty selesai melebihi sisa repair (maks: {$sisaOpen}).", 'QTY_MELEBIHI_BATAS');
            }

            $repair->qty_selesai += $qtySelesai;
            if ($catatan !== null) {
                $repair->catatan = $catatan;
            }
            if ($repair->qty_selesai >= $repair->qty) {
                $repair->status = 'selesai';
                $repair->selesai_at = now();
                $repair->diselesaikan_oleh = $userId;
            }
            $repair->save();

            $woItem->qty_repair_open -= $qtySelesai;
            $woItem->qty_repair_selesai += $qtySelesai;
            $woItem->save();

            Audit::log($userId, 'qc.repair_selesai', 'QcRepair', $repair->id, null, ['qty_selesai' => $qtySelesai, 'total_selesai' => $repair->qty_selesai], $catatan);

            return $repair->fresh();
        });
    }

    /**
     * Void inspeksi dengan pengecekan dampak (counter tidak boleh membuat ketersediaan negatif).
     *
     * Hak: Admin/Manajer kapan saja; QC pembuat dalam batas_void_jam.
     * Baris qc_repair turunan dari inspeksi ini ikut dihapus karena belum dikerjakan
     * (repair yang sudah dikerjakan menolak void).
     */
    public function voidInspeksi(int $id, string $alasan, int $userId): QcInspeksi
    {
        $user = User::findOrFail($userId);
        if (mb_strlen(trim($alasan)) < 5) {
            throw new BusinessRuleException('Alasan void wajib minimal 5 karakter.', 'ALASAN_WAJIB');
        }

        return DB::transaction(function () use ($id, $alasan, $userId, $user) {
            $qc = QcInspeksi::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($qc->status !== DocStatus::Aktif) {
                throw new BusinessRuleException('Inspeksi sudah void.', 'STATUS_TIDAK_VALID', 409);
            }

            $batasJam = (int) (DB::table('pengaturan')->where('key', 'batas_void_jam')->value('value') ?? 24);
            $dalamBatas = $qc->created_at->diffInHours(now()) <= $batasJam;
            $berhak = $user->hasRole('admin', 'manajer')
                || ($user->hasRole('qc') && (int) $qc->qc_id === $userId && $dalamBatas);
            if (! $berhak) {
                throw new BusinessRuleException('Anda tidak berhak melakukan void inspeksi ini.', 'TIDAK_ADA_HAK', 403);
            }

            $wo = WorkOrder::whereKey($qc->wo_id)->lockForUpdate()->firstOrFail();
            if ($wo->status !== WoStatus::Aktif) {
                throw new BusinessRuleException('Inspeksi tidak dapat di-void karena WO sudah tidak aktif.', 'STATUS_TIDAK_VALID');
            }

            foreach ($qc->qcInspeksiItems as $qcItem) {
                $woItem = WoItem::whereKey($qcItem->wo_item_id)->lockForUpdate()->firstOrFail();

                $repairDikerjakan = QcRepair::where('qc_inspeksi_item_id', $qcItem->id)->sum('qty_selesai');
                if ($repairDikerjakan > 0) {
                    throw new BusinessRuleException('Void ditolak karena repair dari inspeksi ini sudah dikerjakan.', 'VOID_DITOLAK_HILIR_NEGATIF');
                }

                $lolosBaru = $woItem->qty_qc_lolos - $qcItem->qty_lolos;
                $diperiksaBaru = $woItem->qty_qc_diperiksa - $qcItem->qty_diperiksa;
                $rejectBaru = $woItem->qty_qc_reject - $qcItem->qty_reject;
                $repairOpenBaru = $woItem->qty_repair_open - $qcItem->qty_repair;

                if (min($lolosBaru, $woItem->qty_order) < $woItem->qty_packing) {
                    throw new BusinessRuleException('Void ditolak karena barang lolos QC sudah dikemas (packing).', 'VOID_DITOLAK_HILIR_NEGATIF');
                }
                if ($diperiksaBaru < 0 || $rejectBaru < 0 || $repairOpenBaru < 0 || $lolosBaru < 0) {
                    throw new BusinessRuleException('Void ditolak karena membuat counter QC negatif.', 'VOID_DITOLAK_HILIR_NEGATIF');
                }

                $woItem->qty_qc_diperiksa = $diperiksaBaru;
                $woItem->qty_qc_lolos = $lolosBaru;
                $woItem->qty_qc_reject = $rejectBaru;
                $woItem->qty_repair_open = $repairOpenBaru;
                $woItem->save();

                QcRepair::where('qc_inspeksi_item_id', $qcItem->id)->delete();
            }

            $qc->update([
                'status' => 'void',
                'void_alasan' => $alasan,
                'void_oleh' => $userId,
                'void_at' => now(),
            ]);

            Audit::log($userId, 'qc.void', 'QcInspeksi', $qc->id, ['status' => 'aktif'], ['status' => 'void'], $alasan);

            return $qc->fresh();
        });
    }

    /**
     * Antrean inspeksi: WO aktif beserta item yang qc_tersedia > 0.
     */
    public function antrean(int $userId): Collection
    {
        $user = User::findOrFail($userId);
        if (! $user->hasRole('admin', 'manajer', 'qc')) {
            throw new BusinessRuleException('Anda tidak berhak melihat antrean QC.', 'TIDAK_ADA_HAK', 403);
        }

        return WorkOrder::with(['order.pelanggan', 'woItems.orderItem.produk', 'woItems.orderItem.warna', 'woItems.orderItem.ukuran'])
            ->where('status', 'aktif')
            ->whereHas('woItems', fn ($q) => $q->whereRaw('qty_finishing + qty_repair_selesai - qty_qc_diperiksa > 0'))
            ->latest('id')
            ->get()
            ->map(function (WorkOrder $wo) {
                $wo->setRelation(
                    'woItems',
                    $wo->woItems->filter(fn (WoItem $i) => $i->qc_tersedia > 0)->map(fn (WoItem $i) => $i->append('qc_tersedia'))->values()
                );

                return $wo;
            })
            ->values();
    }

    public function riwayat(int $userId, array $filter = [], int $perPage = 25): LengthAwarePaginator
    {
        $user = User::findOrFail($userId);
        if (! $user->hasRole('admin', 'manajer', 'qc', 'pimpinan')) {
            throw new BusinessRuleException('Anda tidak berhak melihat riwayat inspeksi.', 'TIDAK_ADA_HAK', 403);
        }

        return QcInspeksi::with(['wo.order', 'qc', 'qcInspeksiItems.qcInspeksiCacat.jenisCacat'])
            ->when($filter['wo_id'] ?? null, fn ($q, $v) => $q->where('wo_id', $v))
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['tgl_dari'] ?? null, fn ($q, $v) => $q->whereDate('tanggal', '>=', $v))
            ->when($filter['tgl_sampai'] ?? null, fn ($q, $v) => $q->whereDate('tanggal', '<=', $v))
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * Daftar repair. Supervisor otomatis hanya melihat repair untuk tahap tujuannya.
     */
    public function daftarRepair(int $userId, array $filter = [], int $perPage = 25): LengthAwarePaginator
    {
        $user = User::findOrFail($userId);
        if (! $user->hasRole('admin', 'manajer', 'supervisor', 'qc', 'pimpinan')) {
            throw new BusinessRuleException('Anda tidak berhak melihat antrean repair.', 'TIDAK_ADA_HAK', 403);
        }

        $query = QcRepair::with(['woItem.wo.order', 'woItem.orderItem.produk', 'woItem.orderItem.warna', 'woItem.orderItem.ukuran', 'tahapTujuan', 'qcInspeksiItem.qcInspeksiCacat.jenisCacat'])
            ->where('status', $filter['status'] ?? 'open');

        if ($user->hasRole('supervisor')) {
            $query->where('tahap_tujuan_id', $user->tahap_id);
        } elseif (! empty($filter['tahap_id'])) {
            $query->where('tahap_tujuan_id', $filter['tahap_id']);
        }

        return $query->latest('id')->paginate($perPage);
    }

    /**
     * Cacat wajib dicatat untuk setiap pcs repair/reject dan jumlahnya harus cocok.
     *
     * @return array<int, array{jenis_cacat_id:int, qty:int, tindakan:string}>
     */
    private function validasiCacat(array $cacat, int $qtyRepair, int $qtyReject): array
    {
        $hasil = [];
        $sumRepair = 0;
        $sumReject = 0;

        foreach ($cacat as $c) {
            $tindakan = $c['tindakan'] ?? null;
            $qty = (int) ($c['qty'] ?? 0);
            if (! in_array($tindakan, ['repair', 'reject'], true) || $qty < 1 || empty($c['jenis_cacat_id'])) {
                throw new BusinessRuleException('Data cacat tidak valid: jenis cacat, qty, dan tindakan wajib diisi.', 'VALIDASI_GAGAL');
            }

            if ($tindakan === 'repair') {
                $sumRepair += $qty;
            } else {
                $sumReject += $qty;
            }
            $hasil[] = ['jenis_cacat_id' => (int) $c['jenis_cacat_id'], 'qty' => $qty, 'tindakan' => $tindakan];
        }

        if ($sumRepair !== $qtyRepair || $sumReject !== $qtyReject) {
            throw new BusinessRuleException('Jumlah cacat per tindakan harus sama dengan qty repair dan qty reject.', 'VALIDASI_GAGAL');
        }

        return $hasil;
    }

    /**
     * Tahap tujuan repair: sewing atau finishing. Default sewing bila tidak diisi.
     */
    private function resolveTahapTujuan($tahapId): int
    {
        if ($tahapId === null || $tahapId === '') {
            $id = Tahap::where('kode', 'sewing')->value('id');
        } else {
            $id = Tahap::whereKey((int) $tahapId)->whereIn('kode', ['sewing', 'finishing'])->value('id');
        }

        if (! $id) {
            throw new BusinessRuleException('Tahap tujuan repair harus sewing atau finishing.', 'VALIDASI_GAGAL');
        }

        return (int) $id;
    }
}