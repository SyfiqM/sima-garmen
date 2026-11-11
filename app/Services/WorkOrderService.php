<?php

namespace App\Services;

use App\Models\Order;
use App\Models\WorkOrder;
use App\Models\WoItem;
use App\Models\WoTahap;
use App\Models\WoBahan;
use App\Models\Tahap;
use App\Helpers\NumberingService;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BusinessRuleException;
use Carbon\Carbon;

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
        // 1. Hitung kebutuhan BOM & Validasi ketersediaan tahap cutting
        $kebutuhanBahan = $this->bomService->hitungKebutuhan($order);
        $cuttingId = Tahap::where('kode', 'cutting')->value('id');
        $adaCutting = collect($kebutuhanBahan)->contains('tahap_id', $cuttingId);
        
        if (empty($kebutuhanBahan) || !$adaCutting) {
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
            'tgl_target' => Carbon::parse($order->deadline)->subDays((int)$bufferKirim)->format('Y-m-d'),
            'dibuat_oleh' => $userId,
        ]);

        // 3. Buat wo_items dengan batas toleransi potong
        foreach ($order->items as $item) {
            $qtyBatas = $item->qty + ceil($item->qty * ((float)$toleransiPotong / 100));
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
}