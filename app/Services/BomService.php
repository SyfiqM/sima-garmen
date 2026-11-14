<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Helpers\Audit;
use App\Models\Bahan;
use App\Models\Bom; // Tambahan untuk fungsi hitungKebutuhan
use App\Models\Order;
use App\Models\Tahap;
use Illuminate\Support\Facades\DB;

class BomService
{
    /**
     * Resolusi baris BOM per item order dan hitung kebutuhan total per (bahan_id, tahap_id)
     */
    public function hitungKebutuhan(Order $order): array
    {
        $kebutuhan = [];

        foreach ($order->items as $item) {
            // Cari kandidat BOM (kosong atau spesifik ke item)
            $boms = Bom::where('produk_id', $item->produk_id)
                ->whereHas('bahan', fn ($query) => $query->where('is_active', true))
                ->where(function ($q) use ($item) {
                    $q->whereNull('ukuran_id')->orWhere('ukuran_id', $item->ukuran_id);
                })
                ->where(function ($q) use ($item) {
                    $q->whereNull('warna_id')->orWhere('warna_id', $item->warna_id);
                })
                ->get();

            // Kelompokkan kandidat per bahan dan tahap
            $grouped = $boms->groupBy(function ($bom) {
                return $bom->bahan_id.'_'.$bom->tahap_id;
            });

            foreach ($grouped as $key => $group) {
                // Paling spesifik menang: ukuran+warna (3) > ukuran (2) > warna (1) > umum (0)
                $terpilih = $group->sortByDesc(function ($bom) {
                    $score = 0;
                    if ($bom->ukuran_id) {
                        $score += 2;
                    }
                    if ($bom->warna_id) {
                        $score += 1;
                    }

                    return $score;
                })->first();

                // Hitung qty: qty * qty_per_pcs * (1 + waste%)
                $qtyRencana = $item->qty * $terpilih->qty_per_pcs * (1 + ($terpilih->waste_persen / 100));

                if (! isset($kebutuhan[$key])) {
                    $kebutuhan[$key] = [
                        'bahan_id' => $terpilih->bahan_id,
                        'tahap_id' => $terpilih->tahap_id,
                        'qty_rencana' => 0,
                    ];
                }
                $kebutuhan[$key]['qty_rencana'] += $qtyRencana;
            }
        }

        // Pembulatan ke atas 3 desimal
        foreach ($kebutuhan as &$keb) {
            $keb['qty_rencana'] = ceil($keb['qty_rencana'] * 1000) / 1000;
        }

        return array_values($kebutuhan);
    }

    public function validasiKelengkapan(Order $order): void
    {
        $cuttingId = Tahap::where('kode', 'cutting')->value('id');

        foreach ($order->items as $item) {
            $hasCuttingBom = Bom::where('produk_id', $item->produk_id)
                ->where('tahap_id', $cuttingId)
                ->where(function ($query) use ($item) {
                    $query->whereNull('ukuran_id')->orWhere('ukuran_id', $item->ukuran_id);
                })
                ->where(function ($query) use ($item) {
                    $query->whereNull('warna_id')->orWhere('warna_id', $item->warna_id);
                })
                ->whereHas('bahan', fn ($query) => $query->where('is_active', true))
                ->exists();

            if (! $hasCuttingBom) {
                throw new BusinessRuleException(
                    "BOM produk {$item->produk_id} belum lengkap untuk tahap cutting.",
                    'BOM_BELUM_LENGKAP',
                    422,
                );
            }
        }
    }

    public function pratinjau(int $produkId, int $ukuranId, int $warnaId, int $qty): array
    {
        $boms = Bom::with(['bahan.satuan', 'tahap'])
            ->where('produk_id', $produkId)
            ->where(fn ($query) => $query->whereNull('ukuran_id')->orWhere('ukuran_id', $ukuranId))
            ->where(fn ($query) => $query->whereNull('warna_id')->orWhere('warna_id', $warnaId))
            ->get()
            ->groupBy(fn (Bom $bom) => $bom->bahan_id.'_'.$bom->tahap_id);

        $result = [];
        foreach ($boms as $group) {
            $bom = $group->sortByDesc(fn (Bom $row) => ($row->ukuran_id ? 2 : 0) + ($row->warna_id ? 1 : 0))->first();
            $qtyRencana = $qty * (float) $bom->qty_per_pcs * (1 + ((float) $bom->waste_persen / 100));
            $result[] = [
                'bahan' => $bom->bahan,
                'tahap' => $bom->tahap,
                'qty_rencana' => ceil($qtyRencana * 1000) / 1000,
            ];
        }

        return $result;
    }

    // ====================================================================
    // KODE ASLI ANDA UNTUK CRUD BOM CONTROLLER (BATCH 2)
    // ====================================================================

