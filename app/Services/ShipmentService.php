<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PengirimanStatus;
use App\Exceptions\BusinessRuleException;
use App\Helpers\Audit;
use App\Models\Order;
use App\Models\Packing;
use App\Models\Pengiriman;
use App\Models\PengirimanKoli;
use App\Models\User;
use App\Models\WoItem;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pengiriman dan surat jalan (bagian 4.10 rancangan).
 *
 * Siklus koli: tersedia -> dalam_pengiriman (SJ dibuat) -> terkirim (SJ dikirim)
 * Batal sebelum dikirim: dalam_pengiriman -> tersedia.
 * Batal setelah dikirim: terkirim -> tersedia dan qty_terkirim dikurangi.
 */
class ShipmentService
{
    private const RELASI_DETAIL = [
        'order.pelanggan',
        'dibuatOleh',
        'pengirimanKoli.packing.packingItems.woItem.orderItem.produk',
        'pengirimanKoli.packing.packingItems.woItem.orderItem.warna',
        'pengirimanKoli.packing.packingItems.woItem.orderItem.ukuran',
    ];

    public function __construct(protected OrderStatusService $orderStatus) {}

    /**
     * Koli berstatus tersedia untuk sebuah order (pilihan saat membuat SJ).
     */
    public function koliTersedia(int $orderId, int $userId): Collection
    {
        $this->authorizeLihat(User::findOrFail($userId));
        $order = Order::findOrFail($orderId);

        return Packing::with(['packingItems.woItem.orderItem.produk', 'packingItems.woItem.orderItem.warna', 'packingItems.woItem.orderItem.ukuran'])
            ->where('status', 'tersedia')
            ->whereHas('wo', fn ($q) => $q->where('order_id', $order->id))
            ->orderBy('id')
            ->get();
    }

    /**
     * Membuat SJ berstatus disiapkan dari koli tersedia; koli menjadi dalam_pengiriman.
     *
     * @param  array{order_id:int, koli_ids:array<int,int>, tgl_kirim?:?string, ekspedisi?:?string, no_resi?:?string, no_kendaraan?:?string, nama_sopir?:?string, nama_penerima?:?string, alamat_tujuan?:?string, catatan?:?string}  $data
     */
    public function buat(array $data, int $userId): Pengiriman
    {
        $this->authorizeKelola(User::findOrFail($userId));

        $koliIds = array_values(array_unique(array_map('intval', $data['koli_ids'] ?? [])));
        if (count($koliIds) < 1) {
            throw new BusinessRuleException('Pilih minimal satu koli.', 'VALIDASI_GAGAL');
        }

        $pengiriman = DB::transaction(function () use ($data, $koliIds, $userId) {
            $order = Order::whereKey($data['order_id'])->lockForUpdate()->firstOrFail();

            $statusBoleh = [OrderStatus::Produksi, OrderStatus::SiapKirim, OrderStatus::DikirimSebagian, OrderStatus::Dihentikan];
            if (! in_array($order->status, $statusBoleh, true)) {
                throw new BusinessRuleException('Pengiriman tidak dapat dibuat untuk order berstatus '.$order->status->label().'.', 'STATUS_TIDAK_VALID');
            }

            $koli = Packing::whereIn('id', $koliIds)->orderBy('id')->lockForUpdate()->with('wo')->get();
            if ($koli->count() !== count($koliIds)) {
                throw new BusinessRuleException('Sebagian koli tidak ditemukan.', 'KOLI_TIDAK_TERSEDIA');
            }

            foreach ($koli as $k) {
                if ((int) $k->wo->order_id !== (int) $order->id) {
                    throw new BusinessRuleException("Koli {$k->no_koli} bukan milik order ini.", 'VALIDASI_GAGAL');
                }
                if ($k->status->value !== 'tersedia') {
                    throw new BusinessRuleException("Koli {$k->no_koli} tidak tersedia (sudah masuk pengiriman atau di-void).", 'KOLI_TIDAK_TERSEDIA');
                }
            }

            $pengiriman = Pengiriman::create([
                'no_sj' => NumberingService::next('SJ'),
                'order_id' => $order->id,
                'tgl_kirim' => $data['tgl_kirim'] ?? null,
                'ekspedisi' => $data['ekspedisi'] ?? null,
                'no_resi' => $data['no_resi'] ?? null,
                'no_kendaraan' => $data['no_kendaraan'] ?? null,
                'nama_sopir' => $data['nama_sopir'] ?? null,
                'nama_penerima' => $data['nama_penerima'] ?? null,
                'alamat_tujuan' => $data['alamat_tujuan'] ?? $order->alamat_kirim,
                'status' => 'disiapkan',
                'catatan' => $data['catatan'] ?? null,
                'dibuat_oleh' => $userId,
            ]);

            foreach ($koli as $k) {
                PengirimanKoli::create(['pengiriman_id' => $pengiriman->id, 'packing_id' => $k->id]);
                $k->update(['status' => 'dalam_pengiriman']);
            }

            Audit::log($userId, 'pengiriman.buat', 'Pengiriman', $pengiriman->id, null, ['no_sj' => $pengiriman->no_sj, 'order_id' => $order->id, 'jumlah_koli' => $koli->count()]);

            return $pengiriman;
        });

        return $this->detail($pengiriman->id, $userId);
    }

