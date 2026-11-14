<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Pemeriksaan konsistensi data (bagian 4.8 dan Batch 9).
 * Hanya membaca; perbaikan counter dilakukan lewat hitungUlangCounter().
 *
 * Setiap temuan: ['kategori' => string, 'rujukan' => string, 'pesan' => string].
 */
class IntegrityService
{
    public const KOLOM_COUNTER = [
        'qty_cutting', 'qty_cutting_reject', 'qty_sewing', 'qty_sewing_reject',
        'qty_finishing', 'qty_finishing_reject',
        'qty_qc_diperiksa', 'qty_qc_lolos', 'qty_qc_reject',
        'qty_repair_open', 'qty_repair_selesai',
        'qty_packing', 'qty_terkirim',
    ];

    /** @return array<int, array{kategori:string, rujukan:string, pesan:string}> */
    public function periksa(): array
    {
        return array_merge(
            $this->periksaStokBahan(),
            $this->periksaReservasi(),
            $this->periksaCounterWoItem(),
            $this->periksaWoAktifPerOrder(),
            $this->periksaKoliPengiriman(),
        );
    }

    /** bahan.stok harus sama dengan jumlah stok_mutasi.qty; stok_dipesan tidak boleh melebihi stok. */
    public function periksaStokBahan(): array
    {
        $temuan = [];
        $mutasi = DB::table('stok_mutasi')->groupBy('bahan_id')->selectRaw('bahan_id, SUM(qty) as total')->pluck('total', 'bahan_id');

        foreach (DB::table('bahan')->get(['id', 'kode', 'stok', 'stok_dipesan']) as $bahan) {
            $harapan = round((float) ($mutasi[$bahan->id] ?? 0), 3);
            if (abs((float) $bahan->stok - $harapan) > 0.0005) {
                $temuan[] = $this->temuan('stok', "bahan {$bahan->kode}", sprintf('stok tersimpan %s, jumlah mutasi %s', $this->angka($bahan->stok), $this->angka($harapan)));
            }
            if ((float) $bahan->stok_dipesan > (float) $bahan->stok + 0.0005) {
                $temuan[] = $this->temuan('stok', "bahan {$bahan->kode}", sprintf('stok dipesan %s melebihi stok %s', $this->angka($bahan->stok_dipesan), $this->angka($bahan->stok)));
            }
        }

        return $temuan;
    }

    /** bahan.stok_dipesan harus sama dengan jumlah max(0, reservasi - keluar) pada WO aktif. */
    public function periksaReservasi(): array
    {
        $harapan = [];
        $baris = DB::table('wo_bahan as wb')
            ->join('work_orders as wo', 'wo.id', '=', 'wb.wo_id')
            ->where('wo.status', 'aktif')
            ->get(['wb.bahan_id', 'wb.qty_reservasi', 'wb.qty_keluar']);
        foreach ($baris as $r) {
            $harapan[$r->bahan_id] = ($harapan[$r->bahan_id] ?? 0) + max(0, (float) $r->qty_reservasi - (float) $r->qty_keluar);
        }

        $temuan = [];
        foreach (DB::table('bahan')->get(['id', 'kode', 'stok_dipesan']) as $bahan) {
            $seharusnya = round((float) ($harapan[$bahan->id] ?? 0), 3);
            if (abs((float) $bahan->stok_dipesan - $seharusnya) > 0.0005) {
                $temuan[] = $this->temuan('reservasi', "bahan {$bahan->kode}", sprintf('stok dipesan %s, reservasi WO aktif %s', $this->angka($bahan->stok_dipesan), $this->angka($seharusnya)));
            }
        }

        return $temuan;
    }

    /** Counter wo_items harus sama dengan agregat dari log, inspeksi, repair, dan koli. */
    public function periksaCounterWoItem(?int $woId = null): array
    {
        $harapan = $this->counterHarapan($woId);
        $temuan = [];

        $items = DB::table('wo_items as wi')
            ->join('work_orders as wo', 'wo.id', '=', 'wi.wo_id')
            ->when($woId, fn ($q) => $q->where('wi.wo_id', $woId))
            ->get(array_merge(['wi.id', 'wo.no_wo'], array_map(fn ($k) => "wi.{$k}", self::KOLOM_COUNTER)));

        foreach ($items as $item) {
            foreach (self::KOLOM_COUNTER as $kolom) {
                $seharusnya = $harapan[$item->id][$kolom] ?? 0;
                if ((int) $item->{$kolom} !== (int) $seharusnya) {
                    $temuan[] = $this->temuan('counter', "{$item->no_wo} item #{$item->id}", "{$kolom}: tersimpan {$item->{$kolom}}, seharusnya {$seharusnya}");
                }
            }
        }

        return $temuan;
    }

