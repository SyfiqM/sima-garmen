<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\WoStatus;
use App\Helpers\Audit;
use App\Models\Order;
use App\Models\Packing;
use App\Models\WorkOrder;

/**
 * Status order turunan dari produksi dan pengiriman (bagian 4.3 dan 4.10 rancangan):
 *  - produksi belum selesai                          -> tidak berubah (tetap produksi)
 *  - produksi selesai, belum ada koli terkirim        -> siap_kirim
 *  - produksi selesai, sebagian koli terkirim         -> dikirim_sebagian
 *  - produksi selesai, tidak ada koli tersedia atau
 *    dalam pengiriman, dan ada koli terkirim          -> terkirim
 * Order dihentikan/dibatalkan/draft dan sebagainya tidak disentuh.
 */
class OrderStatusService
{
    public function hitungUlang(Order $order, ?int $userId = null): OrderStatus
    {
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        $lama = $order->status;

        $dapatDihitung = [OrderStatus::Produksi, OrderStatus::SiapKirim, OrderStatus::DikirimSebagian, OrderStatus::Terkirim];
        if (! in_array($lama, $dapatDihitung, true)) {
            return $lama;
        }

        $wo = WorkOrder::where('order_id', $order->id)
            ->where('status', '!=', WoStatus::Dibatalkan->value)
            ->latest('id')
            ->first();

        $produksiSelesai = $wo && in_array($wo->status, [WoStatus::Selesai, WoStatus::DitutupKurang], true);
        if (! $produksiSelesai) {
            return $lama;
        }

        $adaTerkirim = Packing::where('wo_id', $wo->id)->where('status', 'terkirim')->exists();
        $adaBelumKirim = Packing::where('wo_id', $wo->id)->whereIn('status', ['tersedia', 'dalam_pengiriman'])->exists();

        $baru = match (true) {
            ! $adaTerkirim => OrderStatus::SiapKirim,
            ! $adaBelumKirim => OrderStatus::Terkirim,
            default => OrderStatus::DikirimSebagian,
        };

        if ($baru !== $lama) {
            $order->update(['status' => $baru]);

            if ($userId !== null) {
                Audit::log($userId, 'order.status_turunan', 'Order', $order->id, ['status' => $lama->value], ['status' => $baru->value]);
            }
        }

        return $baru;
    }
}