    public function store($produkId, array $data, int $userId): Bom
    {
        return DB::transaction(function () use ($produkId, $data, $userId) {
            $this->validateBahanAktif($data['bahan_id']);
            $this->validateUnique($produkId, $data['bahan_id'], $data['tahap_id'], $data['ukuran_id'] ?? null, $data['warna_id'] ?? null);

            $data['produk_id'] = $produkId;

            // Gunakan nilai default dari pengaturan jika waste kosong.
            if (! isset($data['waste_persen']) || $data['waste_persen'] === null) {
                $data['waste_persen'] = DB::table('pengaturan')->where('key', 'default_waste_persen')->value('value') ?? 5;
            }

            $bom = Bom::create($data);
            Audit::log($userId, 'bom.create', 'Bom', $bom->id, null, $bom->toArray());

            return $bom->load(['bahan', 'tahap', 'ukuran', 'warna']);
        });
    }

    public function update($bomId, array $data, int $userId): Bom
    {
        return DB::transaction(function () use ($bomId, $data, $userId) {
            $bom = Bom::whereKey($bomId)->lockForUpdate()->firstOrFail();
            $sebelum = $bom->toArray();

            $bahanId = $data['bahan_id'] ?? $bom->bahan_id;
            $this->validateBahanAktif($bahanId);

            $tahapId = $data['tahap_id'] ?? $bom->tahap_id;
            $ukuranId = array_key_exists('ukuran_id', $data) ? $data['ukuran_id'] : $bom->ukuran_id;
            $warnaId = array_key_exists('warna_id', $data) ? $data['warna_id'] : $bom->warna_id;

            $this->validateUnique($bom->produk_id, $bahanId, $tahapId, $ukuranId, $warnaId, $bom->id);
            $bom->update($data);

            Audit::log($userId, 'bom.update', 'Bom', $bom->id, $sebelum, $bom->fresh()->toArray());

            return $bom->fresh()->load(['bahan', 'tahap', 'ukuran', 'warna']);
        });
    }

    public function destroy(int $bomId, int $userId): void
    {
        DB::transaction(function () use ($bomId, $userId) {
            $bom = Bom::whereKey($bomId)->lockForUpdate()->firstOrFail();
            $sebelum = $bom->toArray();
            $bom->delete();

            Audit::log($userId, 'bom.delete', 'Bom', $bomId, $sebelum, null);
        });
    }

    public function copyFrom($produkTujuanId, $produkAsalId, int $userId): void
    {
        DB::transaction(function () use ($produkTujuanId, $produkAsalId, $userId) {
            $bomAsal = Bom::where('produk_id', $produkAsalId)->orderBy('id')->get();

            if ($bomAsal->isEmpty()) {
                throw new BusinessRuleException('Produk asal tidak memiliki data BOM.', 'DATA_KOSONG', 422);
            }

            $sebelum = Bom::where('produk_id', $produkTujuanId)->orderBy('id')->get()->toArray();
            Bom::where('produk_id', $produkTujuanId)->delete();

            foreach ($bomAsal as $bom) {
                $baru = $bom->toArray();
                unset($baru['id'], $baru['created_at'], $baru['updated_at']);
                $baru['produk_id'] = $produkTujuanId;
                Bom::create($baru);
            }

            $sesudah = Bom::where('produk_id', $produkTujuanId)->orderBy('id')->get()->toArray();
            Audit::log($userId, 'bom.copy', 'Produk', $produkTujuanId, $sebelum, $sesudah, "Salin BOM dari produk {$produkAsalId}");
        });
    }

    private function validateBahanAktif($bahanId)
    {
        $bahan = Bahan::find($bahanId);
        if (! $bahan || ! $bahan->is_active) {
            throw new BusinessRuleException('Bahan tidak ditemukan atau statusnya tidak aktif.', 'STATUS_TIDAK_VALID', 422);
        }
    }

    private function validateUnique($produkId, $bahanId, $tahapId, $ukuranId = null, $warnaId = null, $ignoreBomId = null)
    {
        $query = Bom::where('produk_id', $produkId)
            ->where('bahan_id', $bahanId)
            ->where('tahap_id', $tahapId);

        $query->where('ukuran_id', $ukuranId); // Akan valid mencocokkan NULL dengan NULL di Laravel Eloquent isNull() jika $ukuranId null
        $query->where('warna_id', $warnaId);

        if ($ignoreBomId) {
            $query->where('id', '!=', $ignoreBomId);
        }

        if ($query->exists()) {
            throw new BusinessRuleException('BOM dengan kombinasi produk, bahan, tahap, ukuran, dan warna yang sama persis sudah ada.', 'DATA_SUDAH_DIPAKAI', 409);
        }
    }
}
