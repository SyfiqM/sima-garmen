<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\WorkOrder;
use App\Helpers\Audit;
use App\Helpers\NumberingService;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BusinessRuleException;
use Carbon\Carbon;

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
            $data['total_nilai'] = collect($data['items'])->sum(function($item) {
                return $item['qty'] * ($item['harga_satuan'] ?? 0);
            });

            // Fallback alamat kirim ke pelanggan jika kosong
            if (empty($data['alamat_kirim'])) {
                $pelanggan = \App\Models\Pelanggan::find($data['pelanggan_id']);
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
            $order = Order::findOrFail($orderId);
            
            if (!in_array($this->statusValue($order->status), ['draft', 'ditolak'], true)) {
                throw new BusinessRuleException('Hanya order berstatus draft atau ditolak yang dapat diubah sepenuhnya.', 'STATUS_TIDAK_VALID', 422);
            }

            $sebelum = $order->toArray();

            $data['total_qty'] = collect($data['items'])->sum('qty');
            $data['total_nilai'] = collect($data['items'])->sum(function($item) {
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
        $order = Order::findOrFail($orderId);
        if ($this->statusValue($order->status) !== 'draft') {
            throw new BusinessRuleException('Hanya order draft yang bisa dihapus permanen. Gunakan fitur batalkan untuk status lainnya.', 'STATUS_TIDAK_VALID', 422);
        }

        $sebelum = $order->toArray();
        $order->delete(); // Cascading delete ke items

        if (class_exists(Audit::class)) {
            Audit::log($userId, 'order.delete', 'Order', $orderId, $sebelum, null, 'Menghapus draft order');
        }
    }

    // =================================================================
    // KODE BARU TAHAP 3C: MESIN TRANSISI STATUS
    // =================================================================

    public function ubahStatus(int $orderId, string $aksi, array $dataTambahan, int $userId)
    {
        return DB::transaction(function () use ($orderId, $aksi, $dataTambahan, $userId) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            $sebelum = $order->toArray();
            $statusBaru = $order->status;
            $pesanAudit = "Aksi: $aksi";

            switch ($aksi) {
                case 'ajukan':
                    if (!in_array($this->statusValue($order->status), ['draft', 'ditolak'], true)) throw new BusinessRuleException('Hanya draft/ditolak yang bisa diajukan.', 'STATUS_TIDAK_VALID', 422);
                    $statusBaru = 'diajukan';
                    break;

                case 'tarik':
                    if ($this->statusValue($order->status) !== 'diajukan') throw new BusinessRuleException('Hanya status diajukan yang bisa ditarik.', 'STATUS_TIDAK_VALID', 422);
                    $statusBaru = 'draft';
                    break;

                case 'tolak':
                    if ($this->statusValue($order->status) !== 'diajukan') throw new BusinessRuleException('Hanya status diajukan yang bisa ditolak.', 'STATUS_TIDAK_VALID', 422);
                    if (empty($dataTambahan['alasan'])) throw new BusinessRuleException('Alasan penolakan wajib diisi.', 'ALASAN_WAJIB', 422);
                    $order->alasan_penolakan = $dataTambahan['alasan'];
                    $statusBaru = 'ditolak';
                    break;

                case 'setujui':
                    if ($this->statusValue($order->status) !== 'diajukan') throw new BusinessRuleException('Hanya status diajukan yang bisa disetujui.', 'STATUS_TIDAK_VALID', 422);

                    // Panggil WorkOrderService untuk memproses BOM dan Reservasi
                    $siapProduksi = $this->workOrderService->buatDariOrder($order, $userId);

                    $statusBaru = $siapProduksi ? 'siap_produksi' : 'menunggu_bahan';
                    $order->disetujui_oleh = $userId;
                    $order->disetujui_at = Carbon::now();
                    break;

                case 'revisi':
                    if (!in_array($this->statusValue($order->status), ['menunggu_bahan', 'siap_produksi'], true)) {
                        throw new BusinessRuleException('Hanya order yang belum berproduksi yang bisa direvisi.', 'STATUS_TIDAK_VALID', 422);
                    }
                    $this->batalkanWorkOrderDanReservasi($order);
                    $order->revisi_ke += 1;
                    $statusBaru = 'draft';
                    break;

                case 'batalkan':
                    if (!in_array($this->statusValue($order->status), ['draft', 'diajukan', 'menunggu_bahan', 'siap_produksi'], true)) {
                        throw new BusinessRuleException('Order sudah diproduksi, gunakan Hentikan.', 'STATUS_TIDAK_VALID', 422);
                    }
                    if (empty($dataTambahan['alasan'])) throw new BusinessRuleException('Alasan batal wajib diisi.', 'ALASAN_WAJIB', 422);
                    if (in_array($this->statusValue($order->status), ['menunggu_bahan', 'siap_produksi'], true)) {
                        $this->batalkanWorkOrderDanReservasi($order);
                    }
                    $order->alasan_pembatalan = $dataTambahan['alasan'];
                    $statusBaru = 'dibatalkan';
                    break;

                case 'hentikan':
                    if ($this->statusValue($order->status) !== 'produksi') throw new BusinessRuleException('Hanya order berstatus produksi yang bisa dihentikan.', 'STATUS_TIDAK_VALID', 422);
                    if (empty($dataTambahan['alasan'])) throw new BusinessRuleException('Alasan hentikan wajib diisi.', 'ALASAN_WAJIB', 422);
                    $this->batalkanWorkOrderDanReservasi($order); // Lepas sisa reservasi
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

    private function batalkanWorkOrderDanReservasi(Order $order)
    {
        $wo = WorkOrder::where('order_id', $order->id)->where('status', 'aktif')->first();
        if ($wo) {
            $wo->status = 'dibatalkan';
            $wo->save();

            // Lepas semua reservasi bahan
            foreach ($wo->bahan as $woBahan) {
                $this->reservationService->lepasReservasi($woBahan);
            }
        }
    }
}