    /**
     * Kirim: SJ dikirim, koli terkirim, qty_terkirim bertambah, status order dihitung ulang.
     */
    public function kirim(int $id, ?string $tglKirim, int $userId): Pengiriman
    {
        $this->authorizeKelola(User::findOrFail($userId));

        $order = null;

        DB::transaction(function () use ($id, $tglKirim, $userId, &$order) {
            $pengiriman = Pengiriman::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($pengiriman->status !== PengirimanStatus::Disiapkan) {
                throw new BusinessRuleException('Hanya pengiriman berstatus disiapkan yang dapat dikirim.', 'STATUS_TIDAK_VALID', 409);
            }

            $order = Order::whereKey($pengiriman->order_id)->lockForUpdate()->firstOrFail();

            $koli = $this->kunciKoli($pengiriman, 'dalam_pengiriman');
            $perItem = $this->qtyPerWoItem($koli);

            foreach ($this->kunciWoItem($perItem) as $woItem) {
                $woItem->qty_terkirim += $perItem[$woItem->id];
                if ($woItem->qty_terkirim > min($woItem->qty_packing, $woItem->qty_order)) {
                    throw new BusinessRuleException('Qty terkirim akan melebihi qty packing atau qty order.', 'QTY_MELEBIHI_BATAS');
                }
                $woItem->save();
            }

            foreach ($koli as $k) {
                $k->update(['status' => 'terkirim']);
            }

            $tanggal = $tglKirim ?? $pengiriman->tgl_kirim?->format('Y-m-d') ?? now()->format('Y-m-d');
            $pengiriman->update(['status' => 'dikirim', 'tgl_kirim' => $tanggal]);

            $this->orderStatus->hitungUlang($order, $userId);

            Audit::log($userId, 'pengiriman.kirim', 'Pengiriman', $pengiriman->id, ['status' => 'disiapkan'], ['status' => 'dikirim', 'tgl_kirim' => $tanggal]);
        });

        $pengiriman = $this->detail($id, $userId);
        NotificationService::toRoles(['staf_order'], 'Pengiriman dikirim', "Surat jalan {$pengiriman->no_sj} untuk order {$pengiriman->order->no_order} telah dikirim.", null, 'info', $userId);

        return $pengiriman;
    }

