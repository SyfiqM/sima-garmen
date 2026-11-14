<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Helpers\Audit;
use App\Models\Bahan;
use App\Models\Order;
use App\Models\Tahap;
use App\Models\WoBahan;
use App\Models\WorkOrder;

class ReservationService
{
    /**
     * Alokasi parsial per bahan sebesar stok tersedia
     */
    public function alokasi(WoBahan $woBahan)
    {
        // Pastikan dipanggil di dalam DB::transaction
        $bahan = Bahan::where('id', $woBahan->bahan_id)->lockForUpdate()->first();

        $sisaKebutuhan = $woBahan->qty_rencana - $woBahan->qty_reservasi;

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

    public function catatPengeluaran(WoBahan $woBahan, float $qty): void
    {
        $woBahan = WoBahan::whereKey($woBahan->id)->lockForUpdate()->firstOrFail();
        $bahan = Bahan::whereKey($woBahan->bahan_id)->lockForUpdate()->firstOrFail();
        $sisaReservasi = max(0, round((float) $woBahan->qty_reservasi - (float) $woBahan->qty_keluar, 3));
        $qtyTerkonsumsi = min($qty, $sisaReservasi);

        if ((float) $bahan->stok_dipesan < $qtyTerkonsumsi) {
            throw new BusinessRuleException('Saldo reservasi bahan tidak konsisten.', 'STOK_DI_BAWAH_RESERVASI', 409);
        }

        $bahan->stok_dipesan = round((float) $bahan->stok_dipesan - $qtyTerkonsumsi, 3);
        $bahan->save();

        $woBahan->qty_keluar = round((float) $woBahan->qty_keluar + $qty, 3);
        $woBahan->save();
    }

    public function batalkanPengeluaran(WoBahan $woBahan, float $qty): void
    {
        $woBahan = WoBahan::whereKey($woBahan->id)->lockForUpdate()->firstOrFail();
        $bahan = Bahan::whereKey($woBahan->bahan_id)->lockForUpdate()->firstOrFail();
        $qtyKeluarSebelum = round((float) $woBahan->qty_keluar - $qty, 3);
        $reservasiDipulihkan = min(
            $qty,
            max(0, round((float) $woBahan->qty_reservasi - max(0, $qtyKeluarSebelum), 3)),
        );

        if ((float) $bahan->stok_dipesan + $reservasiDipulihkan > (float) $bahan->stok) {
            throw new BusinessRuleException('Stok tidak cukup untuk memulihkan reservasi pengeluaran.', 'STOK_DI_BAWAH_RESERVASI', 409);
        }

        $bahan->stok_dipesan = round((float) $bahan->stok_dipesan + $reservasiDipulihkan, 3);
        $bahan->save();

        $woBahan->qty_keluar = $qtyKeluarSebelum;
        $woBahan->save();
    }

    public function alokasiUlang()
    {
        $woBahans = WoBahan::whereColumn('qty_reservasi', '<', 'qty_rencana')
            ->whereHas('workOrder', function ($q) {
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

        $this->naikkanOrderYangBahannyaTerpenuhi();
    }

    /**
     * Bagian 4.3: order menunggu_bahan otomatis menjadi siap_produksi bila semua wo_bahan
     * tahap cutting sudah terpenuhi reservasinya (aturan yang sama dengan saat persetujuan).
     */
    protected function naikkanOrderYangBahannyaTerpenuhi(): void
    {
        $cuttingId = Tahap::where('kode', 'cutting')->value('id');
        if (! $cuttingId) {
            return;
        }

        $orders = Order::where('status', 'menunggu_bahan')->lockForUpdate()->get();
        foreach ($orders as $order) {
            $wo = WorkOrder::where('order_id', $order->id)->where('status', 'aktif')->first();
            if (! $wo) {
                continue;
            }

            $masihKurang = WoBahan::where('wo_id', $wo->id)
                ->where('tahap_id', $cuttingId)
                ->whereColumn('qty_reservasi', '<', 'qty_rencana')
                ->exists();
            if ($masihKurang) {
                continue;
            }

            $order->update(['status' => 'siap_produksi']);
            Audit::log(null, 'order.siap_produksi_otomatis', 'Order', $order->id, ['status' => 'menunggu_bahan'], ['status' => 'siap_produksi'], 'Bahan cutting terpenuhi setelah alokasi ulang');
            NotificationService::toRoles(
                ['gudang'],
                'WO siap diproduksi',
                "{$wo->no_wo} ({$order->no_order}): bahan cutting sudah terpenuhi dan siap dikeluarkan.",
                null,
                'sukses',
            );
        }
    }

    /**
     * Lepas sisa reservasi seluruh bahan sebuah WO (dipakai saat WO selesai atau ditutup kurang),
     * lalu alokasikan stok yang bebas ke order lain yang menunggu.
     */
    public function lepasSisaReservasiWo(WorkOrder $wo): void
    {
        foreach (WoBahan::where('wo_id', $wo->id)->lockForUpdate()->get() as $woBahan) {
            $this->lepasReservasi($woBahan);
        }

        $this->alokasiUlang();
    }
}