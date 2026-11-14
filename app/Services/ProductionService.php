<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Helpers\Audit;
use App\Models\LineProduksi;
use App\Models\ProduksiLog;
use App\Models\Tahap;
use App\Models\User;
use App\Models\WoItem;
use App\Models\WorkOrder;
use App\Models\WoTahap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProductionService
{
    public function catatProduksi(WorkOrder $wo, int $tahapId, array $items, string $tanggal, int $userId)
    {
        return DB::transaction(function () use ($wo, $tahapId, $items, $tanggal, $userId) {
            $wo = WorkOrder::whereKey($wo->id)->lockForUpdate()->firstOrFail();
            $order = $wo->order()->lockForUpdate()->firstOrFail();
            $tahap = Tahap::findOrFail($tahapId);
            $kodeTahap = $tahap->kode->value;
            $user = User::findOrFail($userId);
            $this->authorizeTahap($user, $tahapId);

            if ($wo->status->value !== 'aktif' || ! in_array($order->status->value, ['siap_produksi', 'produksi'], true)) {
                throw new BusinessRuleException('WO tidak dalam status siap diproduksi.', 'STATUS_TIDAK_VALID');
            }

            $woTahap = WoTahap::where('wo_id', $wo->id)
                ->where('tahap_id', $tahapId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($woTahap->status->value === 'selesai') {
                throw new BusinessRuleException('Tahap ini sudah ditutup.', 'TAHAP_SUDAH_DITUTUP');
            }

            $this->validasiTanggal($tanggal, $user);
            $bahanBelumLengkap = $wo->woBahan()
                ->where('tahap_id', $tahapId)
                ->whereRaw('qty_reservasi < qty_rencana')
                ->exists();
            if ($bahanBelumLengkap) {
                throw new BusinessRuleException('Reservasi bahan tahap ini belum lengkap.', 'BAHAN_TAHAP_BELUM_LENGKAP');
            }

            $logIds = [];
            foreach ($items as $item) {
                $woItem = WoItem::where('wo_id', $wo->id)
                    ->whereKey($item['wo_item_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $qtyHasil = $item['qty_hasil'] ?? 0;
                $qtyReject = $item['qty_reject'] ?? 0;

                if ($qtyHasil + $qtyReject <= 0) {
                    continue;
                }

                if ($qtyReject > 0 && empty($item['jenis_cacat_id'])) {
                    throw new BusinessRuleException('Jenis cacat wajib diisi jika ada reject.', 'VALIDASI_GAGAL');
                }
                if (! empty($item['line_id']) && ! LineProduksi::whereKey($item['line_id'])->where('tahap_id', $tahapId)->exists()) {
                    throw new BusinessRuleException('Line produksi tidak sesuai dengan tahap input.', 'VALIDASI_GAGAL');
                }
                if ($qtyHasil < 0 || $qtyReject < 0 || (int) $qtyHasil !== $qtyHasil || (int) $qtyReject !== $qtyReject) {
                    throw new BusinessRuleException('Qty hasil dan reject harus bilangan bulat non-negatif.', 'VALIDASI_GAGAL');
                }

                // 3. Validasi batas sesuai rumus ketersediaan
                $batas = 0;
                if ($kodeTahap === 'cutting') {
                    $batas = $woItem->qty_batas_potong - ($woItem->qty_cutting + $woItem->qty_cutting_reject);
                } elseif ($kodeTahap === 'sewing') {
                    $batas = $woItem->qty_cutting - ($woItem->qty_sewing + $woItem->qty_sewing_reject);
                } elseif ($kodeTahap === 'finishing') {
                    $batas = $woItem->qty_sewing - ($woItem->qty_finishing + $woItem->qty_finishing_reject);
                }

                if (($qtyHasil + $qtyReject) > $batas) {
                    throw new BusinessRuleException('Input melebihi batas ketersediaan (maks: '.$batas.').', 'QTY_MELEBIHI_BATAS');
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
                    'dibuat_oleh' => $userId,
                ]);
                $logIds[] = $log->id;

                // 5. Update counter di wo_items
                if ($kodeTahap === 'cutting') {
                    $woItem->qty_cutting += $qtyHasil;
                    $woItem->qty_cutting_reject += $qtyReject;
                } elseif ($kodeTahap === 'sewing') {
                    $woItem->qty_sewing += $qtyHasil;
                    $woItem->qty_sewing_reject += $qtyReject;
                } elseif ($kodeTahap === 'finishing') {
                    $woItem->qty_finishing += $qtyHasil;
                    $woItem->qty_finishing_reject += $qtyReject;
                }
                $woItem->save();
            }

            if ($logIds !== [] && $order->status->value === 'siap_produksi') {
                $wo->update(['tgl_mulai' => $tanggal]);
                $order->update(['status' => 'produksi']);
            }

            if ($logIds !== [] && $woTahap->status->value === 'menunggu') {
                $woTahap->update(['status' => 'berjalan', 'mulai_at' => now()]);
            }

            if ($logIds !== []) {
                Audit::log($userId, 'produksi.create', 'WorkOrder', $wo->id, null, ['tahap_id' => $tahapId, 'log_ids' => $logIds]);
            }

            return $logIds;
        });
    }

    public function antrean(int $userId)
    {
        $user = User::findOrFail($userId);
        if (! $user->hasRole('admin', 'supervisor')) {
            throw new BusinessRuleException('Anda tidak memiliki hak melihat antrean produksi.', 'TIDAK_ADA_HAK', 403);
        }

        $query = WorkOrder::with(['order.pelanggan', 'woItems.orderItem.produk', 'woItems.orderItem.ukuran', 'woItems.orderItem.warna'])
            ->where('status', 'aktif');

        if ($user->hasRole('supervisor')) {
            if (! $user->tahap_id) {
                throw new BusinessRuleException('Supervisor belum memiliki tahap kerja.', 'TIDAK_ADA_HAK', 403);
            }
            $tahapId = $user->tahap_id;
            $query->whereHas('woTahap', fn ($stageQuery) => $stageQuery->where('tahap_id', $tahapId)->where('status', '!=', 'selesai'));
        }

        return $query->latest('id')->get()->map(function (WorkOrder $workOrder) use ($user) {
            $tahapId = $user->hasRole('supervisor') ? (int) $user->tahap_id : null;
            $workOrder->setRelation('woItems', $workOrder->woItems->map(function (WoItem $woItem) use ($tahapId) {
                $woItem->setAttribute('qty_tersedia_tahap', $tahapId ? $this->qtyTersedia($woItem, Tahap::findOrFail($tahapId)->kode->value) : null);

                return $woItem;
            }));

            return $workOrder;
        });
    }

    public function riwayat(int $userId, ?int $workOrderId = null)
    {
        $user = User::findOrFail($userId);
        if (! $user->hasRole('admin', 'manajer', 'supervisor')) {
            throw new BusinessRuleException('Anda tidak memiliki hak melihat riwayat produksi.', 'TIDAK_ADA_HAK', 403);
        }

        $query = ProduksiLog::with(['woItem.wo.order', 'tahap', 'line', 'jenisCacat', 'dibuatOleh'])->latest('id');
        if ($workOrderId !== null) {
            $query->whereHas('woItem', fn ($woItemQuery) => $woItemQuery->where('wo_id', $workOrderId));
        }
        if ($user->hasRole('supervisor')) {
            $query->where('tahap_id', $user->tahap_id);
        }

        return $query->paginate(25);
    }

    public function voidLog(int $id, string $alasan, int $userId): ProduksiLog
    {
        return DB::transaction(function () use ($id, $alasan, $userId) {
            $log = ProduksiLog::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($log->status->value !== 'aktif') {
                throw new BusinessRuleException('Log produksi sudah void.', 'STATUS_TIDAK_VALID', 409);
            }

            $user = User::findOrFail($userId);
            $batasJam = (int) (DB::table('pengaturan')->where('key', 'batas_void_jam')->value('value') ?? 24);
            $dalamBatas = $log->created_at->diffInHours(now()) <= $batasJam;
            if ((! $dalamBatas || $log->dibuat_oleh !== $userId) && ! $user->hasRole('admin', 'manajer')) {
                throw new BusinessRuleException('Anda tidak berhak melakukan void log ini.', 'TIDAK_ADA_HAK', 403);
            }
            if (mb_strlen(trim($alasan)) < 5) {
                throw new BusinessRuleException('Alasan void wajib minimal 5 karakter.', 'ALASAN_WAJIB', 422);
            }

            $woItem = WoItem::whereKey($log->wo_item_id)->lockForUpdate()->firstOrFail();
            $tahap = $log->tahap->kode->value;
            $hasilField = match ($tahap) {
                'cutting' => 'qty_cutting',
                'sewing' => 'qty_sewing',
                'finishing' => 'qty_finishing',
            };
            $rejectField = match ($tahap) {
                'cutting' => 'qty_cutting_reject',
                'sewing' => 'qty_sewing_reject',
                'finishing' => 'qty_finishing_reject',
            };
            $hasilSesudahVoid = (int) $woItem->{$hasilField} - $log->qty_hasil;
            $rejectSesudahVoid = (int) $woItem->{$rejectField} - $log->qty_reject;

            if ($hasilSesudahVoid < 0 || $rejectSesudahVoid < 0 || ! $this->hilirTetapValid($woItem, $tahap, $hasilSesudahVoid)) {
                throw new BusinessRuleException('Void ditolak karena membuat ketersediaan tahap hilir negatif.', 'VOID_DITOLAK_HILIR_NEGATIF', 422);
            }

            $woItem->{$hasilField} = $hasilSesudahVoid;
            $woItem->{$rejectField} = $rejectSesudahVoid;
            $woItem->save();
            $log->update(['status' => 'void', 'void_alasan' => $alasan, 'void_oleh' => $userId, 'void_at' => now()]);
            Audit::log($userId, 'produksi.void', 'ProduksiLog', $log->id, null, ['status' => 'void'], $alasan);

            return $log;
        });
    }

    public function tutupTahap(int $workOrderId, string $kodeTahap, ?string $alasan, int $userId): WoTahap
    {
        return DB::transaction(function () use ($workOrderId, $kodeTahap, $alasan, $userId) {
            $tahap = Tahap::where('kode', $kodeTahap)->firstOrFail();
            $workOrder = WorkOrder::whereKey($workOrderId)->lockForUpdate()->firstOrFail();
            $user = User::findOrFail($userId);
            if (! $user->hasRole('admin', 'manajer') && (! $user->hasRole('supervisor') || (int) $user->tahap_id !== (int) $tahap->id)) {
                throw new BusinessRuleException('Anda tidak berhak menutup tahap ini.', 'TIDAK_ADA_HAK', 403);
            }
            $woTahap = WoTahap::where('wo_id', $workOrderId)->where('tahap_id', $tahap->id)->lockForUpdate()->firstOrFail();

            if ($woTahap->status->value === 'selesai') {
                throw new BusinessRuleException('Tahap sudah ditutup.', 'TAHAP_SUDAH_DITUTUP', 409);
            }

            $tahapSebelumnya = Tahap::where('urutan', '<', $tahap->urutan)->orderByDesc('urutan')->first();
            if ($tahapSebelumnya && ! WoTahap::where('wo_id', $workOrderId)->where('tahap_id', $tahapSebelumnya->id)->where('status', 'selesai')->exists()) {
                throw new BusinessRuleException('Tahap sebelumnya belum selesai.', 'STATUS_TIDAK_VALID', 422);
            }

            $sisaAntrean = $workOrder->woItems->sum(fn (WoItem $item) => $this->qtyTersedia($item, $kodeTahap));
            if ($sisaAntrean > 0 && ($alasan === null || mb_strlen(trim($alasan)) < 5)) {
                throw new BusinessRuleException('Alasan wajib jika tahap ditutup dengan sisa antrean.', 'ALASAN_WAJIB', 422);
            }

            $woTahap->update(['status' => 'selesai', 'selesai_at' => now(), 'ditutup_oleh' => $userId, 'catatan_penutupan' => $alasan]);
            Audit::log($userId, 'produksi.tahap_tutup', 'WorkOrder', $workOrder->id, null, ['kode' => $kodeTahap, 'sisa_antrean' => $sisaAntrean], $alasan);

            $tahapBerikut = Tahap::where('urutan', '>', $tahap->urutan)->orderBy('urutan')->first();
            if ($tahapBerikut) {
                NotificationService::toRoles(
                    ['supervisor'],
                    "Tahap {$tahap->nama} ditutup",
                    "{$workOrder->no_wo}: hasil {$tahap->nama} tersedia untuk {$tahapBerikut->nama}.",
                    null,
                    'info',
                    $userId,
                    $tahapBerikut->id,
                );
            } else {
                NotificationService::toRoles(['qc'], "Tahap {$tahap->nama} ditutup", "{$workOrder->no_wo}: hasil {$tahap->nama} siap diinspeksi QC.", null, 'info', $userId);
            }

            return $woTahap;
        });
    }

    public function bukaTahap(int $workOrderId, string $kodeTahap, string $alasan, int $userId): WoTahap
    {
        $this->authorizeManager($userId);
        $alasan = $this->validasiAlasan($alasan);

        return DB::transaction(function () use ($workOrderId, $kodeTahap, $alasan, $userId) {
            $tahap = Tahap::where('kode', $kodeTahap)->firstOrFail();
            $woTahap = WoTahap::where('wo_id', $workOrderId)->where('tahap_id', $tahap->id)->lockForUpdate()->firstOrFail();
            if ($woTahap->status->value !== 'selesai') {
                throw new BusinessRuleException('Hanya tahap selesai yang dapat dibuka kembali.', 'STATUS_TIDAK_VALID', 422);
            }

            $woTahap->update(['status' => 'berjalan', 'selesai_at' => null, 'catatan_penutupan' => $alasan]);
            Audit::log($userId, 'produksi.tahap_buka', 'WoTahap', $woTahap->id, ['status' => 'selesai'], ['status' => 'berjalan'], $alasan);

            return $woTahap;
        });
    }

    public function tambahPotong(int $woItemId, int $qty, string $alasan, int $userId): WoItem
    {
        $this->authorizeManager($userId);
        $alasan = $this->validasiAlasan($alasan);
        if ($qty < 1) {
            throw new BusinessRuleException('Qty tambahan potong minimal 1.', 'VALIDASI_GAGAL', 422);
        }

        return DB::transaction(function () use ($woItemId, $qty, $alasan, $userId) {
            $woItem = WoItem::whereKey($woItemId)->lockForUpdate()->firstOrFail();
            $sebelum = $woItem->qty_batas_potong;
            $woItem->increment('qty_batas_potong', $qty);
            Audit::log($userId, 'produksi.tambah_potong', 'WoItem', $woItem->id, ['qty_batas_potong' => $sebelum], ['qty_batas_potong' => $woItem->qty_batas_potong], $alasan);

            return $woItem->refresh();
        });
    }

    private function authorizeTahap(User $user, int $tahapId): void
    {
        if ($user->hasRole('admin')) {
            return;
        }
        if (! $user->hasRole('supervisor') || (int) $user->tahap_id !== $tahapId) {
            throw new BusinessRuleException('Supervisor hanya dapat mengakses tahap miliknya.', 'TIDAK_ADA_HAK', 403);
        }
    }

    private function authorizeManager(int $userId): void
    {
        if (! User::findOrFail($userId)->hasRole('admin', 'manajer')) {
            throw new BusinessRuleException('Aksi ini hanya untuk Manajer atau Admin.', 'TIDAK_ADA_HAK', 403);
        }
    }

    private function validasiTanggal(string $tanggal, User $user): void
    {
        $date = Carbon::parse($tanggal)->startOfDay();
        if ($date->gt(now()->startOfDay())) {
            throw new BusinessRuleException('Tanggal produksi tidak boleh di masa depan.', 'VALIDASI_GAGAL', 422);
        }
        $backdateHari = (int) (DB::table('pengaturan')->where('key', 'backdate_hari')->value('value') ?? 3);
        if (! $user->hasRole('admin', 'manajer') && $date->lt(now()->subDays($backdateHari)->startOfDay())) {
            throw new BusinessRuleException("Tanggal produksi tidak boleh lebih lama dari {$backdateHari} hari.", 'VALIDASI_GAGAL', 422);
        }
    }

    private function qtyTersedia(WoItem $woItem, string $kodeTahap): int
    {
        return match ($kodeTahap) {
            'cutting' => max(0, $woItem->qty_batas_potong - $woItem->qty_cutting - $woItem->qty_cutting_reject),
            'sewing' => max(0, $woItem->qty_cutting - $woItem->qty_sewing - $woItem->qty_sewing_reject),
            'finishing' => max(0, $woItem->qty_sewing - $woItem->qty_finishing - $woItem->qty_finishing_reject),
        };
    }

    private function hilirTetapValid(WoItem $woItem, string $kodeTahap, int $hasilSesudahVoid): bool
    {
        return match ($kodeTahap) {
            'cutting' => $hasilSesudahVoid >= $woItem->qty_sewing + $woItem->qty_sewing_reject,
            'sewing' => $hasilSesudahVoid >= $woItem->qty_finishing + $woItem->qty_finishing_reject,
            'finishing' => $hasilSesudahVoid + $woItem->qty_repair_selesai >= $woItem->qty_qc_diperiksa,
        };
    }

    private function validasiAlasan(string $alasan): string
    {
        if (mb_strlen(trim($alasan)) < 5) {
            throw new BusinessRuleException('Alasan wajib diisi minimal 5 karakter.', 'ALASAN_WAJIB', 422);
        }

        return $alasan;
    }
}