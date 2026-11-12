<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Helpers\Audit;
use App\Helpers\NumberingService;
use App\Enums\OrderStatus;
use App\Enums\WoStatus;
use App\Models\Order;
use App\Models\Tahap;
use App\Models\User;
use App\Models\WoBahan;
use App\Models\WoItem;
use App\Models\WorkOrder;
use App\Models\WoTahap;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class WorkOrderService
{
    protected $bomService;
    protected $reservationService;

    public function __construct(BomService $bomService, ReservationService $reservationService)
    {
        $this->bomService = $bomService;
        $this->reservationService = $reservationService;
    }

    public function buatDariOrder(Order $order, int $userId)
    {
        $this->bomService->validasiKelengkapan($order);
        $kebutuhanBahan = $this->bomService->hitungKebutuhan($order);
        $cuttingId = Tahap::where('kode', 'cutting')->value('id');

        if (empty($kebutuhanBahan)) {
            throw new BusinessRuleException('BOM belum lengkap. Minimal ada satu bahan di tahap cutting.', 'BOM_BELUM_LENGKAP', 422);
        }

        // Ambil pengaturan dinamis
        $bufferKirim = DB::table('pengaturan')->where('key', 'buffer_kirim_hari')->value('value') ?? 2;
        $toleransiPotong = DB::table('pengaturan')->where('key', 'toleransi_potong_persen')->value('value') ?? 3;

        // 2. Buat Header Work Order
        $wo = WorkOrder::create([
            'no_wo' => NumberingService::next('WO'),
            'order_id' => $order->id,
            'revisi_ke' => $order->revisi_ke,
            'status' => 'aktif',
            'tgl_target' => Carbon::parse($order->deadline)->subDays((int) $bufferKirim)->format('Y-m-d'),
            'dibuat_oleh' => $userId,
        ]);

        // 3. Buat wo_items dengan batas toleransi potong
        foreach ($order->items as $item) {
            // Diperbaiki: ditambah tanda * untuk perkalian
            $qtyBatas = $item->qty + ceil($item->qty * ((float) $toleransiPotong / 100));
            
            WoItem::create([
                'wo_id' => $wo->id,
                'order_item_id' => $item->id,
                'qty_order' => $item->qty,
                'qty_batas_potong' => $qtyBatas,
            ]);
        }

        // 4. Buat 3 baris tahapan (cutting, sewing, finishing)
        $tahapList = Tahap::orderBy('urutan')->get();
        foreach ($tahapList as $tahap) {
            WoTahap::create(['wo_id' => $wo->id, 'tahap_id' => $tahap->id, 'status' => 'menunggu']);
        }

        // 5 & 6. Snapshot BOM, Rekam ke wo_bahan, & Lakukan Reservasi
        $siapProduksi = true;

        foreach ($kebutuhanBahan as $keb) {
            $woBahan = WoBahan::create([
                'wo_id' => $wo->id,
                'bahan_id' => $keb['bahan_id'],
                'tahap_id' => $keb['tahap_id'],
                'qty_rencana' => $keb['qty_rencana'],
            ]);

            // Alokasi reservasi (dilakukan dalam transaksi lock)
            $this->reservationService->alokasi($woBahan);
            $woBahan->refresh();

            // 7. Penentuan Status Order (siap_produksi atau menunggu_bahan)
            if ($woBahan->tahap_id == $cuttingId && $woBahan->qty_reservasi < $woBahan->qty_rencana) {
                $siapProduksi = false;
            }
        }

        return $siapProduksi;
    }

    /**
     * Memeriksa dan mengeksekusi penyelesaian produksi secara otomatis.
     * Dipanggil setiap kali ada penambahan koli (packing) dari Batch 6.
     */
    public function cekSelesai(WorkOrder $wo)
    {
        // Hanya WO aktif yang bisa diselesaikan otomatis
        if ($wo->status !== WoStatus::Aktif) {
            return;
        }

        $wo->load('woItems');

        $selesaiSemua = true;
        foreach ($wo->woItems as $item) {
            // Syarat selesai: yang di-packing harus persis sama dengan yang dipesan
            if ($item->qty_packing < $item->qty_order) {
                $selesaiSemua = false;
                break;
            }
        }

        if ($selesaiSemua) {
            // 1. Ubah status WO
            $wo->update([
                'status' => 'selesai',
                'produksi_selesai_at' => now(),
            ]);

            // 2. Tutup semua tahap produksi (cutting, sewing, finishing) yang masih terbuka
            WoTahap::where('wo_id', $wo->id)
                   ->whereIn('status', ['menunggu', 'berjalan'])
                   ->update([
                       'status' => 'selesai', 
                       'selesai_at' => now(), 
                       'catatan_penutupan' => 'Ditutup otomatis oleh sistem karena produksi selesai.'
                   ]);

            // 3. Status order diturunkan dari produksi dan pengiriman (OrderStatusService).
            app(OrderStatusService::class)->hitungUlang($wo->order);
        }
    }

    public function tutupKurang(WorkOrder $wo, string $alasan, int $userId)
    {
        $user = User::findOrFail($userId);
        if (! $user->hasRole('admin', 'manajer')) {
            throw new BusinessRuleException('Hanya Manajer atau Admin yang dapat menutup WO kurang.', 'TIDAK_ADA_HAK', 403);
        }

        return DB::transaction(function () use ($wo, $alasan, $userId) {
            $wo = WorkOrder::whereKey($wo->id)->lockForUpdate()->firstOrFail();

            if (empty(trim($alasan)) || strlen($alasan) < 5) {
                throw new BusinessRuleException('Alasan tutup kurang wajib diisi minimal 5 karakter.', 'ALASAN_WAJIB');
            }

            if ($wo->status !== WoStatus::Aktif) {
                throw new BusinessRuleException('Hanya WO berstatus aktif yang bisa ditutup kurang.', 'STATUS_TIDAK_VALID');
            }

            $wo->load('woItems');

            // Syarat: qc_tersedia = 0 dan qty_repair_open = 0 untuk semua item
            foreach ($wo->woItems as $item) {
                $qcTersedia = $item->qty_finishing + $item->qty_repair_selesai - $item->qty_qc_diperiksa;
                if ($qcTersedia > 0 || $item->qty_repair_open > 0) {
                    throw new BusinessRuleException('Masih ada barang di antrean QC atau Repair. Selesaikan dahulu sebelum tutup kurang.', 'STATUS_TIDAK_VALID');
                }
            }

            // 1. Update WO
            $wo->update([
                'status' => 'ditutup_kurang',
                'produksi_selesai_at' => now(),
            ]);

            // 2. Tutup semua tahap yang masih terbuka
            WoTahap::where('wo_id', $wo->id)
                   ->whereIn('status', ['menunggu', 'berjalan'])
                   ->update([
                       'status' => 'selesai', 
                       'selesai_at' => now(), 
                       'catatan_penutupan' => 'Ditutup kurang oleh manajer.'
                   ]);

            // 3. Update Order
            //    Status order diturunkan oleh OrderStatusService (tidak menurunkan dikirim_sebagian
            //    atau order yang dihentikan).
            $order = $wo->order;
            $order->update(['ditutup_kurang' => 1, 'alasan_tutup_kurang' => $alasan]);
            app(OrderStatusService::class)->hitungUlang($order, $userId);

            Audit::log($userId, 'wo.tutup_kurang', 'WorkOrder', $wo->id, ['status' => 'aktif'], ['status' => 'ditutup_kurang'], $alasan);

            return $wo->fresh();
        });
    }

    /**
     * Daftar WO. Supervisor hanya WO yang memiliki tahapnya; QC hanya WO yang sudah
     * menghasilkan barang di finishing (bagian 3.2 rancangan).
     */
    public function daftar(User $user, array $filter = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = WorkOrder::with(['order.pelanggan'])->withCount('woItems');
        $this->batasiAkses($query, $user);

        return $query
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['order_id'] ?? null, fn ($q, $v) => $q->where('order_id', $v))
            ->when($filter['search'] ?? null, fn ($q, $v) => $q->where(function ($w) use ($v) {
                $w->where('no_wo', 'like', "%{$v}%")
                    ->orWhereHas('order', fn ($o) => $o->where('no_order', 'like', "%{$v}%"));
            }))
            ->latest('id')
            ->paginate($perPage);
    }

    public function detail(User $user, int $id): WorkOrder
    {
        $query = WorkOrder::with([
            'order.pelanggan',
            'woItems.orderItem.produk', 'woItems.orderItem.warna', 'woItems.orderItem.ukuran',
            'woTahap.tahap',
            'woBahan.bahan',
            'packing.packingItems',
        ]);
        $this->batasiAkses($query, $user);

        $wo = $query->findOrFail($id);
        $wo->woItems->each(fn (WoItem $i) => $i->append([
            'cutting_boleh', 'sewing_tersedia', 'finishing_tersedia', 'qc_tersedia', 'packing_tersedia', 'kirim_tersedia',
        ]));

        return $wo;
    }

    private function batasiAkses($query, User $user): void
    {
        if ($user->hasRole('supervisor')) {
            $query->whereHas('woTahap', fn ($q) => $q->where('tahap_id', $user->tahap_id));
        } elseif ($user->hasRole('qc')) {
            $query->whereHas('woItems', fn ($q) => $q->where('qty_finishing', '>', 0));
        }
    }
}