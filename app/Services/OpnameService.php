<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Helpers\Audit;
use App\Helpers\NumberingService;
use App\Models\Bahan;
use App\Models\StokOpname;
use App\Models\StokOpnameItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OpnameService
{
    protected $stockService;

    protected function statusValue($status): ?string
    {
        if ($status instanceof \BackedEnum) {
            return $status->value;
        }

        return is_string($status) ? $status : null;
    }

    public function __construct(StockService $stockService)
    {
        $this->stockService = $stockService;
    }

    public function store(array $data, int $userId)
    {
        return DB::transaction(function () use ($data, $userId) {
            $data['no_opname'] = NumberingService::next('OPN');
            $data['status'] = 'draft';
            $data['dibuat_oleh'] = $userId;

            $opname = StokOpname::create($data);

            foreach ($data['items'] as $item) {
                $bahan = Bahan::findOrFail($item['bahan_id']);
                $selisih = $item['stok_fisik'] - $bahan->stok;

                if ($selisih != 0 && empty($item['alasan'])) {
                    throw new BusinessRuleException("Alasan wajib diisi karena ada selisih pada bahan: {$bahan->nama}", 'ALASAN_WAJIB', 422);
                }

                StokOpnameItem::create([
                    'opname_id' => $opname->id,
                    'bahan_id' => $bahan->id,
                    'stok_sistem' => $bahan->stok,
                    'stok_fisik' => $item['stok_fisik'],
                    'selisih' => $selisih,
                    'alasan' => $item['alasan'] ?? null,
                ]);
            }

            if (class_exists(Audit::class)) {
                Audit::log($userId, 'opname.create', 'StokOpname', $opname->id, null, $opname->toArray(), 'Membuat draft stok opname');
            }

            return $opname->load('items');
        });
    }

    public function ajukan(int $id, int $userId)
    {
        return DB::transaction(function () use ($id, $userId) {
            $opname = StokOpname::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($this->statusValue($opname->status) !== 'draft') {
                throw new BusinessRuleException('Hanya draft yang bisa diajukan.', 'STATUS_TIDAK_VALID', 422);
            }
            if ($opname->dibuat_oleh !== $userId && ! User::findOrFail($userId)->hasRole('admin')) {
                throw new BusinessRuleException('Anda hanya dapat mengajukan opname milik sendiri.', 'TIDAK_ADA_HAK', 403);
            }
            $sebelum = $opname->toArray();
            $opname->update(['status' => 'diajukan']);
            Audit::log($userId, 'opname.ajukan', 'StokOpname', $opname->id, $sebelum, $opname->toArray());

            return $opname;
        });
    }

    public function tolak(int $id, array $data, int $userId)
    {
        if (empty($data['alasan'])) {
            throw new BusinessRuleException('Alasan penolakan wajib diisi.', 'ALASAN_WAJIB', 422);
        }

        if (mb_strlen(trim($data['alasan'])) < 5) {
            throw new BusinessRuleException('Alasan penolakan wajib minimal 5 karakter.', 'ALASAN_WAJIB', 422);
        }

        return DB::transaction(function () use ($id, $data, $userId) {
            $opname = StokOpname::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($this->statusValue($opname->status) !== 'diajukan') {
                throw new BusinessRuleException('Hanya status diajukan yang bisa ditolak.', 'STATUS_TIDAK_VALID', 422);
            }
            $sebelum = $opname->toArray();
            $opname->update([
                'status' => 'ditolak',
                'alasan_penolakan' => $data['alasan'],
                'disetujui_oleh' => $userId,
                'disetujui_at' => now(),
            ]);
            Audit::log($userId, 'opname.tolak', 'StokOpname', $opname->id, $sebelum, $opname->toArray(), $data['alasan']);

            return $opname;
        });
    }

    public function setujui(int $id, int $userId)
    {
        return DB::transaction(function () use ($id, $userId) {
            $opname = StokOpname::with('items')->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($this->statusValue($opname->status) !== 'diajukan') {
                throw new BusinessRuleException('Hanya status diajukan yang bisa disetujui.', 'STATUS_TIDAK_VALID', 422);
            }

            foreach ($opname->items as $item) {
                if ($item->selisih != 0) {
                    $bahan = Bahan::lockForUpdate()->find($item->bahan_id);

                    // Cek perlindungan reservasi (aturan 4.12)
                    if ($item->stok_fisik < $bahan->stok_dipesan) {
                        throw new BusinessRuleException("Stok fisik {$bahan->kode} ({$item->stok_fisik}) di bawah jumlah reservasi berjalan ({$bahan->stok_dipesan}). Lepaskan reservasi terlebih dahulu.", 'STOK_DI_BAWAH_RESERVASI', 422);
                    }

                    // Panggil mutasi penyesuaian
                    $this->stockService->mutasi(
                        $item->bahan_id,
                        'penyesuaian',
                        $item->selisih,
                        'stok_opname',
                        $opname->id,
                        "Penyesuaian Opname No: {$opname->no_opname}",
                        $userId
                    );
                }
            }

            $opname->update([
                'status' => 'disetujui',
                'disetujui_oleh' => $userId,
                'disetujui_at' => now(),
            ]);

            if (class_exists(Audit::class)) {
                Audit::log($userId, 'opname.setujui', 'StokOpname', $opname->id, null, $opname->toArray(), 'Menyetujui stok opname');
            }

            return $opname;
        });
    }
}