    /**
     * Konfirmasi diterima (opsional dengan bukti terima di disk privat).
     */
    public function diterima(int $id, ?string $tglDiterima, ?UploadedFile $bukti, ?string $catatan, int $userId): Pengiriman
    {
        $user = User::findOrFail($userId);
        if (! $user->hasRole('admin', 'staf_order', 'gudang')) {
            throw new BusinessRuleException('Anda tidak berhak mengonfirmasi penerimaan.', 'TIDAK_ADA_HAK', 403);
        }

        $path = null;

        try {
            DB::transaction(function () use ($id, $tglDiterima, $bukti, $catatan, $userId, &$path) {
                $pengiriman = Pengiriman::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($pengiriman->status !== PengirimanStatus::Dikirim) {
                    throw new BusinessRuleException('Hanya pengiriman berstatus dikirim yang dapat dikonfirmasi diterima.', 'STATUS_TIDAK_VALID', 409);
                }

                $tanggal = $tglDiterima ?? now()->format('Y-m-d');
                if ($pengiriman->tgl_kirim && Carbon::parse($tanggal)->lt($pengiriman->tgl_kirim)) {
                    throw new BusinessRuleException('Tanggal diterima tidak boleh sebelum tanggal kirim.', 'VALIDASI_GAGAL');
                }

                if ($bukti) {
                    $path = $bukti->storeAs(
                        "pengiriman/{$pengiriman->id}",
                        Str::uuid()->toString().'.'.$bukti->getClientOriginalExtension(),
                        'local'
                    );
                }

                $dataUpdate = ['status' => 'diterima', 'tgl_diterima' => $tanggal];
                if ($path) {
                    $dataUpdate['bukti_terima_path'] = $path;
                }
                if ($catatan !== null && $catatan !== '') {
                    $dataUpdate['catatan'] = trim(($pengiriman->catatan ? $pengiriman->catatan."\n" : '').'Diterima: '.$catatan);
                }
                $pengiriman->update($dataUpdate);

                Audit::log($userId, 'pengiriman.diterima', 'Pengiriman', $pengiriman->id, ['status' => 'dikirim'], ['status' => 'diterima', 'tgl_diterima' => $tanggal, 'bukti' => $path !== null]);
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        $pengiriman = $this->detail($id, $userId);
        NotificationService::toRoles(['staf_order'], 'Pengiriman diterima', "Surat jalan {$pengiriman->no_sj} telah dikonfirmasi diterima pelanggan.", null, 'info', $userId);

        return $pengiriman;
    }

    /**
     * Batal. Sebelum dikirim: Admin/Gudang. Setelah dikirim: Admin/Manajer, koli kembali
     * tersedia dan qty_terkirim dikurangi. SJ yang sudah diterima tidak dapat dibatalkan.
     */
    public function batal(int $id, string $alasan, int $userId): Pengiriman
    {
        $user = User::findOrFail($userId);
        if (mb_strlen(trim($alasan)) < 5) {
            throw new BusinessRuleException('Alasan batal wajib minimal 5 karakter.', 'ALASAN_WAJIB');
        }

        DB::transaction(function () use ($id, $alasan, $userId, $user) {
            $pengiriman = Pengiriman::whereKey($id)->lockForUpdate()->firstOrFail();
            $statusLama = $pengiriman->status;

            if ($statusLama === PengirimanStatus::Disiapkan) {
                if (! $user->hasRole('admin', 'gudang')) {
                    throw new BusinessRuleException('Hanya Admin atau Gudang yang dapat membatalkan pengiriman.', 'TIDAK_ADA_HAK', 403);
                }
            } elseif ($statusLama === PengirimanStatus::Dikirim) {
                if (! $user->hasRole('admin', 'manajer')) {
                    throw new BusinessRuleException('Pembatalan setelah dikirim hanya oleh Manajer atau Admin.', 'TIDAK_ADA_HAK', 403);
                }
            } else {
                throw new BusinessRuleException('Pengiriman berstatus '.$statusLama->label().' tidak dapat dibatalkan.', 'STATUS_TIDAK_VALID', 409);
            }

            $order = Order::whereKey($pengiriman->order_id)->lockForUpdate()->firstOrFail();

            if ($statusLama === PengirimanStatus::Dikirim) {
                $koli = $this->kunciKoli($pengiriman, 'terkirim');
                $perItem = $this->qtyPerWoItem($koli);

                foreach ($this->kunciWoItem($perItem) as $woItem) {
                    $baru = $woItem->qty_terkirim - $perItem[$woItem->id];
                    if ($baru < 0) {
                        throw new BusinessRuleException('Pembatalan membuat qty terkirim negatif.', 'VOID_DITOLAK_HILIR_NEGATIF');
                    }
                    $woItem->qty_terkirim = $baru;
                    $woItem->save();
                }
            } else {
                $koli = $this->kunciKoli($pengiriman, 'dalam_pengiriman');
            }

            foreach ($koli as $k) {
                $k->update(['status' => 'tersedia']);
            }

            $pengiriman->update(['status' => 'dibatalkan', 'alasan_batal' => $alasan]);

            $this->orderStatus->hitungUlang($order, $userId);

            Audit::log($userId, 'pengiriman.batal', 'Pengiriman', $pengiriman->id, ['status' => $statusLama->value], ['status' => 'dibatalkan'], $alasan);
        });

        return $this->detail($id, $userId);
    }

    public function daftar(int $userId, array $filter = [], int $perPage = 25): LengthAwarePaginator
    {
        $this->authorizeLihat(User::findOrFail($userId));

        return Pengiriman::with(['order.pelanggan'])
            ->withCount('pengirimanKoli')
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['order_id'] ?? null, fn ($q, $v) => $q->where('order_id', $v))
            ->when($filter['tgl_dari'] ?? null, fn ($q, $v) => $q->whereRaw('COALESCE(tgl_kirim, DATE(created_at)) >= ?', [$v]))
            ->when($filter['tgl_sampai'] ?? null, fn ($q, $v) => $q->whereRaw('COALESCE(tgl_kirim, DATE(created_at)) <= ?', [$v]))
            ->latest('id')
            ->paginate($perPage);
    }

    public function detail(int $id, int $userId): Pengiriman
    {
        $this->authorizeLihat(User::findOrFail($userId));

        $pengiriman = Pengiriman::with(self::RELASI_DETAIL)->findOrFail($id);
        $pengiriman->setAttribute('ringkasan_item', $this->ringkasanItem($pengiriman));

        return $pengiriman;
    }

    /**
     * Isi SJ dikelompokkan per produk, warna, dan ukuran (tanpa harga).
     *
     * @return array<int, array{produk:string, warna:string, ukuran:string, qty:int}>
     */
    public function ringkasanItem(Pengiriman $pengiriman): array
    {
        $baris = [];
        foreach ($pengiriman->pengirimanKoli as $pk) {
            foreach ($pk->packing->packingItems as $pi) {
                $oi = $pi->woItem->orderItem;
                $kunci = $oi->produk_id.'-'.$oi->warna_id.'-'.$oi->ukuran_id;
                $baris[$kunci] ??= ['produk' => $oi->produk->nama, 'warna' => $oi->warna->nama, 'ukuran' => $oi->ukuran->kode, 'qty' => 0];
                $baris[$kunci]['qty'] += $pi->qty;
            }
        }

        $hasil = array_values($baris);
        usort($hasil, fn ($a, $b) => [$a['produk'], $a['warna'], $a['ukuran']] <=> [$b['produk'], $b['warna'], $b['ukuran']]);

        return $hasil;
    }

    /** Path absolut bukti terima untuk diunduh. */
    public function pathBukti(int $id, int $userId): array
    {
        $this->authorizeLihat(User::findOrFail($userId));
        $pengiriman = Pengiriman::findOrFail($id);

        if (! $pengiriman->bukti_terima_path || ! Storage::disk('local')->exists($pengiriman->bukti_terima_path)) {
            throw new BusinessRuleException('Bukti terima tidak tersedia.', 'TIDAK_DITEMUKAN', 404);
        }

        return [
            Storage::disk('local')->path($pengiriman->bukti_terima_path),
            "bukti-terima-{$pengiriman->no_sj}.".pathinfo($pengiriman->bukti_terima_path, PATHINFO_EXTENSION),
        ];
    }

    /** Mengunci koli milik SJ dan memastikan statusnya sesuai yang diharapkan. */
    private function kunciKoli(Pengiriman $pengiriman, string $statusDiharapkan): Collection
    {
        $packingIds = $pengiriman->pengirimanKoli()->pluck('packing_id')->all();
        $koli = Packing::whereIn('id', $packingIds)->orderBy('id')->lockForUpdate()->with('packingItems')->get();

        foreach ($koli as $k) {
            if ($k->status->value !== $statusDiharapkan) {
                throw new BusinessRuleException("Koli {$k->no_koli} berstatus {$k->status->value}, seharusnya {$statusDiharapkan}.", 'STATUS_TIDAK_VALID');
            }
        }

        return $koli;
    }

    /** @return array<int,int> wo_item_id => total qty */
    private function qtyPerWoItem(Collection $koli): array
    {
        $perItem = [];
        foreach ($koli as $k) {
            foreach ($k->packingItems as $pi) {
                $perItem[$pi->wo_item_id] = ($perItem[$pi->wo_item_id] ?? 0) + $pi->qty;
            }
        }

        return $perItem;
    }

    /** Mengunci wo_items secara berurutan id agar tidak deadlock antar transaksi. */
    private function kunciWoItem(array $perItem): Collection
    {
        return WoItem::whereIn('id', array_keys($perItem))->orderBy('id')->lockForUpdate()->get();
    }

    /** Buat/kirim/batal sebelum dikirim: Admin dan Gudang (bagian 3.2 rancangan). */
    private function authorizeKelola(User $user): void
    {
        if (! $user->hasRole('admin', 'gudang')) {
            throw new BusinessRuleException('Hanya Admin atau Gudang yang dapat mengelola pengiriman.', 'TIDAK_ADA_HAK', 403);
        }
    }

    private function authorizeLihat(User $user): void
    {
        if (! $user->hasRole('admin', 'manajer', 'staf_order', 'gudang', 'pimpinan')) {
            throw new BusinessRuleException('Anda tidak berhak melihat data pengiriman.', 'TIDAK_ADA_HAK', 403);
        }
    }
}