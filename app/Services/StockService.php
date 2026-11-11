<?php

namespace App\Services;

use App\Models\Bahan;
use App\Models\StokMutasi;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StockService
{
    /**
     * Fungsi sentral untuk mengubah stok bahan.
     * HARUS dipanggil di dalam DB::transaction dari controller/service lain.
     *
     * @param int $bahanId
     * @param string $tipe ('masuk', 'keluar', 'retur', 'penyesuaian', 'koreksi')
     * @param float $qty (Gunakan nilai negatif untuk barang keluar/pengurangan)
     * @param string $refTipe ('penerimaan', 'pengeluaran', 'stok_opname', 'manual', 'mutasi')
     * @param int|null $refId ID dokumen referensi
     * @param string|null $keterangan
     * @param int $userId
     */
    public function mutasi(int $bahanId, string $tipe, float $qty, string $refTipe, ?int $refId, ?string $keterangan, int $userId)
    {
        if ($qty == 0) {
            return; // Tidak ada mutasi jika qty 0
        }

        // Lock baris bahan agar aman dari *race condition*
        $bahan = Bahan::lockForUpdate()->findOrFail($bahanId);
        
        $stokSebelum = $bahan->stok;
        // Gunakan pembulatan 3 desimal untuk menghindari error floating point
        $stokSesudah = round($stokSebelum + $qty, 3);

        // Validasi aturan bisnis kritis
        if ($stokSesudah < 0) {
            throw new BusinessRuleException("Stok akhir {$bahan->kode} tidak boleh negatif.", 'STOK_TIDAK_CUKUP', 422);
        }
        if ($stokSesudah < $bahan->stok_dipesan) {
            throw new BusinessRuleException("Stok {$bahan->kode} tidak boleh turun di bawah jumlah reservasi aktif ({$bahan->stok_dipesan}). Lepaskan reservasi terlebih dahulu.", 'STOK_DI_BAWAH_RESERVASI', 422);
        }

        // Update saldo stok di master bahan
        $bahan->stok = $stokSesudah;
        $bahan->save();

        // Catat riwayat pergerakan (Append-only)
        StokMutasi::create([
            'bahan_id' => $bahanId,
            'tanggal' => Carbon::now(),
            'tipe' => $tipe,
            'qty' => $qty,
            'stok_sebelum' => $stokSebelum,
            'stok_sesudah' => $stokSesudah,
            'ref_tipe' => $refTipe,
            'ref_id' => $refId,
            'keterangan' => $keterangan,
            'user_id' => $userId,
        ]);
    }
}