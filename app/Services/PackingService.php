<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Models\WoItem;
use App\Models\Packing;
use App\Models\PackingItem;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class PackingService
{
    protected $woService;

    public function __construct(WorkOrderService $woService)
    {
        $this->woService = $woService;
    }

    public function buatKoli(WorkOrder $wo, array $items, string $tanggal, float $beratKg = null, int $userId)
    {
        return DB::transaction(function () use ($wo, $items, $tanggal, $beratKg, $userId) {
            
            // Hitung urutan koli untuk WO ini
            $urutan = Packing::where('wo_id', $wo->id)->max('urutan_koli') + 1;

            $packing = Packing::create([
                'no_koli' => \App\Services\NumberingService::next('PK'),
                'wo_id' => $wo->id,
                'urutan_koli' => $urutan,
                'tanggal' => $tanggal,
                'berat_kg' => $beratKg,
                'status' => 'tersedia',
                'dibuat_oleh' => $userId
            ]);

            foreach ($items as $item) {
                $woItem = WoItem::where('id', $item['wo_item_id'])->lockForUpdate()->firstOrFail();
                if ((int) $woItem->wo_id !== (int) $wo->id) {
                    throw new BusinessRuleException('Item tidak termasuk dalam Work Order ini.', 'VALIDASI_GAGAL');
                }
                $qty = $item['qty'] ?? 0;

                if ($qty <= 0) continue;

                // Validasi batas packing: min(lolos QC, jumlah order) dikurangi yang sudah di-packing
                $maxBoleh = min($woItem->qty_qc_lolos, $woItem->qty_order) - $woItem->qty_packing;
                
                if ($qty > $maxBoleh) {
                    throw new BusinessRuleException('Qty koli melebihi stok yang siap di-packing.', 'QTY_MELEBIHI_BATAS');
                }

                PackingItem::create([
                    'packing_id' => $packing->id,
                    'wo_item_id' => $woItem->id,
                    'qty' => $qty
                ]);

                // Update counter packing
                $woItem->qty_packing += $qty;
                $woItem->save();
            }

            // Panggil cek penyelesaian dari WorkOrderService
            $this->woService->cekSelesai($wo);

            return $packing;
        });
    }
}