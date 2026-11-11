<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Models\WoItem;
use App\Models\WoTahap;
use App\Models\ProduksiLog;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class ProductionService
{
    public function catatProduksi(WorkOrder $wo, int $tahapId, array $items, string $tanggal, int $userId)
    {
        return DB::transaction(function () use ($wo, $tahapId, $items, $tanggal, $userId) {
            $wo = WorkOrder::whereKey($wo->id)->lockForUpdate()->firstOrFail();
            $order = $wo->order()->lockForUpdate()->firstOrFail();

            if ($wo->status->value !== 'aktif' || !in_array($order->status->value, ['siap_produksi', 'produksi'], true)) {
                throw new BusinessRuleException('WO tidak dalam status siap diproduksi.', 'STATUS_TIDAK_VALID');
            }

            // 2. Cek status Tahap
            $woTahap = WoTahap::where('wo_id', $wo->id)->where('tahap_id', $tahapId)->first();
            if ($woTahap && $woTahap->status === 'selesai') {
                throw new BusinessRuleException('Tahap ini sudah ditutup.', 'TAHAP_SUDAH_DITUTUP');
            }

            // (Opsional) Cek kelengkapan bahan tahap ini di sini

            $logIds = [];
            foreach ($items as $item) {
                $woItem = WoItem::where('id', $item['wo_item_id'])->lockForUpdate()->first();
                
                $qtyHasil = $item['qty_hasil'] ?? 0;
                $qtyReject = $item['qty_reject'] ?? 0;

                if ($qtyHasil + $qtyReject <= 0) {
                    continue;
                }

                if ($qtyReject > 0 && empty($item['jenis_cacat_id'])) {
                    throw new BusinessRuleException('Jenis cacat wajib diisi jika ada reject.', 'VALIDASI_GAGAL');
                }

                // 3. Validasi batas sesuai rumus ketersediaan
                $batas = 0;
                if ($tahapId == 1) { // Cutting
                    $batas = $woItem->qty_batas_potong - ($woItem->qty_cutting + $woItem->qty_cutting_reject);
                } elseif ($tahapId == 2) { // Sewing
                    $batas = $woItem->qty_cutting - ($woItem->qty_sewing + $woItem->qty_sewing_reject);
                } elseif ($tahapId == 3) { // Finishing
                    $batas = $woItem->qty_sewing - ($woItem->qty_finishing + $woItem->qty_finishing_reject);
                }

                if (($qtyHasil + $qtyReject) > $batas) {
                    throw new BusinessRuleException('Input melebihi batas ketersediaan (maks: ' . $batas . ').', 'QTY_MELEBIHI_BATAS');
                }

                // 4. Catat ke log
                $log = ProduksiLog::create([
                    'wo_item_id' => $woItem->id,
                    'tahap_id' => $tahapId,
                    'tanggal' => $tanggal,
                    'qty_hasil' => $qtyHasil,
                    'qty_reject' => $qtyReject,
                    'jenis_cacat_id' => $item['jenis_cacat_id'] ?? null,
                    'catatan' => $item['catatan'] ?? null,
                    'dibuat_oleh' => $userId
                ]);
                $logIds[] = $log->id;

                // 5. Update counter di wo_items
                if ($tahapId == 1) {
                    $woItem->qty_cutting += $qtyHasil;
                    $woItem->qty_cutting_reject += $qtyReject;
                } elseif ($tahapId == 2) {
                    $woItem->qty_sewing += $qtyHasil;
                    $woItem->qty_sewing_reject += $qtyReject;
                } elseif ($tahapId == 3) {
                    $woItem->qty_finishing += $qtyHasil;
                    $woItem->qty_finishing_reject += $qtyReject;
                }
                $woItem->save();
            }

            if ($order->status->value === 'siap_produksi') {
                $wo->update(['tgl_mulai' => $tanggal]);
                $order->update(['status' => 'produksi']);
            }
            
            if ($woTahap && $woTahap->status === 'menunggu') {
                $woTahap->update(['status' => 'berjalan', 'mulai_at' => now()]);
            }

            return $logIds;
        });
    }
}