<?php

namespace App\Services;

use App\Models\Bahan;
use App\Models\WoBahan;

class ReservationService
{
    /**
     * Alokasi parsial per bahan sebesar stok tersedia
     */
    public function alokasi(WoBahan $woBahan)
    {
        // Pastikan dipanggil di dalam DB::transaction
        $bahan = Bahan::where('id', $woBahan->bahan_id)->lockForUpdate()->first();
        
        $sisaKebutuhan = $woBahan->qty_rencana - $woBahan->qty_reservasi - $woBahan->qty_keluar;

        if ($sisaKebutuhan > 0) {
            $stokTersedia = $bahan->stok - $bahan->stok_dipesan;
            $alokasi = min($sisaKebutuhan, max(0, $stokTersedia));

            if ($alokasi > 0) {
                $woBahan->qty_reservasi += $alokasi;
                $woBahan->save();

                $bahan->stok_dipesan += $alokasi;
                $bahan->save();
            }
        }
    }
    
    /**
     * Lepas reservasi saat order dibatalkan/revisi
     */
    public function lepasReservasi(WoBahan $woBahan)
    {
        $bahan = Bahan::where('id', $woBahan->bahan_id)->lockForUpdate()->first();
        $qtyDilepas = max(0, $woBahan->qty_reservasi - $woBahan->qty_keluar);
        
        if ($qtyDilepas > 0) {
            $woBahan->qty_reservasi -= $qtyDilepas;
            $woBahan->save();
            
            $bahan->stok_dipesan -= $qtyDilepas;
            $bahan->save();
        }
    }

    public function alokasiUlang()
    {
        $woBahans = \App\Models\WoBahan::whereColumn('qty_reservasi', '<', 'qty_rencana')
            ->whereHas('workOrder', function($q) {
                $q->where('status', 'aktif');
            })
            ->join('work_orders', 'wo_bahan.wo_id', '=', 'work_orders.id')
            ->join('orders', 'work_orders.order_id', '=', 'orders.id')
            ->orderByRaw("FIELD(orders.prioritas, 'urgent', 'tinggi', 'normal')")
            ->orderBy('orders.deadline', 'asc')
            ->orderBy('orders.disetujui_at', 'asc')
            ->select('wo_bahan.*')
            ->get();

        foreach ($woBahans as $wb) {
            $this->alokasi($wb);
        }
    }
}