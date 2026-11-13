<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Helpers\Audit;
use App\Helpers\NumberingService;
use App\Models\Bahan;
use App\Models\PenerimaanBahan;
use App\Models\PenerimaanBahanItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

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

    public function buatPoDariKekurangan(int $supplierId, string $tanggal, int $userId): PurchaseOrder
    {
        $kebutuhan = DB::table('wo_bahan as wb')
            ->join('work_orders as wo', 'wo.id', '=', 'wb.wo_id')
            ->join('orders as o', 'o.id', '=', 'wo.order_id')
            ->join('bahan as b', 'b.id', '=', 'wb.bahan_id')
            ->where('wo.status', 'aktif')
            ->where('o.status', 'menunggu_bahan')
            ->where('b.supplier_id', $supplierId)
            ->groupBy('wb.bahan_id')
            ->select('wb.bahan_id', DB::raw('SUM(wb.qty_rencana - wb.qty_reservasi) as shortage'))
            ->get();

        $poTransit = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.po_id')
            ->join('bahan as b', 'b.id', '=', 'poi.bahan_id')
            ->whereIn('po.status', ['dipesan', 'diterima_sebagian'])
            ->where('b.supplier_id', $supplierId)
            ->select('poi.bahan_id', DB::raw('SUM(poi.qty_pesan - poi.qty_diterima) as qty_transit'))
            ->groupBy('poi.bahan_id')
            ->get()
            ->keyBy('bahan_id');

        $items = $kebutuhan->map(function ($row) use ($poTransit) {
            $qtyTransit = (float) ($poTransit[$row->bahan_id]->qty_transit ?? 0);
            $qty = round((float) $row->shortage - $qtyTransit, 3);
            return $qty > 0 ? ['bahan_id' => $row->bahan_id, 'qty_pesan' => $qty, 'harga_satuan' => 0] : null;
        })->filter()->values()->all();

        if ($items === []) {
            throw new BusinessRuleException('Tidak ada kekurangan bahan untuk supplier ini.', 'DATA_KOSONG', 422);
        }

        return $this->storePo([
            'supplier_id' => $supplierId,
            'tgl_po' => $tanggal,
            'items' => $items,
        ], $userId);
    }

    public function updatePo(int $poId, array $data, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($poId, $data, $userId) {
            $po = PurchaseOrder::whereKey($poId)->lockForUpdate()->firstOrFail();
            if ($this->statusValue($po->status) !== 'draft') {
                throw new BusinessRuleException('Hanya PO draft yang dapat diubah.', 'STATUS_TIDAK_VALID', 409);
            }

            $sebelum = $po->toArray();
            $items = $data['items'];
            unset($data['items']);
            $po->update($data);
            $po->items()->delete();
            foreach ($items as $item) {
                $po->items()->create($item);
            }
            Audit::log($userId, 'po.update', 'PurchaseOrder', $po->id, $sebelum, $po->fresh()->toArray());

            return $po->load('items');
        });
    }

    public function destroyPo(int $poId, int $userId): void
    {
        DB::transaction(function () use ($poId, $userId) {
            $po = PurchaseOrder::with('items')->whereKey($poId)->lockForUpdate()->firstOrFail();
            if ($this->statusValue($po->status) !== 'draft') {
                throw new BusinessRuleException('Hanya PO draft yang dapat dihapus.', 'STATUS_TIDAK_VALID', 409);
            }
            $sebelum = $po->toArray();
            $po->items()->delete();
            $po->delete();
            Audit::log($userId, 'po.delete', 'PurchaseOrder', $po->id, $sebelum, null);
        });
    }

    public function pesanPo(int $poId, int $userId)
    {
        return DB::transaction(function () use ($poId, $userId) {
            $po = PurchaseOrder::whereKey($poId)->lockForUpdate()->firstOrFail();
            if ($this->statusValue($po->status) !== 'draft') {
                throw new BusinessRuleException('Hanya PO draft yang bisa dipesan.', 'STATUS_TIDAK_VALID', 422);
            }
            $po->update(['status' => 'dipesan', 'dipesan_at' => now()]);
            Audit::log($userId, 'po.pesan', 'PurchaseOrder', $po->id, ['status' => 'draft'], $po->toArray());

            return $po;
        });
    }

    public function batalkanPo(int $poId, string $alasan, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($poId, $alasan, $userId) {
            $po = PurchaseOrder::with('items')->whereKey($poId)->lockForUpdate()->firstOrFail();
            if (! in_array($this->statusValue($po->status), ['draft', 'dipesan'], true)) {
                throw new BusinessRuleException('PO tidak dapat dibatalkan pada status ini.', 'STATUS_TIDAK_VALID', 409);
            }
            if ($po->items->contains(fn (PurchaseOrderItem $item) => (float) $item->qty_diterima > 0)) {
                throw new BusinessRuleException('PO yang sudah memiliki penerimaan tidak dapat dibatalkan.', 'SUDAH_ADA_PENGELUARAN', 409);
            }
            if (mb_strlen(trim($alasan)) < 5) {
                throw new BusinessRuleException('Alasan pembatalan wajib minimal 5 karakter.', 'ALASAN_WAJIB', 422);
            }

            $sebelum = $po->toArray();
            $po->update(['status' => 'dibatalkan', 'alasan_batal' => $alasan]);
            Audit::log($userId, 'po.batalkan', 'PurchaseOrder', $po->id, $sebelum, $po->toArray(), $alasan);

            return $po;
        });
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

            if ($po && ! in_array($this->statusValue($po->status), ['dipesan', 'diterima_sebagian'], true)) {
                throw new BusinessRuleException('Penerimaan hanya dapat dicatat untuk PO yang sudah dipesan.', 'STATUS_TIDAK_VALID', 409);
            }
            if (! $po && empty($data['alasan_tanpa_po'])) {
                throw new BusinessRuleException('Alasan penerimaan tanpa PO wajib diisi.', 'ALASAN_WAJIB', 422);
            }
            if (! $po && ! empty($data['alasan_tanpa_po'])) {
                $keterangan = trim(($data['catatan'] ?? '').' '.$data['alasan_tanpa_po']);
                $penerimaan->update(['catatan' => $keterangan]);
            }

            foreach ($data['items'] as $item) {
                // 1. Validasi Toleransi PO (jika dari PO)
                if ($po && ! empty($item['po_item_id'])) {
                    $poItem = PurchaseOrderItem::whereKey($item['po_item_id'])
                        ->where('po_id', $po->id)
                        ->where('bahan_id', $item['bahan_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                    $sisaPo = $poItem->qty_pesan - $poItem->qty_diterima;
                    $batasTerima = $sisaPo * 1.10; // Toleransi 10%

                    if ($item['qty'] > $batasTerima) {
                        throw new BusinessRuleException("Qty terima melebihi sisa PO + toleransi 10% untuk bahan ID {$item['bahan_id']}.", 'QTY_MELEBIHI_BATAS', 422);
                    }

                    $poItem->qty_diterima += $item['qty'];
                    $poItem->save();
                } elseif ($po) {
                    throw new BusinessRuleException('Setiap item penerimaan PO harus menunjuk item PO yang sama.', 'VALIDASI_GAGAL', 422);
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
                    'Penerimaan No: '.$data['no_bm'],
                    $userId
                );

                // 4. Update harga terakhir bahan jika ada
                if (! empty($item['harga_satuan'])) {
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

    public function voidPenerimaan(int $id, string $alasan, int $userId): PenerimaanBahan
    {
        return DB::transaction(function () use ($id, $alasan, $userId) {
            $penerimaan = PenerimaanBahan::with('items')->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($this->statusValue($penerimaan->status) !== 'aktif') {
                throw new BusinessRuleException('Penerimaan sudah void.', 'STATUS_TIDAK_VALID', 409);
            }
            if (mb_strlen(trim($alasan)) < 5) {
                throw new BusinessRuleException('Alasan void wajib minimal 5 karakter.', 'ALASAN_WAJIB', 422);
            }

            $sebelum = $penerimaan->toArray();
            foreach ($penerimaan->items as $item) {
                $this->stockService->mutasi(
                    $item->bahan_id,
                    'koreksi',
                    -(float) $item->qty,
                    'mutasi',
                    $penerimaan->id,
                    "Void penerimaan {$penerimaan->no_bm}",
                    $userId,
                );

                if ($item->po_item_id) {
                    $poItem = PurchaseOrderItem::whereKey($item->po_item_id)->lockForUpdate()->firstOrFail();
                    $poItem->qty_diterima = round((float) $poItem->qty_diterima - (float) $item->qty, 3);
                    $poItem->save();
                }
            }

            $penerimaan->update(['status' => 'void', 'void_alasan' => $alasan, 'void_oleh' => $userId, 'void_at' => now()]);
            $po = $penerimaan->po_id ? PurchaseOrder::with('items')->lockForUpdate()->find($penerimaan->po_id) : null;
            if ($po) {
                $adaPenerimaan = $po->items->contains(fn (PurchaseOrderItem $item) => (float) $item->qty_diterima > 0);
                $semuaTerpenuhi = $po->items->every(fn (PurchaseOrderItem $item) => (float) $item->qty_diterima >= (float) $item->qty_pesan);
                $po->update(['status' => $semuaTerpenuhi ? 'diterima' : ($adaPenerimaan ? 'diterima_sebagian' : 'dipesan')]);
            }

            $this->reservationService->alokasiUlang();
            Audit::log($userId, 'penerimaan.void', 'PenerimaanBahan', $penerimaan->id, $sebelum, $penerimaan->toArray(), $alasan);

            return $penerimaan;
        });
    }
}
