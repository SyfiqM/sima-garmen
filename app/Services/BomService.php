<?php

namespace App\Services;

use App\Models\BOM;
use App\Models\Bahan;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class BomService
{
    public function store($produkId, array $data)
    {
        $this->validateBahanAktif($data['bahan_id']);
        $this->validateUnique($produkId, $data['bahan_id'], $data['tahap_id'], $data['ukuran_id'] ?? null, $data['warna_id'] ?? null);

        $data['produk_id'] = $produkId;
        
        // Gunakan nilai default dari pengaturan jika waste kosong
        if (!isset($data['waste_persen']) || $data['waste_persen'] === null) {
            $data['waste_persen'] = DB::table('pengaturan')->where('key', 'default_waste_persen')->value('value') ?? 5;
        }

        return BOM::create($data);
    }

    public function update($bomId, array $data)
    {
        $bom = BOM::findOrFail($bomId);
        
        $bahanId = $data['bahan_id'] ?? $bom->bahan_id;
        $this->validateBahanAktif($bahanId);

        $tahapId = $data['tahap_id'] ?? $bom->tahap_id;
        $ukuranId = array_key_exists('ukuran_id', $data) ? $data['ukuran_id'] : $bom->ukuran_id;
        $warnaId = array_key_exists('warna_id', $data) ? $data['warna_id'] : $bom->warna_id;

        $this->validateUnique($bom->produk_id, $bahanId, $tahapId, $ukuranId, $warnaId, $bom->id);

        $bom->update($data);
        return $bom;
    }

    public function copyFrom($produkTujuanId, $produkAsalId)
    {
        $bomAsal = BOM::where('produk_id', $produkAsalId)->get();
        
        if ($bomAsal->isEmpty()) {
            throw new BusinessRuleException('Produk asal tidak memiliki data BOM.', 'DATA_KOSONG', 422);
        }

        DB::transaction(function () use ($produkTujuanId, $bomAsal) {
            // Hapus BOM lama di produk tujuan
            BOM::where('produk_id', $produkTujuanId)->delete();
            
            // Salin satu per satu
            foreach ($bomAsal as $bom) {
                $baru = $bom->toArray();
                unset($baru['id'], $baru['created_at'], $baru['updated_at']);
                $baru['produk_id'] = $produkTujuanId;
                BOM::create($baru);
            }
        });
    }

    private function validateBahanAktif($bahanId)
    {
        $bahan = Bahan::find($bahanId);
        if (!$bahan || !$bahan->is_active) {
            throw new BusinessRuleException('Bahan tidak ditemukan atau statusnya tidak aktif.', 'STATUS_TIDAK_VALID', 422);
        }
    }

    private function validateUnique($produkId, $bahanId, $tahapId, $ukuranId = null, $warnaId = null, $ignoreBomId = null)
    {
        $query = BOM::where('produk_id', $produkId)
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