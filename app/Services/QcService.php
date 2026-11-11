<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Models\WoItem;
use App\Models\QcInspeksi;
use App\Models\QcInspeksiItem;
use App\Models\QcRepair;
use App\Models\QcInspeksiCacat;
use App\Exceptions\BusinessRuleException;
use App\Helpers\NumberingService; // Sesuaikan namespace NumberingService Anda (Helpers atau Support)
use Illuminate\Support\Facades\DB;

class QcService
{
    /**
     * Mencatat hasil inspeksi QC (100% inspeksi).
     */
    public function catatInspeksi(WorkOrder $wo, array $items, string $tanggal, int $userId)
    {
        return DB::transaction(function () use ($wo, $items, $tanggal, $userId) {
            $qc = QcInspeksi::create([
                'no_qc' => NumberingService::next('QC'),
                'wo_id' => $wo->id,
                'tanggal' => $tanggal,
                'qc_id' => $userId
            ]);

            foreach ($items as $item) {
                $woItem = WoItem::where('id', $item['wo_item_id'])->lockForUpdate()->first();
                
                $diperiksa = $item['qty_diperiksa'] ?? 0;
                $lolos = $item['qty_lolos'] ?? 0;
                $repair = $item['qty_repair'] ?? 0;
                $reject = $item['qty_reject'] ?? 0;

                // 1. Validasi: Diperiksa harus sama dengan Lolos + Repair + Reject
                if ($diperiksa !== ($lolos + $repair + $reject)) {
                    throw new BusinessRuleException('Total diperiksa harus sama dengan jumlah lolos, repair, dan reject.', 'VALIDASI_GAGAL');
                }

                // 2. Validasi Ketersediaan QC
                // Rumus: qc_tersedia = finishing + repair_selesai - qc_diperiksa
                $qcTersedia = $woItem->qty_finishing + $woItem->qty_repair_selesai - $woItem->qty_qc_diperiksa;
                
                if ($diperiksa > $qcTersedia) {
                    throw new BusinessRuleException('Qty diperiksa melebihi stok yang siap diinspeksi (maks: ' . $qcTersedia . ').', 'QTY_MELEBIHI_BATAS');
                }

                $qcItem = QcInspeksiItem::create([
                    'qc_inspeksi_id' => $qc->id,
                    'wo_item_id' => $woItem->id,
                    'qty_diperiksa' => $diperiksa,
                    'qty_lolos' => $lolos,
                    'qty_repair' => $repair,
                    'qty_reject' => $reject
                ]);

                // 3. Catat Cacat (jika ada) - Opsional, bergantung pada struktur array $item['cacat'] Anda
                if (isset($item['cacat']) && is_array($item['cacat'])) {
                    foreach ($item['cacat'] as $cacat) {
                        QcInspeksiCacat::create([
                            'qc_inspeksi_item_id' => $qcItem->id,
                            'jenis_cacat_id' => $cacat['jenis_cacat_id'],
                            'qty' => $cacat['qty'],
                            'tindakan' => $cacat['tindakan'] // 'repair' atau 'reject'
                        ]);
                    }
                }

                // 4. Jika ada repair, buat dokumen repair terbuka
                if ($repair > 0) {
                    QcRepair::create([
                        'qc_inspeksi_item_id' => $qcItem->id,
                        'wo_item_id' => $woItem->id,
                        'qty' => $repair,
                        'tahap_tujuan_id' => 2, // Default: diarahkan ke sewing (bisa dinamis sesuai request)
                        'status' => 'open'
                    ]);
                    $woItem->qty_repair_open += $repair;
                }

                // 5. Update Counter di wo_items
                $woItem->qty_qc_diperiksa += $diperiksa;
                $woItem->qty_qc_lolos += $lolos;
                $woItem->qty_qc_reject += $reject;
                $woItem->save();
            }

            return $qc;
        });
    }

    /**
     * Menyelesaikan barang yang masuk antrean repair.
     */
    public function selesaikanRepair(int $repairId, int $qtySelesai, int $userId)
    {
        return DB::transaction(function () use ($repairId, $qtySelesai, $userId) {
            $repair = QcRepair::where('id', $repairId)->lockForUpdate()->first();
            
            if (!$repair || $repair->status === 'selesai') {
                throw new BusinessRuleException('Data repair tidak valid atau sudah selesai.', 'STATUS_TIDAK_VALID');
            }

            $sisaOpen = $repair->qty - $repair->qty_selesai;
            if ($qtySelesai > $sisaOpen) {
                throw new BusinessRuleException("Qty selesai melebihi sisa repair (maks: $sisaOpen).", 'QTY_MELEBIHI_BATAS');
            }

            // Update data repair
            $repair->qty_selesai += $qtySelesai;
            if ($repair->qty_selesai == $repair->qty) {
                $repair->status = 'selesai';
                $repair->selesai_at = now();
                $repair->diselesaikan_oleh = $userId;
            }
            $repair->save();

            // Update counter di wo_items
            $woItem = WoItem::where('id', $repair->wo_item_id)->lockForUpdate()->first();
            $woItem->qty_repair_open -= $qtySelesai;
            $woItem->qty_repair_selesai += $qtySelesai;
            $woItem->save();

            return $repair;
        });
    }
}