    /** Satu WO aktif per order. */
    public function periksaWoAktifPerOrder(): array
    {
        return DB::table('work_orders as wo')
            ->join('orders as o', 'o.id', '=', 'wo.order_id')
            ->where('wo.status', 'aktif')
            ->groupBy('wo.order_id', 'o.no_order')
            ->havingRaw('COUNT(*) > 1')
            ->selectRaw('o.no_order as no_order, COUNT(*) as jumlah')
            ->get()
            ->map(fn ($r) => $this->temuan('wo', "order {$r->no_order}", "{$r->jumlah} WO aktif (seharusnya satu)"))
            ->all();
    }

    /** Satu koli hanya di satu pengiriman aktif, dan status koli sesuai status pengirimannya. */
    public function periksaKoliPengiriman(): array
    {
        $temuan = [];

        $ganda = DB::table('pengiriman_koli as pk')
            ->join('pengiriman as sj', 'sj.id', '=', 'pk.pengiriman_id')
            ->join('packing as p', 'p.id', '=', 'pk.packing_id')
            ->whereIn('sj.status', ['disiapkan', 'dikirim', 'diterima'])
            ->groupBy('pk.packing_id', 'p.no_koli')
            ->havingRaw('COUNT(DISTINCT pk.pengiriman_id) > 1')
            ->selectRaw('p.no_koli as no_koli, COUNT(DISTINCT pk.pengiriman_id) as jumlah')
            ->get();
        foreach ($ganda as $r) {
            $temuan[] = $this->temuan('koli', "koli {$r->no_koli}", "ada di {$r->jumlah} pengiriman aktif");
        }

        $dalamTanpaSj = DB::table('packing as p')
            ->where('p.status', 'dalam_pengiriman')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('pengiriman_koli as pk')
                    ->join('pengiriman as sj', 'sj.id', '=', 'pk.pengiriman_id')
                    ->whereColumn('pk.packing_id', 'p.id')
                    ->where('sj.status', 'disiapkan');
            })
            ->pluck('p.no_koli');
        foreach ($dalamTanpaSj as $noKoli) {
            $temuan[] = $this->temuan('koli', "koli {$noKoli}", 'berstatus dalam_pengiriman tetapi tidak ada di surat jalan disiapkan');
        }

        $terkirimTanpaSj = DB::table('packing as p')
            ->where('p.status', 'terkirim')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('pengiriman_koli as pk')
                    ->join('pengiriman as sj', 'sj.id', '=', 'pk.pengiriman_id')
                    ->whereColumn('pk.packing_id', 'p.id')
                    ->whereIn('sj.status', ['dikirim', 'diterima']);
            })
            ->pluck('p.no_koli');
        foreach ($terkirimTanpaSj as $noKoli) {
            $temuan[] = $this->temuan('koli', "koli {$noKoli}", 'berstatus terkirim tetapi tidak ada di surat jalan dikirim/diterima');
        }

        return $temuan;
    }

    /**
     * Counter yang seharusnya untuk setiap wo_item, dihitung dari data sumber.
     *
     * @return array<int, array<string,int>> wo_item_id => [kolom => nilai]
     */
    public function counterHarapan(?int $woId = null): array
    {
        $ids = DB::table('wo_items')->when($woId, fn ($q) => $q->where('wo_id', $woId))->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $harapan = array_fill_keys($ids, array_fill_keys(self::KOLOM_COUNTER, 0));
        $punya = array_flip($ids);

        // Hasil dan reject per tahap dari log produksi aktif
        $log = DB::table('produksi_log as pl')
            ->join('tahap as t', 't.id', '=', 'pl.tahap_id')
            ->whereIn('pl.wo_item_id', $ids)
            ->where('pl.status', 'aktif')
            ->groupBy('pl.wo_item_id', 't.kode')
            ->selectRaw('pl.wo_item_id as id, t.kode as kode, SUM(pl.qty_hasil) as hasil, SUM(pl.qty_reject) as reject')
            ->get();
        foreach ($log as $r) {
            if (! isset($punya[$r->id]) || ! in_array($r->kode, ['cutting', 'sewing', 'finishing'], true)) {
                continue;
            }
            $harapan[$r->id]["qty_{$r->kode}"] += (int) $r->hasil;
            $harapan[$r->id]["qty_{$r->kode}_reject"] += (int) $r->reject;
        }

        // Inspeksi QC aktif
        $qc = DB::table('qc_inspeksi_items as qii')
            ->join('qc_inspeksi as qi', 'qi.id', '=', 'qii.qc_inspeksi_id')
            ->whereIn('qii.wo_item_id', $ids)
            ->where('qi.status', 'aktif')
            ->groupBy('qii.wo_item_id')
            ->selectRaw('qii.wo_item_id as id, SUM(qii.qty_diperiksa) as diperiksa, SUM(qii.qty_lolos) as lolos, SUM(qii.qty_reject) as reject')
            ->get();
        foreach ($qc as $r) {
            if (! isset($punya[$r->id])) {
                continue;
            }
            $harapan[$r->id]['qty_qc_diperiksa'] = (int) $r->diperiksa;
            $harapan[$r->id]['qty_qc_lolos'] = (int) $r->lolos;
            $harapan[$r->id]['qty_qc_reject'] = (int) $r->reject;
        }

        // Repair (baris repair milik inspeksi yang di-void sudah dihapus saat void)
        $repair = DB::table('qc_repair')
            ->whereIn('wo_item_id', $ids)
            ->groupBy('wo_item_id')
            ->selectRaw('wo_item_id as id, SUM(CAST(qty AS SIGNED) - CAST(qty_selesai AS SIGNED)) as buka, SUM(qty_selesai) as selesai')
            ->get();
        foreach ($repair as $r) {
            if (! isset($punya[$r->id])) {
                continue;
            }
            $harapan[$r->id]['qty_repair_open'] = (int) $r->buka;
            $harapan[$r->id]['qty_repair_selesai'] = (int) $r->selesai;
        }

        // Koli: dikemas (selain void) dan terkirim
        $koli = DB::table('packing_items as pi')
            ->join('packing as p', 'p.id', '=', 'pi.packing_id')
            ->whereIn('pi.wo_item_id', $ids)
            ->where('p.status', '!=', 'void')
            ->groupBy('pi.wo_item_id')
            ->selectRaw("pi.wo_item_id as id, SUM(pi.qty) as total, SUM(CASE WHEN p.status = 'terkirim' THEN pi.qty ELSE 0 END) as kirim")
            ->get();
        foreach ($koli as $r) {
            if (! isset($punya[$r->id])) {
                continue;
            }
            $harapan[$r->id]['qty_packing'] = (int) $r->total;
            $harapan[$r->id]['qty_terkirim'] = (int) $r->kirim;
        }

        return $harapan;
    }

    /**
     * Menulis ulang counter wo_items dari data sumber.
     *
     * @return array<int, array{rujukan:string, perubahan:array<string,array{dari:int,ke:int}>}>
     */
    public function hitungUlangCounter(?int $woId = null, bool $dryRun = false): array
    {
        $laporan = [];

        DB::transaction(function () use ($woId, $dryRun, &$laporan) {
            // Kunci item dahulu agar input produksi/QC/packing tidak mengubah sumber
            // ketika agregat dihitung ulang. Semua service transaksi terkait juga lock wo_items.
            $items = DB::table('wo_items as wi')
                ->join('work_orders as wo', 'wo.id', '=', 'wi.wo_id')
                ->when($woId, fn ($q) => $q->where('wi.wo_id', $woId))
                ->orderBy('wi.id')
                ->lockForUpdate()
                ->get(array_merge(['wi.id', 'wo.no_wo'], array_map(fn ($k) => "wi.{$k}", self::KOLOM_COUNTER)));

            $harapan = $this->counterHarapan($woId);

            foreach ($items as $item) {
                $perubahan = [];
                foreach (self::KOLOM_COUNTER as $kolom) {
                    $ke = (int) ($harapan[$item->id][$kolom] ?? 0);
                    if ((int) $item->{$kolom} !== $ke) {
                        $perubahan[$kolom] = ['dari' => (int) $item->{$kolom}, 'ke' => $ke];
                    }
                }

                if ($perubahan === []) {
                    continue;
                }

                if (! $dryRun) {
                    DB::table('wo_items')->where('id', $item->id)->update(array_map(fn ($p) => $p['ke'], $perubahan) + ['updated_at' => now()]);
                }

                $laporan[$item->id] = ['rujukan' => "{$item->no_wo} item #{$item->id}", 'perubahan' => $perubahan];
            }
        });

        return $laporan;
    }

    private function temuan(string $kategori, string $rujukan, string $pesan): array
    {
        return ['kategori' => $kategori, 'rujukan' => $rujukan, 'pesan' => $pesan];
    }

    private function angka(mixed $nilai): string
    {
        return rtrim(rtrim(number_format((float) $nilai, 3, '.', ''), '0'), '.') ?: '0';
    }
}