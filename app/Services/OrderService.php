<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Helpers\Audit;
use App\Helpers\NumberingService;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pelanggan;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderService
{
    protected $workOrderService;

    protected $reservationService;

    protected function statusValue($status): ?string
    {
        if ($status instanceof \BackedEnum) {
            return $status->value;
        }

        return is_string($status) ? $status : null;
    }

    // INJEKSI SERVICE LAIN UNTUK TAHAP 3C
    public function __construct(WorkOrderService $workOrderService, ReservationService $reservationService)
    {
        $this->workOrderService = $workOrderService;
        $this->reservationService = $reservationService;
    }

    // =================================================================
    // KODE LAMA DARI TAHAP 3A
    // =================================================================

    public function store(array $data, int $userId)
    {
        return DB::transaction(function () use ($data, $userId) {
            // Generate Nomor Order (contoh: ORD-2611-0001)
            $data['no_order'] = NumberingService::next('ORD');
            $data['dibuat_oleh'] = $userId;
            $data['status'] = 'draft';

            // Perhitungan Total
            $data['total_qty'] = collect($data['items'])->sum('qty');
            $data['total_nilai'] = collect($data['items'])->sum(function ($item) {
                return $item['qty'] * ($item['harga_satuan'] ?? 0);
            });

            // Fallback alamat kirim ke pelanggan jika kosong
            if (empty($data['alamat_kirim'])) {
                $pelanggan = Pelanggan::find($data['pelanggan_id']);
                $data['alamat_kirim'] = $pelanggan->alamat ?? '-';
            }

            $order = Order::create($data);

            // Simpan Items
            foreach ($data['items'] as $item) {
                $item['order_id'] = $order->id;
                OrderItem::create($item);
            }

            if (class_exists(Audit::class)) {
                Audit::log($userId, 'order.create', 'Order', $order->id, null, $order->toArray(), 'Membuat draft order baru');
            }

            return $order->load('items');
        });
    }

    public function update($orderId, array $data, int $userId)
    {
        return DB::transaction(function () use ($orderId, $data, $userId) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            $this->authorizeOwnerOrManager($order, $userId);

            if (! in_array($this->statusValue($order->status), ['draft', 'ditolak'], true)) {
                throw new BusinessRuleException('Hanya order berstatus draft atau ditolak yang dapat diubah sepenuhnya.', 'STATUS_TIDAK_VALID', 422);
            }

            $sebelum = $order->toArray();

            $data['total_qty'] = collect($data['items'])->sum('qty');
            $data['total_nilai'] = collect($data['items'])->sum(function ($item) {
                return $item['qty'] * ($item['harga_satuan'] ?? 0);
            });

            $order->update($data);

            // Sinkronisasi ulang items (Hapus lama, insert baru)
            $order->items()->delete();
            foreach ($data['items'] as $item) {
                $item['order_id'] = $order->id;
                OrderItem::create($item);
            }

            if (class_exists(Audit::class)) {
                Audit::log($userId, 'order.update', 'Order', $order->id, $sebelum, $order->toArray(), 'Mengubah order');
            }

            return $order->load('items');
        });
    }

    public function destroy($orderId, int $userId)
    {
        return DB::transaction(function () use ($orderId, $userId) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            $this->authorizeOwnerOrManager($order, $userId);
            if ($this->statusValue($order->status) !== 'draft') {
                throw new BusinessRuleException('Hanya order draft yang bisa dihapus permanen. Gunakan fitur batalkan untuk status lainnya.', 'STATUS_TIDAK_VALID', 422);
            }

            $sebelum = $order->toArray();
            $order->delete();

            if (class_exists(Audit::class)) {
                Audit::log($userId, 'order.delete', 'Order', $orderId, $sebelum, null, 'Menghapus draft order');
            }
        });
    }

    // =================================================================
    // KODE BARU TAHAP 3C: MESIN TRANSISI STATUS
    // =================================================================

    public function ubahStatus(int $orderId, string $aksi, array $dataTambahan, int $userId)
    {
        return DB::transaction(function () use ($orderId, $aksi, $dataTambahan, $userId) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            $this->authorizeAction($order, $aksi, $userId);
            $sebelum = $order->toArray();
            $statusBaru = $order->status;
            $pesanAudit = "Aksi: $aksi";

            switch ($aksi) {
                case 'ajukan':
                    if (! in_array($this->statusValue($order->status), ['draft', 'ditolak'], true)) {
                        throw new BusinessRuleException('Hanya draft/ditolak yang bisa diajukan.', 'STATUS_TIDAK_VALID', 422);
                    }
                    if (! $order->items()->exists()) {
                        throw new BusinessRuleException('Order harus memiliki minimal satu item.', 'STATUS_TIDAK_VALID', 422);
                    }
                    if ($order->deadline->lt($order->tgl_order)) {
                        throw new BusinessRuleException('Deadline tidak boleh lebih kecil dari tanggal order.', 'STATUS_TIDAK_VALID', 422);
                    }
                    if (! $order->pelanggan()->where('is_active', true)->exists() || $order->items()->whereHas('produk', fn ($query) => $query->where('is_active', false))->exists()) {
                        throw new BusinessRuleException('Pelanggan dan produk order harus aktif.', 'STATUS_TIDAK_VALID', 422);
                    }
                    $statusBaru = 'diajukan';
                    break;

                case 'tarik':
                    if ($this->statusValue($order->status) !== 'diajukan') {
                        throw new BusinessRuleException('Hanya status diajukan yang bisa ditarik.', 'STATUS_TIDAK_VALID', 422);
                    }
                    $statusBaru = 'draft';
                    break;

                case 'perbaiki':
                    if ($this->statusValue($order->status) !== 'ditolak') {
                        throw new BusinessRuleException('Hanya order ditolak yang dapat diperbaiki.', 'STATUS_TIDAK_VALID', 422);
                    }
                    $statusBaru = 'draft';
                    break;

                case 'tolak':
                    if ($this->statusValue($order->status) !== 'diajukan') {
                        throw new BusinessRuleException('Hanya status diajukan yang bisa ditolak.', 'STATUS_TIDAK_VALID', 422);
                    }
                    $this->validasiAlasan($dataTambahan['alasan'] ?? null);
                    $order->alasan_penolakan = $dataTambahan['alasan'];
                    $statusBaru = 'ditolak';
                    break;

                case 'setujui':
                    if ($this->statusValue($order->status) !== 'diajukan') {
                        throw new BusinessRuleException('Hanya status diajukan yang bisa disetujui.', 'STATUS_TIDAK_VALID', 422);
                    }

                    // Panggil WorkOrderService untuk memproses BOM dan Reservasi
                    $siapProduksi = $this->workOrderService->buatDariOrder($order, $userId);

                    $statusBaru = $siapProduksi ? 'siap_produksi' : 'menunggu_bahan';
                    $order->disetujui_oleh = $userId;
                    $order->disetujui_at = Carbon::now();
                    break;

                case 'revisi':
                    $this->validasiAlasan($dataTambahan['alasan'] ?? null);
                    if (! in_array($this->statusValue($order->status), ['menunggu_bahan', 'siap_produksi'], true)) {
                        throw new BusinessRuleException('Hanya order yang belum berproduksi yang bisa direvisi.', 'STATUS_TIDAK_VALID', 422);
                    }
                    $this->batalkanWorkOrderDanReservasi($order);
                    $order->revisi_ke += 1;
                    $statusBaru = 'draft';
                    break;

                case 'batalkan':
                    if (! in_array($this->statusValue($order->status), ['draft', 'diajukan', 'menunggu_bahan', 'siap_produksi'], true)) {
                        throw new BusinessRuleException('Order sudah diproduksi, gunakan Hentikan.', 'STATUS_TIDAK_VALID', 422);
                    }
                    $this->validasiAlasan($dataTambahan['alasan'] ?? null);
                    if (in_array($this->statusValue($order->status), ['menunggu_bahan', 'siap_produksi'], true)) {
                        $this->batalkanWorkOrderDanReservasi($order);
                    }
                    $order->alasan_pembatalan = $dataTambahan['alasan'];
                    $statusBaru = 'dibatalkan';
                    break;

                case 'hentikan':
                    if ($this->statusValue($order->status) !== 'produksi') {
                        throw new BusinessRuleException('Hanya order berstatus produksi yang bisa dihentikan.', 'STATUS_TIDAK_VALID', 422);
                    }
                    $this->validasiAlasan($dataTambahan['alasan'] ?? null);
                    $this->batalkanWorkOrderDanReservasi($order, 'dihentikan');
                    $statusBaru = 'dihentikan';
                    break;

                default:
                    throw new BusinessRuleException('Aksi tidak dikenal.', 'AKSI_TIDAK_VALID', 400);
            }

            $order->status = $statusBaru;
            $order->save();

            if (class_exists(Audit::class)) {
                Audit::log($userId, "order.{$aksi}", 'Order', $order->id, $sebelum, $order->toArray(), $pesanAudit);
            }

            return $order;
        });
    }

    public function ubahDeadline(int $orderId, string $deadline, string $alasan, int $userId): Order
    {
        $this->authorizeManager($userId);
        $alasan = $this->validasiAlasan($alasan);

        return DB::transaction(function () use ($orderId, $deadline, $alasan, $userId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            if (! in_array($this->statusValue($order->status), ['menunggu_bahan', 'siap_produksi', 'produksi', 'siap_kirim', 'dikirim_sebagian'], true)) {
                throw new BusinessRuleException('Deadline hanya dapat diubah setelah order disetujui.', 'STATUS_TIDAK_VALID', 409);
            }

            $deadlineBaru = Carbon::parse($deadline)->startOfDay();
            if ($deadlineBaru->lt($order->tgl_order->startOfDay())) {
                throw new BusinessRuleException('Deadline tidak boleh lebih kecil dari tanggal order.', 'VALIDASI_GAGAL', 422);
            }

            $sebelum = $order->toArray();
            $order->update(['deadline' => $deadlineBaru->toDateString()]);
            $bufferHari = (int) (DB::table('pengaturan')->where('key', 'buffer_kirim_hari')->value('value') ?? 2);
            $workOrder = WorkOrder::where('order_id', $order->id)->where('status', 'aktif')->lockForUpdate()->first();
            if ($workOrder) {
                $workOrder->update(['tgl_target' => $deadlineBaru->copy()->subDays($bufferHari)->toDateString()]);
            }

            Audit::log($userId, 'order.deadline', 'Order', $order->id, $sebelum, $order->fresh()->toArray(), $alasan);
            return $order->refresh();
        });
    }

    public function ubahPrioritas(int $orderId, string $prioritas, string $alasan, int $userId): Order
    {
        $this->authorizeManager($userId);
        $alasan = $this->validasiAlasan($alasan);

        return DB::transaction(function () use ($orderId, $prioritas, $alasan, $userId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            if (! in_array($this->statusValue($order->status), ['menunggu_bahan', 'siap_produksi', 'produksi', 'siap_kirim', 'dikirim_sebagian'], true)) {
                throw new BusinessRuleException('Prioritas hanya dapat diubah setelah order disetujui.', 'STATUS_TIDAK_VALID', 409);
            }

            $sebelum = $order->toArray();
            $order->update(['prioritas' => $prioritas]);
            if ($this->statusValue($order->status) === 'menunggu_bahan') {
                $this->reservationService->alokasiUlang();
            }
            Audit::log($userId, 'order.prioritas', 'Order', $order->id, $sebelum, $order->fresh()->toArray(), $alasan);
            return $order->refresh();
        });
    }

    private function batalkanWorkOrderDanReservasi(Order $order, string $statusWo = 'dibatalkan'): void
    {
        $wo = WorkOrder::where('order_id', $order->id)->where('status', 'aktif')->first();
        if ($wo) {
            $wo->status = $statusWo;
            $wo->save();

            // Lepas semua reservasi bahan
            foreach ($wo->woBahan as $woBahan) {
                $this->reservationService->lepasReservasi($woBahan);
            }
        }
    }

    private function authorizeOwnerOrManager(Order $order, int $userId): void
    {
        $user = User::findOrFail($userId);

        if ($user->hasRole('admin', 'manajer')) {
            return;
        }

        if (! $user->hasRole('staf_order') || $order->dibuat_oleh !== $user->id) {
            throw new BusinessRuleException('Anda tidak memiliki hak untuk mengubah order ini.', 'TIDAK_ADA_HAK', 403);
        }
    }

    private function authorizeManager(int $userId): void
    {
        if (! User::findOrFail($userId)->hasRole('admin', 'manajer')) {
            throw new BusinessRuleException('Aksi ini hanya untuk Manajer atau Admin.', 'TIDAK_ADA_HAK', 403);
        }
    }

    private function authorizeAction(Order $order, string $aksi, int $userId): void
    {
        $user = User::findOrFail($userId);

        if ($user->hasRole('admin', 'manajer')) {
            return;
        }

        if (! in_array($aksi, ['ajukan', 'tarik', 'perbaiki'], true)) {
            throw new BusinessRuleException('Anda tidak memiliki hak untuk melakukan aksi ini.', 'TIDAK_ADA_HAK', 403);
        }

        $this->authorizeOwnerOrManager($order, $userId);
    }

    private function validasiAlasan(?string $alasan): void
    {
        if ($alasan === null || mb_strlen(trim($alasan)) < 5) {
            throw new BusinessRuleException('Alasan wajib diisi minimal 5 karakter.', 'ALASAN_WAJIB', 422);
        }
    }
}
