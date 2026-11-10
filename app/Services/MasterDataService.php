<?php

namespace App\Services;

use App\Helpers\Audit;
use App\Exceptions\BusinessRuleException;
use Illuminate\Database\QueryException;

class MasterDataService
{
    public function store($modelClass, array $data, $userId)
    {
        // Auto-generate kode jika kosong (misal: PEL-0001)
        if (in_array('kode', app($modelClass)->getFillable()) && empty($data['kode'])) {
            $data['kode'] = $this->generateKode($modelClass);
        }

        $model = $modelClass::create($data);
        
        if (class_exists(Audit::class)) {
            Audit::log($userId, 'master.create', $modelClass, $model->id, null, $model->toArray(), 'Buat data master baru');
        }

        return $model;
    }

    public function update($model, array $data, $userId)
    {
        $sebelum = $model->toArray();
        $model->update($data);

        if (class_exists(Audit::class)) {
            Audit::log($userId, 'master.update', get_class($model), $model->id, $sebelum, $model->toArray(), 'Ubah data master');
        }

        return $model;
    }

    public function destroy($model, $userId)
    {
        try {
            $sebelum = $model->toArray();
            
            // Tahap tidak boleh dihapus
            if (class_basename($model) === 'Tahap') {
                throw new BusinessRuleException('Data Tahap bersifat tetap dan tidak boleh dihapus.', 'STATUS_TIDAK_VALID', 422);
            }

            $model->delete();

            if (class_exists(Audit::class)) {
                Audit::log($userId, 'master.delete', get_class($model), $model->id, $sebelum, null, 'Hapus data master');
            }
        } catch (QueryException $e) {
            // Error 23000 / 1451: Foreign key constraint fails (Data sudah dipakai di tabel lain)
            if ($e->getCode() == '23000') {
                throw new BusinessRuleException('Data master tidak dapat dihapus karena sudah dipakai dalam transaksi. Silakan gunakan fitur nonaktifkan.', 'DATA_SUDAH_DIPAKAI', 409);
            }
            throw $e;
        }
    }

    public function setStatus($model, $isActive, $userId)
    {
        $sebelum = $model->toArray();
        $model->update(['is_active' => $isActive]);
        
        $aksi = $isActive ? 'master.aktifkan' : 'master.nonaktifkan';
        $pesan = $isActive ? 'Aktifkan master' : 'Nonaktifkan master';
        
        if (class_exists(Audit::class)) {
            Audit::log($userId, $aksi, get_class($model), $model->id, $sebelum, $model->toArray(), $pesan);
        }

        return $model;
    }

    private function generateKode($modelClass)
    {
        $prefix = strtoupper(substr(class_basename($modelClass), 0, 3));
        $last = $modelClass::orderBy('id', 'desc')->first();
        $nextId = $last ? $last->id + 1 : 1;
        return $prefix . '-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);
    }
}