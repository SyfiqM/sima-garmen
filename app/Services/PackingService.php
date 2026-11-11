<?php

namespace App\Services;

use App\Enums\PackingStatus;
use App\Enums\WoStatus;
use App\Exceptions\BusinessRuleException;
use App\Helpers\Audit;
use App\Models\Packing;
use App\Models\PackingItem;
use App\Models\Tahap;
use App\Models\User;
use App\Models\WoItem;
use App\Models\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PackingService
{
    protected $woService;

    public function __construct(WorkOrderService $woService)
    {
        $this->woService = $woService;
    }

    /**
     * Membuat koli. Qty per item <= packing_tersedia = min(qty_qc_lolos, qty_order) - qty_packing.
     * Setelah koli tersimpan, penyelesaian produksi dicek otomatis (cekSelesai).
     */
    public function buatKoli(WorkOrder $wo, array $items, string $tanggal, ?float $beratKg, int $userId, ?string $catatan = null): Packing
    {
        $this->authorizePacking(User::findOrFail($userId));
        if (count($items) < 1) {
            throw new BusinessRuleException('Minimal satu item koli.', 'VALIDASI_GAGAL');
        }

        $packing = DB::transaction(function () use ($wo, $items, $tanggal, $beratKg, $userId, $catatan) {
            $wo = WorkOrder::whereKey($wo->id)->lockForUpdate()->firstOrFail();
            if ($wo->status !== WoStatus::Aktif) {
                throw new BusinessRuleException('Koli hanya dapat dibuat untuk WO yang aktif.', 'STATUS_TIDAK_VALID');
            }

            $urutan = (int) Packing::where('wo_id', $wo->id)->max('urutan_koli') + 1;

            $packing = Packing::create([
                'no_koli' => NumberingService::next('PK'),
                'wo_id' => $wo->id,
                'urutan_koli' => $urutan,
                'tanggal' => $tanggal,
                'berat_kg' => $beratKg,
                'catatan' => $catatan,
                'status' => 'tersedia',
                'dibuat_oleh' => $userId,
            ]);

            $total = 0;
            foreach ($items as $item) {
                $qty = (int) ($item['qty'] ?? 0);
                if ($qty < 1) {
                    throw new BusinessRuleException('Qty item koli minimal 1.', 'VALIDASI_GAGAL');
                }

                $woItem = WoItem::where('id', $item['wo_item_id'] ?? 0)->lockForUpdate()->firstOrFail();
                if ((int) $woItem->wo_id !== (int) $wo->id) {
                    throw new BusinessRuleException('Item tidak termasuk dalam Work Order ini.', 'VALIDASI_GAGAL');
                }

                $maxBoleh = min($woItem->qty_qc_lolos, $woItem->qty_order) - $woItem->qty_packing;
                if ($qty > $maxBoleh) {
                    throw new BusinessRuleException('Qty koli melebihi barang yang siap di-packing (maks: '.max(0, $maxBoleh).').', 'QTY_MELEBIHI_BATAS');
                }

                PackingItem::create([
                    'packing_id' => $packing->id,
                    'wo_item_id' => $woItem->id,
                    'qty' => $qty,
                ]);

                $woItem->qty_packing += $qty;
                $woItem->save();
                $total += $qty;
            }

            Audit::log($userId, 'packing.buat', 'Packing', $packing->id, null, ['no_koli' => $packing->no_koli, 'wo_id' => $wo->id, 'total_qty' => $total], $catatan);

            $this->woService->cekSelesai($wo);

            return $packing;
        });

        return $packing->fresh(['packingItems.woItem.orderItem.produk', 'packingItems.woItem.orderItem.warna', 'packingItems.woItem.orderItem.ukuran']);
    }

    /**
     * Void koli: hanya bila status tersedia (belum masuk pengiriman) dan WO masih aktif.
     */
    public function voidKoli(int $id, string $alasan, int $userId): Packing
    {
        $this->authorizePacking(User::findOrFail($userId));
        if (mb_strlen(trim($alasan)) < 5) {
            throw new BusinessRuleException('Alasan void wajib minimal 5 karakter.', 'ALASAN_WAJIB');
        }

        return DB::transaction(function () use ($id, $alasan, $userId) {
            $packing = Packing::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($packing->status !== PackingStatus::Tersedia) {
                throw new BusinessRuleException('Hanya koli berstatus tersedia yang dapat di-void.', 'KOLI_TIDAK_TERSEDIA');
            }

            $wo = WorkOrder::whereKey($packing->wo_id)->lockForUpdate()->firstOrFail();
            if ($wo->status !== WoStatus::Aktif) {
                throw new BusinessRuleException('Koli tidak dapat di-void karena WO sudah tidak aktif.', 'STATUS_TIDAK_VALID');
            }

            foreach ($packing->packingItems as $pi) {
                $woItem = WoItem::whereKey($pi->wo_item_id)->lockForUpdate()->firstOrFail();
                $baru = $woItem->qty_packing - $pi->qty;
                if ($baru < $woItem->qty_terkirim) {
                    throw new BusinessRuleException('Void ditolak karena qty packing akan lebih kecil dari qty terkirim.', 'VOID_DITOLAK_HILIR_NEGATIF');
                }
                $woItem->qty_packing = $baru;
                $woItem->save();
            }

            $packing->update([
                'status' => 'void',
                'void_alasan' => $alasan,
                'void_oleh' => $userId,
                'void_at' => now(),
            ]);

            Audit::log($userId, 'packing.void', 'Packing', $packing->id, ['status' => 'tersedia'], ['status' => 'void'], $alasan);

            return $packing->fresh();
        });
    }

    public function daftar(int $userId, array $filter = [], int $perPage = 25): LengthAwarePaginator
    {
        $this->authorizeLihat(User::findOrFail($userId));

        return Packing::with(['wo.order.pelanggan', 'packingItems.woItem.orderItem.produk', 'packingItems.woItem.orderItem.warna', 'packingItems.woItem.orderItem.ukuran'])
            ->when($filter['wo_id'] ?? null, fn ($q, $v) => $q->where('wo_id', $v))
            ->when($filter['order_id'] ?? null, fn ($q, $v) => $q->whereHas('wo', fn ($w) => $w->where('order_id', $v)))
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest('id')
            ->paginate($perPage);
    }

    public function detail(int $id, int $userId): Packing
    {
        $this->authorizeLihat(User::findOrFail($userId));

        return Packing::with(['wo.order.pelanggan', 'packingItems.woItem.orderItem.produk', 'packingItems.woItem.orderItem.warna', 'packingItems.woItem.orderItem.ukuran', 'dibuatOleh'])
            ->findOrFail($id);
    }

    /** Packing: Admin dan Supervisor Finishing (bagian 3.2 rancangan). */
    private function authorizePacking(User $user): void
    {
        if ($user->hasRole('admin')) {
            return;
        }

        $finishingId = Tahap::where('kode', 'finishing')->value('id');
        if (! $user->hasRole('supervisor') || ! $finishingId || (int) $user->tahap_id !== (int) $finishingId) {
            throw new BusinessRuleException('Packing hanya untuk Admin atau Supervisor Finishing.', 'TIDAK_ADA_HAK', 403);
        }
    }

    private function authorizeLihat(User $user): void
    {
        if (! $user->hasRole('admin', 'manajer', 'gudang', 'supervisor', 'staf_order', 'pimpinan')) {
            throw new BusinessRuleException('Anda tidak berhak melihat data koli.', 'TIDAK_ADA_HAK', 403);
        }
    }
}