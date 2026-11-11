<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PenerimaanBahan;
use App\Models\PenerimaanBahanItem;
use App\Models\Bahan;
use App\Helpers\Audit;
use App\Helpers\NumberingService;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BusinessRuleException;

class PurchaseService
{
    protected $stockService;
    protected $reservationService;

    protected function statusValue($status): ?string
    {
        if ($status instanceof \BackedEnum) {
            return $status->value;
        }

        return is_string($status) ? $status : null;
    }

    public function __construct(StockService $stockService, ReservationService $reservationService)
    {
        $this->stockService = $stockService;
        $this->reservationService = $reservationService;
    }

    // --- LOGIKA PO ---
    public function storePo(array $data, int $userId)
    {
        return DB::transaction(function () use ($data, $userId) {
            $data['no_po'] = NumberingService::next('PO');
            $data['status'] = 'draft';
            $data['dibuat_oleh'] = $userId;

            $po = PurchaseOrder::create($data);

            foreach ($data['items'] as $item) {
                $item['po_id'] = $po->id;
                PurchaseOrderItem::create($item);
            }
            return $po->load('items');
        });
    }

    public function pesanPo(int $poId, int $userId)
    {
        $po = PurchaseOrder::findOrFail($poId);
        if ($this->statusValue($po->status) !== 'draft') {
            throw new BusinessRuleException('Hanya PO draft yang bisa dipesan.', 'STATUS_TIDAK_VALID', 422);
        }
        $po->update(['status' => 'dipesan', 'dipesan_at' => now()]);
        return $po;
    }

    // --- LOGIKA PENERIMAAN BAHAN ---
    public function terimaBahan(array $data, int $userId)
    {
        return DB::transaction(function () use ($data, $userId) {
            $data['no_bm'] = NumberingService::next('BM');
            $data['diterima_oleh'] = $userId;
            $data['status'] = 'aktif';

            $penerimaan = PenerimaanBahan::create($data);
            $poId = $data['po_id'] ?? null;
            $po = $poId ? PurchaseOrder::lockForUpdate()->find($poId) : null;

            foreach ($data['items'] as $item) {
                // 1. Validasi Toleransi PO (jika dari PO)
                if ($po && !empty($item['po_item_id'])) {
                    $poItem = PurchaseOrderItem::find($item['po_item_id']);
                    $sisaPo = $poItem->qty_pesan - $poItem->qty_diterima;
                    $batasTerima = $sisaPo * 1.10; // Toleransi 10%

                    if ($item['qty'] > $batasTerima) {
                        throw new BusinessRuleException("Qty terima melebihi sisa PO + toleransi 10% untuk bahan ID {$item['bahan_id']}.", 'QTY_MELEBIHI_BATAS', 422);
                    }

                    $poItem->qty_diterima += $item['qty'];
                    $poItem->save();
                }

                // 2. Simpan Item Penerimaan
                $item['penerimaan_id'] = $penerimaan->id;
                PenerimaanBahanItem::create($item);

                // 3. Mutasi Stok Masuk
                $this->stockService->mutasi(
                    $item['bahan_id'], 
                    'masuk', 
                    $item['qty'], 
                    'penerimaan', 
                    $penerimaan->id, 
                    "Penerimaan No: " . $data['no_bm'], 
                    $userId
                );

                // 4. Update harga terakhir bahan jika ada
                if (!empty($item['harga_satuan'])) {
                    Bahan::where('id', $item['bahan_id'])->update(['harga_terakhir' => $item['harga_satuan']]);
                }
            }

            // 5. Update Status PO (Diterima Sebagian / Penuh)
            if ($po) {
                $semuaTerpenuhi = true;
                foreach ($po->items as $pi) {
                    if ($pi->qty_diterima < $pi->qty_pesan) {
                        $semuaTerpenuhi = false;
                        break;
                    }
                }
                $po->status = $semuaTerpenuhi ? 'diterima' : 'diterima_sebagian';
                $po->save();
            }

            // 6. Alokasi Ulang Reservasi karena ada stok baru yang masuk
            $this->reservationService->alokasiUlang();

            if (class_exists(Audit::class)) {
                Audit::log($userId, 'penerimaan.create', 'PenerimaanBahan', $penerimaan->id, null, $penerimaan->toArray(), 'Menerima bahan masuk');
            }

            return $penerimaan->load('items');
        });
    }
}