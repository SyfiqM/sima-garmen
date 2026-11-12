<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\User;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard per role dan 16 laporan (bagian 7 rancangan, Batch 8).
 *
 * Aturan:
 *  - Service ini hanya MEMBACA data. Tidak ada perubahan stok, counter, atau status.
 *  - Semua angka KPI dihitung di sini (satu tempat) agar dashboard dan laporan konsisten.
 *  - Counter wo_items bertipe INT UNSIGNED, jadi selisih (rumus ketersediaan) dihitung di PHP,
 *    bukan di SQL, supaya MySQL tidak melempar "BIGINT UNSIGNED value is out of range".
 *  - Laporan untuk supervisor dibatasi ke tahapnya: kolom tahap lain dan kolom hilir (QC, kirim)
 *    dibuang dari JSON, PDF, maupun Excel.
 */
class ReportService
{
    /** Batas baris yang diambil per laporan (JSON dan Excel). */
    public const MAKS_BARIS = 20000;

    /** Batas baris pada PDF (dompdf berat untuk tabel besar). */
    public const MAKS_BARIS_PDF = 1500;

    private const GRUP_ADMIN = ['admin', 'manajer', 'pimpinan'];

    /** Jenis laporan: label dan role yang berhak (matriks bagian 3.2 rancangan). */
    public const JENIS = [
        'order' => ['label' => 'Laporan Order', 'roles' => ['admin', 'manajer', 'pimpinan', 'staf_order']],
        'progres-wo' => ['label' => 'Progres Order dan WO', 'roles' => ['admin', 'manajer', 'pimpinan', 'supervisor']],
        'produksi-harian' => ['label' => 'Produksi Harian', 'roles' => ['admin', 'manajer', 'pimpinan', 'supervisor']],
        'wip' => ['label' => 'WIP (Barang dalam Proses)', 'roles' => ['admin', 'manajer', 'pimpinan', 'supervisor']],
        'reject' => ['label' => 'Analisis Reject', 'roles' => ['admin', 'manajer', 'pimpinan', 'supervisor', 'qc']],
        'repair' => ['label' => 'Laporan Repair', 'roles' => ['admin', 'manajer', 'pimpinan', 'supervisor', 'qc']],
        'stok-bahan' => ['label' => 'Stok Bahan', 'roles' => ['admin', 'manajer', 'pimpinan', 'gudang']],
        'kartu-stok' => ['label' => 'Kartu Stok', 'roles' => ['admin', 'manajer', 'pimpinan', 'gudang']],
        'kebutuhan-vs-stok' => ['label' => 'Kebutuhan Bahan vs Stok', 'roles' => ['admin', 'manajer', 'pimpinan', 'gudang']],
        'pemakaian-bahan' => ['label' => 'Pemakaian Bahan (Rencana vs Aktual)', 'roles' => ['admin', 'manajer', 'pimpinan', 'gudang']],
        'pembelian' => ['label' => 'Laporan Pembelian', 'roles' => ['admin', 'manajer', 'pimpinan', 'gudang']],
        'penerimaan' => ['label' => 'Laporan Penerimaan Bahan', 'roles' => ['admin', 'manajer', 'pimpinan', 'gudang']],
        'pengiriman' => ['label' => 'Laporan Pengiriman', 'roles' => ['admin', 'manajer', 'pimpinan', 'staf_order', 'gudang']],
        'on-time-delivery' => ['label' => 'On-Time Delivery', 'roles' => ['admin', 'manajer', 'pimpinan']],
        'opname' => ['label' => 'Laporan Stok Opname', 'roles' => ['admin', 'manajer', 'pimpinan', 'gudang']],
        'audit-log' => ['label' => 'Audit Log', 'roles' => ['admin', 'manajer']],
    ];

    private const KOLOM_ITEM = [
        'wi.id as item_id', 'wi.wo_id', 'wi.qty_order', 'wi.qty_batas_potong',
        'wi.qty_cutting', 'wi.qty_cutting_reject', 'wi.qty_sewing', 'wi.qty_sewing_reject',
        'wi.qty_finishing', 'wi.qty_finishing_reject', 'wi.qty_qc_diperiksa', 'wi.qty_qc_lolos',
        'wi.qty_qc_reject', 'wi.qty_repair_open', 'wi.qty_repair_selesai', 'wi.qty_packing', 'wi.qty_terkirim',
    ];

    // =====================================================================
    // HAK AKSES LAPORAN
    // =====================================================================

    /** Daftar jenis laporan yang boleh dibuka user. */
    public function daftarUntuk(User $user): array
    {
        $hasil = [];
        foreach (self::JENIS as $kode => $def) {
            if ($this->boleh($user, $kode)) {
                $hasil[] = ['jenis' => $kode, 'label' => $def['label']];
            }
        }

        return $hasil;
    }

    public function boleh(User $user, string $jenis): bool
    {
        $roles = self::JENIS[$jenis]['roles'] ?? [];

        return $user->hasRole(...$roles);
    }

    // =====================================================================
    // HELPER UMUM
    // =====================================================================

    /** Persentase aman: pembagi nol menghasilkan 0, hasil dibulatkan 2 desimal, maksimal 100. */
    public function persen(float|int|string|null $pembilang, float|int|string|null $penyebut, bool $batas100 = true): float
    {
        $penyebut = (float) $penyebut;
        if ($penyebut <= 0) {
            return 0.0;
        }

        $nilai = round(((float) $pembilang) / $penyebut * 100, 2);

        return $batas100 ? min(100.0, $nilai) : $nilai;
    }

    /** Reject rate (%) = reject / (hasil + reject) x 100. */
    public function rejectRate(int|float|string|null $hasil, int|float|string|null $reject): float
    {
        return $this->persen($reject, (float) $hasil + (float) $reject, false);
    }

    protected function statusAktif(): array
    {
        return array_map(fn (OrderStatus $s) => $s->value, OrderStatus::aktif());
    }

    protected function hari(string $tanggal): int
    {
        return (int) floor(strtotime($tanggal) / 86400);
    }

    protected function kodeTahapUser(User $user): ?string
    {
        if (! $user->tahap_id) {
            return null;
        }

        return DB::table('tahap')->where('id', $user->tahap_id)->value('kode');
    }

    /**
     * Angka antrean per item (rumus ketersediaan bagian 4.8). Dihitung di PHP.
     *
     * @return array<string,int>
     */
    protected function antrean(object $i): array
    {
        $cuttingBoleh = $i->qty_batas_potong - ($i->qty_cutting + $i->qty_cutting_reject);
        $sewing = $i->qty_cutting - ($i->qty_sewing + $i->qty_sewing_reject);
        $finishing = $i->qty_sewing - ($i->qty_finishing + $i->qty_finishing_reject);
        $qc = $i->qty_finishing + $i->qty_repair_selesai - $i->qty_qc_diperiksa;
        $packing = min($i->qty_qc_lolos, $i->qty_order) - $i->qty_packing;

        return [
            'cutting_boleh' => max(0, (int) $cuttingBoleh),
            'sewing_tersedia' => max(0, (int) $sewing),
            'finishing_tersedia' => max(0, (int) $finishing),
            'qc_tersedia' => max(0, (int) $qc),
            'repair_open' => (int) $i->qty_repair_open,
            'packing_tersedia' => max(0, (int) $packing),
            'kirim_tersedia' => max(0, (int) ($i->qty_packing - $i->qty_terkirim)),
        ];
    }

    /** Query dasar item WO lengkap dengan order, pelanggan, produk, warna, dan ukuran. */
    protected function itemQuery()
    {
        return DB::table('wo_items as wi')
            ->join('work_orders as wo', 'wo.id', '=', 'wi.wo_id')
            ->join('orders as o', 'o.id', '=', 'wo.order_id')
            ->join('pelanggan as p', 'p.id', '=', 'o.pelanggan_id')
            ->join('order_items as oi', 'oi.id', '=', 'wi.order_item_id')
            ->join('produk as pr', 'pr.id', '=', 'oi.produk_id')
            ->join('warna as w', 'w.id', '=', 'oi.warna_id')
            ->join('ukuran as u', 'u.id', '=', 'oi.ukuran_id')
            ->whereNull('o.deleted_at');
    }

    /** Order berstatus terkirim yang pengiriman terakhirnya jatuh di periode. */
    protected function orderTerkirimPeriode(string $dari, string $sampai)
    {
        $terakhir = DB::table('pengiriman')
            ->whereIn('status', ['dikirim', 'diterima'])
            ->whereNotNull('tgl_kirim')
            ->groupBy('order_id')
            ->selectRaw('order_id, MAX(tgl_kirim) as tgl_terkirim');

        return DB::table('orders as o')
            ->joinSub($terakhir, 'pt', 'pt.order_id', '=', 'o.id')
            ->join('pelanggan as p', 'p.id', '=', 'o.pelanggan_id')
            ->whereNull('o.deleted_at')
            ->where('o.status', 'terkirim')
            ->whereBetween('pt.tgl_terkirim', [$dari, $sampai]);
    }

    /** KPI on-time delivery dari baris order terkirim (tgl_terkirim <= deadline = tepat waktu). */
    public function hitungOnTime(Collection $rows): array
    {
        $total = $rows->count();
        $tepat = $rows->filter(fn ($r) => $r->tgl_terkirim <= $r->deadline)->count();

        return [
            'total_order_terkirim' => $total,
            'tepat_waktu' => $tepat,
            'terlambat' => $total - $tepat,
            'persen' => $this->persen($tepat, $total),
        ];
    }

    protected function bahanDiBawahMinimum(int $limit = 10): array
    {
        $q = DB::table('bahan as b')
            ->join('satuan as s', 's.id', '=', 'b.satuan_id')
            ->whereNull('b.deleted_at')
            ->where('b.is_active', 1)
            ->where('b.stok_minimum', '>', 0)
            ->whereRaw('(b.stok - b.stok_dipesan) <= b.stok_minimum');

        $total = (clone $q)->count();
        $daftar = $q->orderByRaw('(b.stok - b.stok_dipesan) - b.stok_minimum')
            ->limit($limit)
            ->get(['b.id', 'b.kode', 'b.nama', 's.kode as satuan', 'b.stok', 'b.stok_dipesan', 'b.stok_minimum'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'kode' => $r->kode,
                'nama' => $r->nama,
                'satuan' => $r->satuan,
                'stok' => (float) $r->stok,
                'stok_dipesan' => (float) $r->stok_dipesan,
                'stok_tersedia' => round((float) $r->stok - (float) $r->stok_dipesan, 3),
                'stok_minimum' => (float) $r->stok_minimum,
            ])->values()->all();

        return ['total' => $total, 'daftar' => $daftar];
    }

    /** Rumus kekurangan bahan (bagian 4.6), sama dengan GET /kekurangan-bahan. */
    protected function kekuranganBahan(): Collection
    {
        $kebutuhan = DB::table('wo_bahan as wb')
            ->join('work_orders as wo', 'wo.id', '=', 'wb.wo_id')
            ->join('orders as o', 'o.id', '=', 'wo.order_id')
            ->where('wo.status', 'aktif')
            ->where('o.status', 'menunggu_bahan')
            ->whereRaw('wb.qty_rencana > wb.qty_reservasi')
            ->groupBy('wb.bahan_id')
            ->selectRaw('wb.bahan_id, SUM(wb.qty_rencana - wb.qty_reservasi) as total')
            ->get()
            ->keyBy('bahan_id');

        $transit = $this->poTransit();
        $bahan = DB::table('bahan as b')
            ->join('satuan as s', 's.id', '=', 'b.satuan_id')
            ->whereIn('b.id', $kebutuhan->keys()->all())
            ->get(['b.id', 'b.kode', 'b.nama', 's.kode as satuan'])
            ->keyBy('id');

        $hasil = collect();
        foreach ($kebutuhan as $bahanId => $k) {
            $dalamPerjalanan = (float) ($transit[$bahanId] ?? 0);
            $kurang = round((float) $k->total - $dalamPerjalanan, 3);
            if ($kurang <= 0 || ! isset($bahan[$bahanId])) {
                continue;
            }

            $hasil->push([
                'bahan_id' => (int) $bahanId,
                'kode' => $bahan[$bahanId]->kode,
                'nama' => $bahan[$bahanId]->nama,
                'satuan' => $bahan[$bahanId]->satuan,
                'kebutuhan' => round((float) $k->total, 3),
                'po_dalam_perjalanan' => $dalamPerjalanan,
                'kekurangan' => $kurang,
            ]);
        }

        return $hasil->sortByDesc('kekurangan')->values();
    }

    /** qty PO dalam perjalanan per bahan (dipesan atau diterima_sebagian). */
    protected function poTransit(): array
    {
        return DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.po_id')
            ->whereIn('po.status', ['dipesan', 'diterima_sebagian'])
            ->groupBy('poi.bahan_id')
            ->selectRaw('poi.bahan_id, SUM(poi.qty_pesan - poi.qty_diterima) as total')
            ->pluck('total', 'bahan_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    // =====================================================================
    // DASHBOARD
    // =====================================================================

    public function dashboard(User $user): array
    {
        return match (true) {
            $user->hasRole(...self::GRUP_ADMIN) => $this->dashboardEksekutif(),
            $user->hasRole('staf_order') => $this->dashboardStafOrder($user),
            $user->hasRole('gudang') => $this->dashboardGudang(),
            $user->hasRole('supervisor') => $this->dashboardSupervisor($user),
            $user->hasRole('qc') => $this->dashboardQc(),
            default => abort(403),
        };
    }

    protected function barisOrderRingkas(object $r, string $hariIni): array
    {
        $selisih = $this->hari($hariIni) - $this->hari($r->deadline);

        return [
            'id' => (int) $r->id,
            'no_order' => $r->no_order,
            'pelanggan' => $r->pelanggan,
            'deadline' => $r->deadline,
            'status' => $r->status,
            'prioritas' => $r->prioritas,
            'hari_terlambat' => max(0, $selisih),
            'sisa_hari' => max(0, -$selisih),
        ];
    }

    protected function dashboardEksekutif(): array
    {
        $hari = today();
        $hariIni = $hari->toDateString();
        $awalBulan = $hari->copy()->startOfMonth()->toDateString();
        $akhirBulan = $hari->copy()->endOfMonth()->toDateString();
        $alertHari = Setting::int('deadline_alert_hari', 3);
        $aktif = $this->statusAktif();

        $orderAktif = fn () => DB::table('orders as o')
            ->join('pelanggan as p', 'p.id', '=', 'o.pelanggan_id')
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', $aktif);

        $perStatus = DB::table('orders')
            ->whereNull('deleted_at')
            ->whereIn('status', $aktif)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as jumlah')
            ->pluck('jumlah', 'status');

        $orderPerStatus = collect($aktif)->map(fn (string $s) => [
            'status' => $s,
            'label' => OrderStatus::from($s)->label(),
            'jumlah' => (int) ($perStatus[$s] ?? 0),
        ])->values()->all();

        $kolomRingkas = ['o.id', 'o.no_order', 'p.nama as pelanggan', 'o.deadline', 'o.status', 'o.prioritas'];

        $terlambat = $orderAktif()->where('o.deadline', '<', $hariIni);
        $terlambatTotal = (clone $terlambat)->count();
        $terlambatDaftar = $terlambat->orderBy('o.deadline')->limit(10)->get($kolomRingkas)
            ->map(fn ($r) => $this->barisOrderRingkas($r, $hariIni))->values()->all();

        $mendekati = $orderAktif()
            ->where('o.deadline', '>=', $hariIni)
            ->where('o.deadline', '<=', $hari->copy()->addDays($alertHari)->toDateString());
        $mendekatiTotal = (clone $mendekati)->count();
        $mendekatiDaftar = $mendekati->orderBy('o.deadline')->limit(10)->get($kolomRingkas)
            ->map(fn ($r) => $this->barisOrderRingkas($r, $hariIni))->values()->all();

        // Progres dan WIP dari item WO aktif
        $items = $this->itemQuery()->where('wo.status', 'aktif')->get(self::KOLOM_ITEM);
        $totalOrder = $items->sum('qty_order');
        $cuttingTotal = $items->sum('qty_cutting') + $items->sum('qty_cutting_reject');

        $progres = [
            'qty_order' => (int) $totalOrder,
            'cutting' => $this->persen($items->sum('qty_cutting'), $totalOrder),
            'sewing' => $this->persen($items->sum('qty_sewing'), $totalOrder),
            'finishing' => $this->persen($items->sum('qty_finishing'), $totalOrder),
            'packing' => $this->persen($items->sum('qty_packing'), $totalOrder),
            'kirim' => $this->persen($items->sum('qty_terkirim'), $totalOrder),
        ];

        $wip = ['sewing_tersedia' => 0, 'finishing_tersedia' => 0, 'qc_tersedia' => 0, 'repair_open' => 0, 'packing_tersedia' => 0];
        foreach ($items as $i) {
            $a = $this->antrean($i);
            foreach (array_keys($wip) as $k) {
                $wip[$k] += $a[$k];
            }
        }

        // Output 7 hari terakhir per tahap
        $dari7 = $hari->copy()->subDays(6)->toDateString();
        $output = DB::table('produksi_log as pl')
            ->join('tahap as t', 't.id', '=', 'pl.tahap_id')
            ->where('pl.status', 'aktif')
            ->whereBetween('pl.tanggal', [$dari7, $hariIni])
            ->groupBy('pl.tanggal', 't.kode')
            ->selectRaw('pl.tanggal as tgl, t.kode as kode, SUM(pl.qty_hasil) as hasil')
            ->get();
        $peta = [];
        foreach ($output as $o) {
            $peta[Carbon::parse($o->tgl)->toDateString().'|'.$o->kode] = (int) $o->hasil;
        }
        $output7 = [];
        for ($d = 6; $d >= 0; $d--) {
            $tgl = $hari->copy()->subDays($d)->toDateString();
            $output7[] = [
                'tanggal' => $tgl,
                'cutting' => $peta[$tgl.'|cutting'] ?? 0,
                'sewing' => $peta[$tgl.'|sewing'] ?? 0,
                'finishing' => $peta[$tgl.'|finishing'] ?? 0,
            ];
        }

        // Reject rate per tahap, bulan berjalan
        $rejectRows = DB::table('produksi_log as pl')
            ->join('tahap as t', 't.id', '=', 'pl.tahap_id')
            ->where('pl.status', 'aktif')
            ->whereBetween('pl.tanggal', [$awalBulan, $akhirBulan])
            ->groupBy('t.kode')
            ->selectRaw('t.kode as kode, SUM(pl.qty_hasil) as hasil, SUM(pl.qty_reject) as reject')
            ->get()
            ->keyBy('kode');
        $rejectRate = [];
        foreach (['cutting', 'sewing', 'finishing'] as $kode) {
            $row = $rejectRows->get($kode);
            $h = (int) ($row->hasil ?? 0);
            $r = (int) ($row->reject ?? 0);
            $rejectRate[] = ['tahap' => $kode, 'hasil' => $h, 'reject' => $r, 'reject_rate' => $this->rejectRate($h, $r)];
        }

        $onTime = $this->hitungOnTime(
            $this->orderTerkirimPeriode($awalBulan, $akhirBulan)->get(['o.id', 'o.deadline', 'pt.tgl_terkirim'])
        );

        return [
            'tipe' => 'eksekutif',
            'periode_bulan' => ['tgl_dari' => $awalBulan, 'tgl_sampai' => $akhirBulan],
            'order_aktif_per_status' => $orderPerStatus,
            'order_terlambat' => ['total' => $terlambatTotal, 'daftar' => $terlambatDaftar],
            'order_mendekati_deadline' => ['hari' => $alertHari, 'total' => $mendekatiTotal, 'daftar' => $mendekatiDaftar],
            'progres_tahap' => $progres,
            'wip' => $wip,
            'yield_produksi' => $this->persen($items->sum('qty_packing'), $cuttingTotal),
            'output_7_hari' => $output7,
            'reject_rate' => $rejectRate,
            'stok_di_bawah_minimum' => $this->bahanDiBawahMinimum(),
            'order_menunggu_persetujuan' => DB::table('orders')->whereNull('deleted_at')->where('status', 'diajukan')->count(),
            'on_time_delivery_bulan_ini' => $onTime,
        ];
    }

    protected function dashboardStafOrder(User $user): array
    {
        $hari = today();
        $hariIni = $hari->toDateString();
        $alertHari = Setting::int('deadline_alert_hari', 3);

        $milikku = fn () => DB::table('orders as o')
            ->join('pelanggan as p', 'p.id', '=', 'o.pelanggan_id')
            ->whereNull('o.deleted_at')
            ->where('o.dibuat_oleh', $user->id);

        $perStatus = DB::table('orders')
            ->whereNull('deleted_at')
            ->where('dibuat_oleh', $user->id)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as jumlah')
            ->pluck('jumlah', 'status');
        $orderSaya = collect(OrderStatus::cases())->map(fn (OrderStatus $s) => [
            'status' => $s->value,
            'label' => $s->label(),
            'jumlah' => (int) ($perStatus[$s->value] ?? 0),
        ])->values()->all();

        $kolom = ['o.id', 'o.no_order', 'p.nama as pelanggan', 'o.deadline', 'o.status', 'o.prioritas', 'o.total_qty'];
        $aktifList = $milikku()->whereIn('o.status', $this->statusAktif())
            ->orderBy('o.deadline')->limit(15)->get($kolom);

        $progres = DB::table('wo_items as wi')
            ->join('work_orders as wo', 'wo.id', '=', 'wi.wo_id')
            ->whereIn('wo.order_id', $aktifList->pluck('id')->all())
            ->where('wo.status', '!=', 'dibatalkan')
            ->groupBy('wo.order_id')
            ->selectRaw('wo.order_id as order_id, SUM(wi.qty_order) as qo, SUM(wi.qty_cutting) as c, SUM(wi.qty_sewing) as s, SUM(wi.qty_finishing) as f, SUM(wi.qty_terkirim) as k')
            ->get()
            ->keyBy('order_id');

        $orderAktif = $aktifList->map(function ($r) use ($progres, $hariIni) {
            $p = $progres->get($r->id);

            return $this->barisOrderRingkas($r, $hariIni) + [
                'total_qty' => (int) $r->total_qty,
                'progres' => [
                    'cutting' => $this->persen($p->c ?? 0, $p->qo ?? 0),
                    'sewing' => $this->persen($p->s ?? 0, $p->qo ?? 0),
                    'finishing' => $this->persen($p->f ?? 0, $p->qo ?? 0),
                    'kirim' => $this->persen($p->k ?? 0, $p->qo ?? 0),
                ],
            ];
        })->values()->all();

        $mendekati = $milikku()->whereIn('o.status', $this->statusAktif())
            ->where('o.deadline', '>=', $hariIni)
            ->where('o.deadline', '<=', $hari->copy()->addDays($alertHari)->toDateString())
            ->orderBy('o.deadline')->limit(10)->get($kolom)
            ->map(fn ($r) => $this->barisOrderRingkas($r, $hariIni))->values()->all();

        $pengiriman = DB::table('pengiriman as sj')
            ->join('orders as o', 'o.id', '=', 'sj.order_id')
            ->where('o.dibuat_oleh', $user->id)
            ->orderByDesc('sj.id')
            ->limit(10)
            ->get(['sj.id', 'sj.no_sj', 'o.no_order', 'sj.status', 'sj.tgl_kirim', 'sj.tgl_diterima'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'no_sj' => $r->no_sj,
                'no_order' => $r->no_order,
                'status' => $r->status,
                'tgl_kirim' => $r->tgl_kirim,
                'tgl_diterima' => $r->tgl_diterima,
            ])->values()->all();

        return [
            'tipe' => 'staf_order',
            'order_saya_per_status' => $orderSaya,
            'order_aktif' => $orderAktif,
            'deadline_mendekati' => ['hari' => $alertHari, 'daftar' => $mendekati],
            'pengiriman_terbaru' => $pengiriman,
        ];
    }

    protected function dashboardGudang(): array
    {
        $hariIni = today()->toDateString();
        $kekurangan = $this->kekuranganBahan();

        $po = DB::table('purchase_orders as po')
            ->join('supplier as s', 's.id', '=', 'po.supplier_id')
            ->whereIn('po.status', ['dipesan', 'diterima_sebagian']);
        $poTotal = (clone $po)->count();
        $poDaftar = $po->orderBy('po.tgl_estimasi_tiba')->orderBy('po.id')->limit(10)
            ->get(['po.id', 'po.no_po', 's.nama as supplier', 'po.status', 'po.tgl_po', 'po.tgl_estimasi_tiba'])
            ->map(fn ($r) => (array) $r)->values()->all();

        $woSiap = DB::table('work_orders as wo')
            ->join('orders as o', 'o.id', '=', 'wo.order_id')
            ->where('wo.status', 'aktif')
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', ['siap_produksi', 'produksi'])
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('wo_bahan as wb')
                    ->whereColumn('wb.wo_id', 'wo.id')
                    ->whereColumn('wb.qty_reservasi', '>', 'wb.qty_keluar');
            });
        $woSiapTotal = (clone $woSiap)->count();
        $woSiapDaftar = $woSiap->orderBy('o.deadline')->limit(10)
            ->get(['wo.id', 'wo.no_wo', 'o.no_order', 'o.deadline', 'o.status'])
            ->map(fn ($r) => (array) $r)->values()->all();

        $kirimHariIni = DB::table('pengiriman as sj')
            ->join('orders as o', 'o.id', '=', 'sj.order_id')
            ->join('pelanggan as p', 'p.id', '=', 'o.pelanggan_id')
            ->where('sj.status', '!=', 'dibatalkan')
            ->where('sj.tgl_kirim', $hariIni)
            ->orderBy('sj.id')
            ->get(['sj.id', 'sj.no_sj', 'o.no_order', 'p.nama as pelanggan', 'sj.status'])
            ->map(fn ($r) => (array) $r)->values()->all();

        $opname = DB::table('stok_opname')
            ->whereIn('status', ['draft', 'diajukan'])
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as jumlah')
            ->pluck('jumlah', 'status');

        return [
            'tipe' => 'gudang',
            'bahan_di_bawah_minimum' => $this->bahanDiBawahMinimum(),
            'kekurangan_bahan' => ['total' => $kekurangan->count(), 'daftar' => $kekurangan->take(10)->values()->all()],
            'po_berjalan' => ['total' => $poTotal, 'daftar' => $poDaftar],
            'wo_siap_dikeluarkan' => ['total' => $woSiapTotal, 'daftar' => $woSiapDaftar],
            'pengiriman_hari_ini' => [
                'total' => count($kirimHariIni),
                'sj_disiapkan' => DB::table('pengiriman')->where('status', 'disiapkan')->count(),
                'daftar' => $kirimHariIni,
            ],
            'opname' => ['draft' => (int) ($opname['draft'] ?? 0), 'diajukan' => (int) ($opname['diajukan'] ?? 0)],
        ];
    }

    protected function dashboardSupervisor(User $user): array
    {
        $kode = $this->kodeTahapUser($user);
        $hariIni = today()->toDateString();

        if ($kode === null) {
            return [
                'tipe' => 'supervisor', 'tahap' => null, 'antrean' => [],
                'output_hari_ini' => ['tanggal' => $hariIni, 'total_hasil' => 0, 'total_reject' => 0, 'per_line' => []],
                'repair_open' => ['total_pcs' => 0, 'daftar' => []],
            ];
        }

        $tahapId = (int) $user->tahap_id;
        $kunci = ['cutting' => 'cutting_boleh', 'sewing' => 'sewing_tersedia', 'finishing' => 'finishing_tersedia'][$kode];

        $items = $this->itemQuery()
            ->join('wo_tahap as wt', function ($j) use ($tahapId) {
                $j->on('wt.wo_id', '=', 'wo.id')->where('wt.tahap_id', '=', $tahapId);
            })
            ->where('wo.status', 'aktif')
            ->where('wt.status', '!=', 'selesai')
            ->get(array_merge(self::KOLOM_ITEM, ['wo.no_wo', 'o.no_order', 'o.deadline', 'o.prioritas', 'p.nama as pelanggan']));

        $antrean = $items->groupBy('wo_id')->map(function (Collection $g) use ($kunci) {
            $f = $g->first();

            return [
                'wo_id' => (int) $f->wo_id,
                'no_wo' => $f->no_wo,
                'no_order' => $f->no_order,
                'pelanggan' => $f->pelanggan,
                'deadline' => $f->deadline,
                'prioritas' => $f->prioritas,
                'tersedia_dikerjakan' => $g->sum(fn ($i) => $this->antrean($i)[$kunci]),
            ];
        })->filter(fn (array $r) => $r['tersedia_dikerjakan'] > 0)
            ->sortBy('deadline')->values()->all();

        $lines = DB::table('line_produksi')->where('tahap_id', $tahapId)->where('is_active', 1)->orderBy('kode')->get();
        $out = DB::table('produksi_log')
            ->where('status', 'aktif')
            ->where('tahap_id', $tahapId)
            ->where('tanggal', $hariIni)
            ->groupBy('line_id')
            ->selectRaw('line_id, SUM(qty_hasil) as hasil, SUM(qty_reject) as reject')
            ->get()
            ->keyBy(fn ($r) => $r->line_id ?? 0);

        $perLine = [];
        foreach ($lines as $l) {
            $o = $out->get($l->id);
            $hasil = (int) ($o->hasil ?? 0);
            $perLine[] = [
                'line_id' => (int) $l->id,
                'kode' => $l->kode,
                'nama' => $l->nama,
                'target' => $l->kapasitas_per_hari !== null ? (int) $l->kapasitas_per_hari : null,
                'hasil' => $hasil,
                'reject' => (int) ($o->reject ?? 0),
                'persen_target' => $l->kapasitas_per_hari ? $this->persen($hasil, $l->kapasitas_per_hari, false) : null,
            ];
        }
        if ($out->has(0)) {
            $perLine[] = [
                'line_id' => null, 'kode' => null, 'nama' => 'Tanpa line', 'target' => null,
                'hasil' => (int) $out->get(0)->hasil, 'reject' => (int) $out->get(0)->reject, 'persen_target' => null,
            ];
        }

        $repair = DB::table('qc_repair as r')
            ->join('qc_inspeksi_items as qii', 'qii.id', '=', 'r.qc_inspeksi_item_id')
            ->join('qc_inspeksi as qi', 'qi.id', '=', 'qii.qc_inspeksi_id')
            ->join('wo_items as wi', 'wi.id', '=', 'r.wo_item_id')
            ->join('work_orders as wo', 'wo.id', '=', 'wi.wo_id')
            ->join('orders as o', 'o.id', '=', 'wo.order_id')
            ->where('qi.status', 'aktif')
            ->where('r.status', 'open')
            ->where('r.tahap_tujuan_id', $tahapId)
            ->orderBy('r.id')
            ->get(['r.id', 'r.qty', 'r.qty_selesai', 'wo.no_wo', 'o.no_order']);

        return [
            'tipe' => 'supervisor',
            'tahap' => $kode,
            'antrean' => $antrean,
            'output_hari_ini' => [
                'tanggal' => $hariIni,
                'total_hasil' => array_sum(array_column($perLine, 'hasil')),
                'total_reject' => array_sum(array_column($perLine, 'reject')),
                'per_line' => $perLine,
            ],
            'repair_open' => [
                'total_pcs' => (int) $repair->sum(fn ($r) => $r->qty - $r->qty_selesai),
                'daftar' => $repair->take(10)->map(fn ($r) => [
                    'id' => (int) $r->id, 'no_wo' => $r->no_wo, 'no_order' => $r->no_order,
                    'qty_open' => (int) ($r->qty - $r->qty_selesai),
                ])->values()->all(),
            ],
        ];
    }

    protected function dashboardQc(): array
    {
        $hari = today();
        $awalBulan = $hari->copy()->startOfMonth()->toDateString();
        $akhirBulan = $hari->copy()->endOfMonth()->toDateString();

        $items = $this->itemQuery()
            ->where('wo.status', 'aktif')
            ->get(array_merge(self::KOLOM_ITEM, ['wo.no_wo', 'o.no_order', 'o.deadline', 'p.nama as pelanggan']));

        $antrean = $items->groupBy('wo_id')->map(function (Collection $g) {
            $f = $g->first();

            return [
                'wo_id' => (int) $f->wo_id,
                'no_wo' => $f->no_wo,
                'no_order' => $f->no_order,
                'pelanggan' => $f->pelanggan,
                'deadline' => $f->deadline,
                'qc_tersedia' => $g->sum(fn ($i) => $this->antrean($i)['qc_tersedia']),
            ];
        })->filter(fn (array $r) => $r['qc_tersedia'] > 0)->sortBy('deadline')->values();

        $qc = DB::table('qc_inspeksi_items as qii')
            ->join('qc_inspeksi as qi', 'qi.id', '=', 'qii.qc_inspeksi_id')
            ->where('qi.status', 'aktif')
            ->whereBetween('qi.tanggal', [$awalBulan, $akhirBulan])
            ->selectRaw('COALESCE(SUM(qii.qty_diperiksa),0) as diperiksa, COALESCE(SUM(qii.qty_lolos),0) as lolos, COALESCE(SUM(qii.qty_repair),0) as repair, COALESCE(SUM(qii.qty_reject),0) as reject')
            ->first();

        $topCacat = DB::table('qc_inspeksi_cacat as c')
            ->join('qc_inspeksi_items as qii', 'qii.id', '=', 'c.qc_inspeksi_item_id')
            ->join('qc_inspeksi as qi', 'qi.id', '=', 'qii.qc_inspeksi_id')
            ->join('jenis_cacat as jc', 'jc.id', '=', 'c.jenis_cacat_id')
            ->where('qi.status', 'aktif')
            ->whereBetween('qi.tanggal', [$awalBulan, $akhirBulan])
            ->groupBy('jc.id', 'jc.nama')
            ->selectRaw('jc.id as id, jc.nama as nama, SUM(c.qty) as total')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['jenis_cacat_id' => (int) $r->id, 'nama' => $r->nama, 'qty' => (int) $r->total])
            ->values()->all();

        return [
            'tipe' => 'qc',
            'periode_bulan' => ['tgl_dari' => $awalBulan, 'tgl_sampai' => $akhirBulan],
            'antrean_inspeksi' => [
                'total_pcs' => (int) $antrean->sum('qc_tersedia'),
                'daftar' => $antrean->take(15)->all(),
            ],
            'reject_rate' => [
                'diperiksa' => (int) $qc->diperiksa,
                'lolos' => (int) $qc->lolos,
                'repair' => (int) $qc->repair,
                'reject' => (int) $qc->reject,
                'reject_rate' => $this->rejectRate($qc->lolos, $qc->reject),
            ],
            'top_jenis_cacat' => $topCacat,
        ];
    }

    // =====================================================================
    // LAPORAN: PINTU MASUK
    // =====================================================================

    /**
     * Menyusun satu laporan. Hasil dipakai bersama oleh JSON, PDF, dan Excel.
     *
     * @param  array<string,mixed>  $filter
     * @return array{jenis:string,judul:string,periode:?array,kolom:array,ringkasan:array,baris:array,terpotong:bool,dibuat_pada:string}
     */
    public function laporan(User $user, string $jenis, array $filter = []): array
    {
        abort_unless(isset(self::JENIS[$jenis]), 404);
        abort_unless($this->boleh($user, $jenis), 403);

        $scope = null;
        if ($user->hasRole('supervisor')) {
            $scope = $this->kodeTahapUser($user);
            abort_if($scope === null, 403);
        }

        $f = $this->normalisasi($filter);
        $hasil = $this->{'laporan'.Str::studly($jenis)}($f, $scope);

        $kolom = array_values(array_filter($hasil['kolom'], fn (array $k) => $this->tahapBoleh($scope, $k['tahap'] ?? null)));
        $kunci = array_flip(array_column($kolom, 'key'));
        $baris = $hasil['baris']
            ->map(fn (array $b) => array_intersect_key($b, $kunci))
            ->values()
            ->all();

        $ringkasan = [];
        foreach ($hasil['ringkasan'] ?? [] as $r) {
            if ($this->tahapBoleh($scope, $r['tahap'] ?? null)) {
                $ringkasan[] = ['label' => $r['label'], 'nilai' => $r['nilai'], 'tipe' => $r['tipe'] ?? 'text'];
            }
        }

        return [
            'jenis' => $jenis,
            'judul' => self::JENIS[$jenis]['label'],
            'periode' => ($hasil['snapshot'] ?? false) ? null : ['tgl_dari' => $f['tgl_dari'], 'tgl_sampai' => $f['tgl_sampai']],
            'kolom' => $kolom,
            'ringkasan' => $ringkasan,
            'baris' => $baris,
            'terpotong' => count($baris) >= self::MAKS_BARIS,
            'dibuat_pada' => now()->toIso8601String(),
        ];
    }

    /** Bentuk tabel siap cetak (PDF atau Excel): kepala, baris terformat, ringkasan terformat. */
    public function tabel(array $laporan, bool $untukPdf): array
    {
        $kolom = array_values(array_filter($laporan['kolom'], fn (array $k) => ! $untukPdf || ($k['pdf'] ?? true)));

        $baris = [];
        foreach ($laporan['baris'] as $b) {
            $baris[] = array_map(fn (array $k) => $this->formatSel($b[$k['key']] ?? null, $k['tipe'], $untukPdf), $kolom);
        }

        $ringkasan = array_map(
            fn (array $r) => [$r['label'], $this->formatSel($r['nilai'], $r['tipe'], $untukPdf)],
            $laporan['ringkasan']
        );

        return [
            'kepala' => array_column($kolom, 'label'),
            'tipe' => array_column($kolom, 'tipe'),
            'baris' => $baris,
            'ringkasan' => $ringkasan,
        ];
    }

    public function formatSel(mixed $nilai, string $tipe, bool $pdf): mixed
    {
        if ($nilai === null || $nilai === '') {
            return $pdf ? '-' : '';
        }

        $hasil = match ($tipe) {
            'int' => $pdf ? number_format((float) $nilai, 0, ',', '.') : (int) $nilai,
            'dec' => $pdf ? number_format((float) $nilai, 3, ',', '.') : (float) $nilai,
            'money' => $pdf ? number_format((float) $nilai, 2, ',', '.') : (float) $nilai,
            'pct' => $pdf ? number_format((float) $nilai, 2, ',', '.').'%' : (float) $nilai,
            'date' => Carbon::parse($nilai)->format('d-m-Y'),
            'datetime' => Carbon::parse($nilai)->format('d-m-Y H:i'),
            'bool' => $nilai ? 'Ya' : 'Tidak',
            'status' => Str::headline((string) $nilai),
            default => (string) $nilai,
        };

        // Excel: teks berawalan "=" dibaca sebagai rumus (injeksi rumus). Jadikan teks biasa.
        if (! $pdf && is_string($hasil) && $hasil !== '' && $hasil[0] === '=') {
            return "'".$hasil;
        }

        return $hasil;
    }

    protected function normalisasi(array $f): array
    {
        $dari = ! empty($f['tgl_dari']) ? Carbon::parse($f['tgl_dari']) : today()->startOfMonth();
        if (! empty($f['tgl_sampai'])) {
            $sampai = Carbon::parse($f['tgl_sampai']);
        } else {
            $sampai = today()->lt($dari) ? $dari->copy() : today();
        }

        if ($sampai->lt($dari)) {
            throw ValidationException::withMessages(['tgl_sampai' => ['Tanggal sampai tidak boleh sebelum tanggal dari.']]);
        }

        $f['tgl_dari'] = $dari->toDateString();
        $f['tgl_sampai'] = $sampai->toDateString();

        return $f;
    }

    /** Supervisor hanya melihat kolom tahapnya. Tag null = umum, 'hilir' = QC/kirim (disembunyikan untuk supervisor). */
    protected function tahapBoleh(?string $scope, ?string $tag): bool
    {
        if ($scope === null || $tag === null) {
            return true;
        }

        return $tag === $scope;
    }

    protected function kol(string $key, string $label, string $tipe = 'text', ?string $tahap = null, bool $pdf = true): array
    {
        return ['key' => $key, 'label' => $label, 'tipe' => $tipe, 'tahap' => $tahap, 'pdf' => $pdf];
    }

    protected function ring(string $label, mixed $nilai, string $tipe = 'text', ?string $tahap = null): array
    {
        return ['label' => $label, 'nilai' => $nilai, 'tipe' => $tipe, 'tahap' => $tahap];
    }

    protected function filterInt(array $f, string $key): ?int
    {
        return isset($f[$key]) && $f[$key] !== '' ? (int) $f[$key] : null;
    }

    // =====================================================================
    // 1. ORDER
    // =====================================================================

    protected function laporanOrder(array $f, ?string $scope): array
    {
        $q = DB::table('orders as o')
            ->join('pelanggan as p', 'p.id', '=', 'o.pelanggan_id')
            ->whereNull('o.deleted_at')
            ->whereBetween('o.tgl_order', [$f['tgl_dari'], $f['tgl_sampai']]);

        if (! empty($f['status'])) {
            $q->where('o.status', $f['status']);
        }
        if ($id = $this->filterInt($f, 'pelanggan_id')) {
            $q->where('o.pelanggan_id', $id);
        }

        $hariIni = today()->toDateString();
        $aktif = $this->statusAktif();

        $rows = $q->orderBy('o.tgl_order')->orderBy('o.id')->limit(self::MAKS_BARIS)
            ->get(['o.no_order', 'o.tgl_order', 'p.nama as pelanggan', 'o.deadline', 'o.prioritas', 'o.status', 'o.total_qty', 'o.total_nilai']);

        $baris = $rows->map(fn ($r) => [
            'no_order' => $r->no_order,
            'tgl_order' => $r->tgl_order,
            'pelanggan' => $r->pelanggan,
            'deadline' => $r->deadline,
            'prioritas' => $r->prioritas,
            'status' => $r->status,
            'terlambat' => in_array($r->status, $aktif, true) && $r->deadline < $hariIni,
            'total_qty' => (int) $r->total_qty,
            'total_nilai' => $r->total_nilai !== null ? (float) $r->total_nilai : null,
        ]);

        return [
            'kolom' => [
                $this->kol('no_order', 'No Order'), $this->kol('tgl_order', 'Tgl Order', 'date'),
                $this->kol('pelanggan', 'Pelanggan'), $this->kol('deadline', 'Deadline', 'date'),
                $this->kol('prioritas', 'Prioritas', 'status'), $this->kol('status', 'Status', 'status'),
                $this->kol('terlambat', 'Terlambat', 'bool'), $this->kol('total_qty', 'Total Qty', 'int'),
                $this->kol('total_nilai', 'Total Nilai', 'money'),
            ],
            'baris' => $baris,
            'ringkasan' => [
                $this->ring('Jumlah Order', $baris->count(), 'int'),
                $this->ring('Total Qty', $baris->sum('total_qty'), 'int'),
                $this->ring('Total Nilai', round((float) $baris->sum('total_nilai'), 2), 'money'),
            ],
        ];
    }

    // =====================================================================
    // 2. PROGRES ORDER DAN WO
    // =====================================================================

    protected function laporanProgresWo(array $f, ?string $scope): array
    {
        $q = $this->itemQuery()
            ->where('wo.status', '!=', 'dibatalkan')
            ->whereBetween('o.tgl_order', [$f['tgl_dari'], $f['tgl_sampai']]);

        if (! empty($f['status'])) {
            $q->where('o.status', $f['status']);
        }
        if ($id = $this->filterInt($f, 'pelanggan_id')) {
            $q->where('o.pelanggan_id', $id);
        }

        $rows = $q->orderBy('o.deadline')->orderBy('wo.id')->orderBy('wi.id')->limit(self::MAKS_BARIS)
            ->get(array_merge(self::KOLOM_ITEM, [
                'wo.no_wo', 'o.no_order', 'o.deadline', 'p.nama as pelanggan',
                'pr.nama as produk', 'w.nama as warna', 'u.kode as ukuran',
            ]));

        $baris = $rows->map(fn ($r) => [
            'no_wo' => $r->no_wo,
            'no_order' => $r->no_order,
            'pelanggan' => $r->pelanggan,
            'produk' => $r->produk,
            'warna' => $r->warna,
            'ukuran' => $r->ukuran,
            'qty_order' => (int) $r->qty_order,
            'cutting' => (int) $r->qty_cutting,
            'sewing' => (int) $r->qty_sewing,
            'finishing' => (int) $r->qty_finishing,
            'lolos_qc' => (int) $r->qty_qc_lolos,
            'packing' => (int) $r->qty_packing,
            'terkirim' => (int) $r->qty_terkirim,
            'progres_cutting' => $this->persen($r->qty_cutting, $r->qty_order),
            'progres_sewing' => $this->persen($r->qty_sewing, $r->qty_order),
            'progres_finishing' => $this->persen($r->qty_finishing, $r->qty_order),
            'progres_kirim' => $this->persen($r->qty_terkirim, $r->qty_order),
            'kekurangan' => max(0, (int) ($r->qty_order - $r->qty_qc_lolos)),
            'kelebihan' => max(0, (int) ($r->qty_qc_lolos - $r->qty_order)),
        ]);

        $qo = $rows->sum('qty_order');
        $cuttingTotal = $rows->sum('qty_cutting') + $rows->sum('qty_cutting_reject');

        return [
            'kolom' => [
                $this->kol('no_wo', 'No WO'), $this->kol('no_order', 'No Order'), $this->kol('pelanggan', 'Pelanggan'),
                $this->kol('produk', 'Produk'), $this->kol('warna', 'Warna'), $this->kol('ukuran', 'Ukuran'),
                $this->kol('qty_order', 'Qty Order', 'int'),
                $this->kol('cutting', 'Cutting', 'int', 'cutting'),
                $this->kol('sewing', 'Sewing', 'int', 'sewing'),
                $this->kol('finishing', 'Finishing', 'int', 'finishing'),
                $this->kol('lolos_qc', 'Lolos QC', 'int', 'hilir'),
                $this->kol('packing', 'Packing', 'int', 'finishing'),
                $this->kol('terkirim', 'Terkirim', 'int', 'hilir'),
                $this->kol('progres_cutting', '% Cutting', 'pct', 'cutting'),
                $this->kol('progres_sewing', '% Sewing', 'pct', 'sewing'),
                $this->kol('progres_finishing', '% Finishing', 'pct', 'finishing'),
                $this->kol('progres_kirim', '% Kirim', 'pct', 'hilir'),
                $this->kol('kekurangan', 'Kekurangan vs QC', 'int', 'hilir'),
                $this->kol('kelebihan', 'Kelebihan Produksi', 'int', 'hilir'),
            ],
            'baris' => $baris,
            'ringkasan' => [
                $this->ring('Total Qty Order', (int) $qo, 'int'),
                $this->ring('Progres Cutting', $this->persen($rows->sum('qty_cutting'), $qo), 'pct', 'cutting'),
                $this->ring('Progres Sewing', $this->persen($rows->sum('qty_sewing'), $qo), 'pct', 'sewing'),
                $this->ring('Progres Finishing', $this->persen($rows->sum('qty_finishing'), $qo), 'pct', 'finishing'),
                $this->ring('Progres Kirim', $this->persen($rows->sum('qty_terkirim'), $qo), 'pct', 'hilir'),
                $this->ring('Yield Produksi', $this->persen($rows->sum('qty_packing'), $cuttingTotal), 'pct', 'hilir'),
                $this->ring('Total Kelebihan Produksi', (int) $baris->sum('kelebihan'), 'int', 'hilir'),
            ],
        ];
    }

    // =====================================================================
    // 3. PRODUKSI HARIAN
    // =====================================================================

    protected function laporanProduksiHarian(array $f, ?string $scope): array
    {
        $q = DB::table('produksi_log as pl')
            ->join('tahap as t', 't.id', '=', 'pl.tahap_id')
            ->leftJoin('line_produksi as l', 'l.id', '=', 'pl.line_id')
            ->where('pl.status', 'aktif')
            ->whereBetween('pl.tanggal', [$f['tgl_dari'], $f['tgl_sampai']]);

        if ($scope !== null) {
            $q->where('t.kode', $scope);
        }
        if ($id = $this->filterInt($f, 'tahap_id')) {
            $q->where('pl.tahap_id', $id);
        }
        if ($id = $this->filterInt($f, 'line_id')) {
            $q->where('pl.line_id', $id);
        }

        $rows = $q->groupBy('pl.tanggal', 't.urutan', 't.nama', 't.kode', 'l.id', 'l.nama')
            ->orderBy('pl.tanggal')->orderBy('t.urutan')->orderBy('l.nama')
            ->limit(self::MAKS_BARIS)
            ->selectRaw("pl.tanggal as tanggal, t.nama as tahap, t.kode as kode, COALESCE(l.nama, 'Tanpa line') as line, SUM(pl.qty_hasil) as hasil, SUM(pl.qty_reject) as reject")
            ->get();

        $baris = $rows->map(fn ($r) => [
            'tanggal' => $r->tanggal,
            'tahap' => $r->tahap,
            'line' => $r->line,
            'hasil' => (int) $r->hasil,
            'reject' => (int) $r->reject,
            'reject_rate' => $this->rejectRate($r->hasil, $r->reject),
        ]);

        $ringkasan = [];
        foreach ($rows->groupBy('kode') as $kode => $g) {
            $ringkasan[] = $this->ring('Total Hasil '.ucfirst($kode), (int) $g->sum('hasil'), 'int', $kode);
            $ringkasan[] = $this->ring('Total Reject '.ucfirst($kode), (int) $g->sum('reject'), 'int', $kode);
            $ringkasan[] = $this->ring('Reject Rate '.ucfirst($kode), $this->rejectRate($g->sum('hasil'), $g->sum('reject')), 'pct', $kode);

            // Produktivitas line = jumlah qty_hasil per line / jumlah hari yang ada input
            foreach ($g->groupBy('line') as $namaLine => $gl) {
                $hari = $gl->pluck('tanggal')->unique()->count();
                $ringkasan[] = $this->ring(
                    'Produktivitas '.$namaLine.' ('.ucfirst($kode).', pcs/hari)',
                    $hari > 0 ? round($gl->sum('hasil') / $hari, 2) : 0,
                    'dec',
                    $kode
                );
            }
        }

        return [
            'kolom' => [
                $this->kol('tanggal', 'Tanggal', 'date'), $this->kol('tahap', 'Tahap'), $this->kol('line', 'Line'),
                $this->kol('hasil', 'Hasil', 'int'), $this->kol('reject', 'Reject', 'int'),
                $this->kol('reject_rate', 'Reject Rate', 'pct'),
            ],
            'baris' => $baris,
            'ringkasan' => $ringkasan,
        ];
    }

    // =====================================================================
    // 4. WIP
    // =====================================================================

    protected function laporanWip(array $f, ?string $scope): array
    {
        $items = $this->itemQuery()
            ->where('wo.status', 'aktif')
            ->get(array_merge(self::KOLOM_ITEM, ['wo.no_wo', 'o.no_order', 'o.deadline', 'p.nama as pelanggan']));

        $baris = $items->groupBy('wo_id')->map(function (Collection $g) {
            $f = $g->first();
            $a = ['cutting_boleh' => 0, 'sewing_tersedia' => 0, 'finishing_tersedia' => 0, 'qc_tersedia' => 0, 'repair_open' => 0, 'packing_tersedia' => 0, 'kirim_tersedia' => 0];
            foreach ($g as $i) {
                foreach ($this->antrean($i) as $k => $v) {
                    $a[$k] += $v;
                }
            }

            return ['no_wo' => $f->no_wo, 'no_order' => $f->no_order, 'pelanggan' => $f->pelanggan, 'deadline' => $f->deadline] + $a;
        })->sortBy('deadline')->values();

        $ringkasan = [];
        foreach ([
            'cutting_boleh' => ['Total Sisa Boleh Dipotong', 'cutting'],
            'sewing_tersedia' => ['Total Antrean Sewing', 'sewing'],
            'finishing_tersedia' => ['Total Antrean Finishing', 'finishing'],
            'qc_tersedia' => ['Total Antrean QC', 'hilir'],
            'repair_open' => ['Total Repair Open', 'hilir'],
            'packing_tersedia' => ['Total Siap Packing', 'finishing'],
            'kirim_tersedia' => ['Total Siap Kirim', 'hilir'],
        ] as $key => [$label, $tag]) {
            $ringkasan[] = $this->ring($label, (int) $baris->sum($key), 'int', $tag);
        }

        return [
            'snapshot' => true,
            'kolom' => [
                $this->kol('no_wo', 'No WO'), $this->kol('no_order', 'No Order'), $this->kol('pelanggan', 'Pelanggan'),
                $this->kol('deadline', 'Deadline', 'date'),
                $this->kol('cutting_boleh', 'Sisa Boleh Dipotong', 'int', 'cutting'),
                $this->kol('sewing_tersedia', 'Antrean Sewing', 'int', 'sewing'),
                $this->kol('finishing_tersedia', 'Antrean Finishing', 'int', 'finishing'),
                $this->kol('qc_tersedia', 'Antrean QC', 'int', 'hilir'),
                $this->kol('repair_open', 'Repair Open', 'int', 'hilir'),
                $this->kol('packing_tersedia', 'Siap Packing', 'int', 'finishing'),
                $this->kol('kirim_tersedia', 'Siap Kirim', 'int', 'hilir'),
            ],
            'baris' => $baris,
            'ringkasan' => $ringkasan,
        ];
    }

    // =====================================================================
    // 5. ANALISIS REJECT
    // =====================================================================

    protected function laporanReject(array $f, ?string $scope): array
    {
        $kelompok = $f['kelompok'] ?? 'tahap';
        if (! in_array($kelompok, ['tahap', 'produk', 'line', 'jenis_cacat'], true)) {
            throw ValidationException::withMessages(['kelompok' => ['Kelompok harus salah satu dari: tahap, produk, line, jenis_cacat.']]);
        }

        $q = DB::table('produksi_log as pl')
            ->join('tahap as t', 't.id', '=', 'pl.tahap_id')
            ->join('wo_items as wi', 'wi.id', '=', 'pl.wo_item_id')
            ->join('order_items as oi', 'oi.id', '=', 'wi.order_item_id')
            ->join('produk as pr', 'pr.id', '=', 'oi.produk_id')
            ->leftJoin('line_produksi as l', 'l.id', '=', 'pl.line_id')
            ->leftJoin('jenis_cacat as jc', 'jc.id', '=', 'pl.jenis_cacat_id')
            ->where('pl.status', 'aktif')
            ->whereBetween('pl.tanggal', [$f['tgl_dari'], $f['tgl_sampai']]);

        if ($scope !== null) {
            $q->where('t.kode', $scope);
        }
        if ($id = $this->filterInt($f, 'tahap_id')) {
            $q->where('pl.tahap_id', $id);
        }
        if ($id = $this->filterInt($f, 'produk_id')) {
            $q->where('oi.produk_id', $id);
        }
        if ($id = $this->filterInt($f, 'line_id')) {
            $q->where('pl.line_id', $id);
        }

        $sum = 'SUM(pl.qty_hasil) as hasil, SUM(pl.qty_reject) as reject';
        $dataQc = $scope === null && ($this->filterInt($f, 'tahap_id') === null) && ($this->filterInt($f, 'line_id') === null);

        // QC final: hasil = qty_lolos (rumus reject rate yang sama).
        $qcQuery = fn () => DB::table('qc_inspeksi_items as qii')
            ->join('qc_inspeksi as qi', 'qi.id', '=', 'qii.qc_inspeksi_id')
            ->where('qi.status', 'aktif')
            ->whereBetween('qi.tanggal', [$f['tgl_dari'], $f['tgl_sampai']]);

        if ($kelompok === 'jenis_cacat') {
            $prod = (clone $q)->where('pl.qty_reject', '>', 0)
                ->groupBy('jc.id', 'jc.nama', 'jc.kategori')
                ->selectRaw('jc.id as id, jc.nama as nama, jc.kategori as kategori, SUM(pl.qty_reject) as reject')
                ->get()->keyBy(fn ($r) => $r->id ?? 0);

            $qc = collect();
            if ($dataQc) {
                $qb = $qcQuery()
                    ->join('qc_inspeksi_cacat as c', 'c.qc_inspeksi_item_id', '=', 'qii.id')
                    ->join('jenis_cacat as jc', 'jc.id', '=', 'c.jenis_cacat_id')
                    ->where('c.tindakan', 'reject');
                if ($id = $this->filterInt($f, 'produk_id')) {
                    $qb->join('wo_items as wi', 'wi.id', '=', 'qii.wo_item_id')
                        ->join('order_items as oi', 'oi.id', '=', 'wi.order_item_id')
                        ->where('oi.produk_id', $id);
                }
                $qc = $qb->groupBy('jc.id', 'jc.nama', 'jc.kategori')
                    ->selectRaw('jc.id as id, jc.nama as nama, jc.kategori as kategori, SUM(c.qty) as reject')
                    ->get()->keyBy('id');
            }

            $gabung = [];
            foreach ([$prod, $qc] as $sumber) {
                foreach ($sumber as $id => $r) {
                    $gabung[$id] ??= ['jenis_cacat' => $r->nama ?? 'Tanpa jenis cacat', 'kategori' => $r->kategori, 'reject' => 0];
                    $gabung[$id]['reject'] += (int) $r->reject;
                }
            }
            $total = array_sum(array_column($gabung, 'reject'));
            $baris = collect($gabung)->sortByDesc('reject')->map(fn (array $r) => $r + [
                'kontribusi' => $this->persen($r['reject'], $total),
            ])->values();

            return [
                'kolom' => [
                    $this->kol('jenis_cacat', 'Jenis Cacat'), $this->kol('kategori', 'Kategori', 'status'),
                    $this->kol('reject', 'Reject (pcs)', 'int'), $this->kol('kontribusi', 'Kontribusi', 'pct'),
                ],
                'baris' => $baris,
                'ringkasan' => [$this->ring('Total Reject', (int) $total, 'int')],
            ];
        }

        if ($kelompok === 'tahap') {
            $rows = $q->groupBy('t.urutan', 't.nama', 't.kode')->orderBy('t.urutan')
                ->selectRaw("t.nama as label, t.kode as kode, {$sum}")->get();
            $baris = $rows->map(fn ($r) => [
                'kelompok' => $r->label, 'hasil' => (int) $r->hasil, 'reject' => (int) $r->reject,
                'reject_rate' => $this->rejectRate($r->hasil, $r->reject),
            ]);

            if ($dataQc && $this->filterInt($f, 'produk_id') === null) {
                $qc = $qcQuery()->selectRaw('COALESCE(SUM(qii.qty_lolos),0) as hasil, COALESCE(SUM(qii.qty_reject),0) as reject')->first();
                if ((int) $qc->hasil + (int) $qc->reject > 0) {
                    $baris->push([
                        'kelompok' => 'Final QC', 'hasil' => (int) $qc->hasil, 'reject' => (int) $qc->reject,
                        'reject_rate' => $this->rejectRate($qc->hasil, $qc->reject),
                    ]);
                }
            }
            $judulKel = 'Tahap';
        } else {
            if ($kelompok === 'produk') {
                $rows = $q->groupBy('pr.id', 'pr.nama')->orderBy('pr.nama')
                    ->selectRaw("pr.nama as label, {$sum}")->get();
                $judulKel = 'Produk';
            } else {
                $rows = $q->groupBy('l.id', 'l.nama')->orderBy('l.nama')
                    ->selectRaw("COALESCE(l.nama, 'Tanpa line') as label, {$sum}")->get();
                $judulKel = 'Line';
            }
            $baris = $rows->map(fn ($r) => [
                'kelompok' => $r->label, 'hasil' => (int) $r->hasil, 'reject' => (int) $r->reject,
                'reject_rate' => $this->rejectRate($r->hasil, $r->reject),
            ]);
        }

        $h = $baris->sum('hasil');
        $r = $baris->sum('reject');

        return [
            'kolom' => [
                $this->kol('kelompok', $judulKel), $this->kol('hasil', 'Hasil Baik', 'int'),
                $this->kol('reject', 'Reject', 'int'), $this->kol('reject_rate', 'Reject Rate', 'pct'),
            ],
            'baris' => $baris,
            'ringkasan' => [
                $this->ring('Total Hasil Baik', (int) $h, 'int'),
                $this->ring('Total Reject', (int) $r, 'int'),
                $this->ring('Reject Rate Keseluruhan', $this->rejectRate($h, $r), 'pct'),
            ],
        ];
    }

    // =====================================================================
    // 6. REPAIR
    // =====================================================================

    protected function laporanRepair(array $f, ?string $scope): array
    {
        $q = DB::table('qc_repair as r')
            ->join('qc_inspeksi_items as qii', 'qii.id', '=', 'r.qc_inspeksi_item_id')
            ->join('qc_inspeksi as qi', 'qi.id', '=', 'qii.qc_inspeksi_id')
            ->join('wo_items as wi', 'wi.id', '=', 'r.wo_item_id')
            ->join('work_orders as wo', 'wo.id', '=', 'wi.wo_id')
            ->join('order_items as oi', 'oi.id', '=', 'wi.order_item_id')
            ->join('produk as pr', 'pr.id', '=', 'oi.produk_id')
            ->join('warna as w', 'w.id', '=', 'oi.warna_id')
            ->join('ukuran as u', 'u.id', '=', 'oi.ukuran_id')
            ->join('tahap as t', 't.id', '=', 'r.tahap_tujuan_id')
            ->where('qi.status', 'aktif')
            ->whereBetween('qi.tanggal', [$f['tgl_dari'], $f['tgl_sampai']]);

        if ($scope !== null) {
            $q->where('t.kode', $scope);
        }
        if (! empty($f['status'])) {
            $q->where('r.status', $f['status']);
        }
        if ($id = $this->filterInt($f, 'tahap_id')) {
            $q->where('r.tahap_tujuan_id', $id);
        }

        $rows = $q->orderBy('qi.tanggal')->orderBy('r.id')->limit(self::MAKS_BARIS)
            ->get([
                'r.id', 'qi.no_qc', 'qi.tanggal', 'wo.no_wo', 'pr.nama as produk', 'w.nama as warna', 'u.kode as ukuran',
                't.nama as tahap_tujuan', 'r.qty', 'r.qty_selesai', 'r.status', 'r.created_at', 'r.selesai_at',
            ]);

        $sekarang = now()->toDateTimeString();
        $baris = $rows->map(function ($r) use ($sekarang) {
            $akhir = $r->selesai_at ?: $sekarang;
            $lama = max(0, (int) floor((strtotime($akhir) - strtotime($r->created_at)) / 86400));

            return [
                'no_qc' => $r->no_qc,
                'tanggal' => $r->tanggal,
                'no_wo' => $r->no_wo,
                'produk' => $r->produk,
                'warna' => $r->warna,
                'ukuran' => $r->ukuran,
                'tahap_tujuan' => $r->tahap_tujuan,
                'qty' => (int) $r->qty,
                'qty_selesai' => (int) $r->qty_selesai,
                'qty_open' => $r->status === 'open' ? (int) ($r->qty - $r->qty_selesai) : 0,
                'status' => $r->status,
                'lama_hari' => $lama,
                'selesai_at' => $r->selesai_at,
            ];
        });

        $selesai = $baris->where('status', 'selesai');

        return [
            'kolom' => [
                $this->kol('no_qc', 'No QC'), $this->kol('tanggal', 'Tgl QC', 'date'), $this->kol('no_wo', 'No WO'),
                $this->kol('produk', 'Produk'), $this->kol('warna', 'Warna'), $this->kol('ukuran', 'Ukuran'),
                $this->kol('tahap_tujuan', 'Tahap Tujuan'), $this->kol('qty', 'Qty Repair', 'int'),
                $this->kol('qty_selesai', 'Selesai', 'int'), $this->kol('qty_open', 'Masih Open', 'int'),
                $this->kol('status', 'Status', 'status'), $this->kol('lama_hari', 'Lama (hari)', 'int'),
                $this->kol('selesai_at', 'Selesai Pada', 'datetime'),
            ],
            'baris' => $baris,
            'ringkasan' => [
                $this->ring('Total Pcs Repair', (int) $baris->sum('qty'), 'int'),
                $this->ring('Pcs Masih Open', (int) $baris->sum('qty_open'), 'int'),
                $this->ring('Pcs Sudah Selesai', (int) $baris->sum('qty_selesai'), 'int'),
                $this->ring('Rata-rata Lama Penyelesaian (hari)', $selesai->count() ? round($selesai->avg('lama_hari'), 2) : 0, 'dec'),
            ],
        ];
    }

    // =====================================================================
    // 7. STOK BAHAN
    // =====================================================================

    protected function laporanStokBahan(array $f, ?string $scope): array
    {
        $q = DB::table('bahan as b')
            ->join('satuan as s', 's.id', '=', 'b.satuan_id')
            ->whereNull('b.deleted_at')
            ->where('b.is_active', 1);

        if (! empty($f['jenis_bahan'])) {
            $q->where('b.jenis', $f['jenis_bahan']);
        }

        $baris = $q->orderBy('b.nama')->limit(self::MAKS_BARIS)
            ->get(['b.kode', 'b.nama', 'b.jenis', 's.kode as satuan', 'b.stok', 'b.stok_dipesan', 'b.stok_minimum'])
            ->map(function ($r) {
                $tersedia = round((float) $r->stok - (float) $r->stok_dipesan, 3);

                return [
                    'kode' => $r->kode,
                    'nama' => $r->nama,
                    'jenis' => $r->jenis,
                    'satuan' => $r->satuan,
                    'stok' => (float) $r->stok,
                    'stok_dipesan' => (float) $r->stok_dipesan,
                    'stok_tersedia' => $tersedia,
                    'stok_minimum' => (float) $r->stok_minimum,
                    'di_bawah_minimum' => (float) $r->stok_minimum > 0 && $tersedia <= (float) $r->stok_minimum,
                ];
            });

        if (filter_var($f['di_bawah_minimum'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $baris = $baris->where('di_bawah_minimum', true)->values();
        }

        return [
            'snapshot' => true,
            'kolom' => [
                $this->kol('kode', 'Kode'), $this->kol('nama', 'Nama Bahan'), $this->kol('jenis', 'Jenis', 'status'),
                $this->kol('satuan', 'Satuan'), $this->kol('stok', 'Stok', 'dec'), $this->kol('stok_dipesan', 'Dipesan', 'dec'),
                $this->kol('stok_tersedia', 'Tersedia', 'dec'), $this->kol('stok_minimum', 'Minimum', 'dec'),
                $this->kol('di_bawah_minimum', 'Di Bawah Minimum', 'bool'),
            ],
            'baris' => $baris,
            'ringkasan' => [
                $this->ring('Jumlah Bahan', $baris->count(), 'int'),
                $this->ring('Di Bawah Minimum', $baris->where('di_bawah_minimum', true)->count(), 'int'),
            ],
        ];
    }

    // =====================================================================
    // 8. KARTU STOK
    // =====================================================================

    protected function laporanKartuStok(array $f, ?string $scope): array
    {
        $bahanId = $this->filterInt($f, 'bahan_id');
        if (! $bahanId) {
            throw ValidationException::withMessages(['bahan_id' => ['bahan_id wajib diisi untuk kartu stok.']]);
        }

        $bahan = DB::table('bahan as b')->join('satuan as s', 's.id', '=', 'b.satuan_id')
            ->where('b.id', $bahanId)->first(['b.id', 'b.kode', 'b.nama', 's.kode as satuan']);
        abort_if($bahan === null, 404);

        $awal = $f['tgl_dari'].' 00:00:00';
        $akhir = $f['tgl_sampai'].' 23:59:59';

        $rows = DB::table('stok_mutasi as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.bahan_id', $bahanId)
            ->whereBetween('m.tanggal', [$awal, $akhir])
            ->orderBy('m.tanggal')->orderBy('m.id')->limit(self::MAKS_BARIS)
            ->get(['m.tanggal', 'm.tipe', 'm.ref_tipe', 'm.ref_id', 'm.keterangan', 'm.qty', 'm.stok_sebelum', 'm.stok_sesudah', 'u.name as oleh']);

        $baris = $rows->map(fn ($r) => [
            'tanggal' => $r->tanggal,
            'tipe' => $r->tipe,
            'referensi' => $r->ref_tipe ? $r->ref_tipe.($r->ref_id ? ' #'.$r->ref_id : '') : null,
            'keterangan' => $r->keterangan,
            'masuk' => (float) $r->qty > 0 ? (float) $r->qty : 0.0,
            'keluar' => (float) $r->qty < 0 ? abs((float) $r->qty) : 0.0,
            'saldo' => (float) $r->stok_sesudah,
            'oleh' => $r->oleh,
        ]);

        $saldoAwal = $rows->isNotEmpty()
            ? (float) $rows->first()->stok_sebelum
            : (float) (DB::table('stok_mutasi')->where('bahan_id', $bahanId)->where('tanggal', '<', $awal)
                ->orderByDesc('tanggal')->orderByDesc('id')->value('stok_sesudah') ?? 0);
        $saldoAkhir = $rows->isNotEmpty() ? (float) $rows->last()->stok_sesudah : $saldoAwal;

        return [
            'kolom' => [
                $this->kol('tanggal', 'Tanggal', 'datetime'), $this->kol('tipe', 'Tipe', 'status'),
                $this->kol('referensi', 'Referensi'), $this->kol('keterangan', 'Keterangan'),
                $this->kol('masuk', 'Masuk', 'dec'), $this->kol('keluar', 'Keluar', 'dec'),
                $this->kol('saldo', 'Saldo', 'dec'), $this->kol('oleh', 'Oleh'),
            ],
            'baris' => $baris,
            'ringkasan' => [
                $this->ring('Bahan', $bahan->kode.' - '.$bahan->nama.' ('.$bahan->satuan.')'),
                $this->ring('Saldo Awal', $saldoAwal, 'dec'),
                $this->ring('Total Masuk', round((float) $baris->sum('masuk'), 3), 'dec'),
                $this->ring('Total Keluar', round((float) $baris->sum('keluar'), 3), 'dec'),
                $this->ring('Saldo Akhir', $saldoAkhir, 'dec'),
            ],
        ];
    }

    // =====================================================================
    // 9. KEBUTUHAN VS STOK
    // =====================================================================

    protected function laporanKebutuhanVsStok(array $f, ?string $scope): array
    {
        $kebutuhan = DB::table('wo_bahan as wb')
            ->join('work_orders as wo', 'wo.id', '=', 'wb.wo_id')
            ->join('orders as o', 'o.id', '=', 'wo.order_id')
            ->where('wo.status', 'aktif')
            ->whereIn('o.status', ['menunggu_bahan', 'siap_produksi', 'produksi'])
            ->get(['wb.bahan_id', 'wb.qty_rencana', 'wb.qty_keluar'])
            ->groupBy('bahan_id')
            ->map(fn (Collection $g) => round($g->sum(fn ($r) => max(0, (float) $r->qty_rencana - (float) $r->qty_keluar)), 3))
            ->filter(fn ($v) => $v > 0);

        $transit = $this->poTransit();

        $bahan = DB::table('bahan as b')
            ->join('satuan as s', 's.id', '=', 'b.satuan_id')
            ->whereIn('b.id', $kebutuhan->keys()->all())
            ->orderBy('b.nama')
            ->get(['b.id', 'b.kode', 'b.nama', 'b.jenis', 's.kode as satuan', 'b.stok', 'b.stok_dipesan']);

        $baris = $bahan->map(function ($b) use ($kebutuhan, $transit) {
            $k = (float) $kebutuhan[$b->id];
            $stok = (float) $b->stok;
            $po = (float) ($transit[$b->id] ?? 0);
            $proyeksi = round($stok + $po - $k, 3);
            $kurang = max(0.0, -$proyeksi);

            return [
                'kode' => $b->kode,
                'nama' => $b->nama,
                'jenis' => $b->jenis,
                'satuan' => $b->satuan,
                'kebutuhan_sisa' => $k,
                'stok' => $stok,
                'stok_dipesan' => (float) $b->stok_dipesan,
                'stok_tersedia' => round($stok - (float) $b->stok_dipesan, 3),
                'po_dalam_perjalanan' => $po,
                'proyeksi_saldo' => $proyeksi,
                'kekurangan' => $kurang,
                'status' => $kurang > 0 ? 'Kurang' : ($stok < $k ? 'Tergantung PO' : 'Cukup'),
            ];
        })->values();

        return [
            'snapshot' => true,
            'kolom' => [
                $this->kol('kode', 'Kode'), $this->kol('nama', 'Nama Bahan'), $this->kol('jenis', 'Jenis', 'status'),
                $this->kol('satuan', 'Satuan'), $this->kol('kebutuhan_sisa', 'Kebutuhan Sisa', 'dec'),
                $this->kol('stok', 'Stok', 'dec'), $this->kol('stok_dipesan', 'Dipesan', 'dec'),
                $this->kol('stok_tersedia', 'Tersedia', 'dec'), $this->kol('po_dalam_perjalanan', 'PO Berjalan', 'dec'),
                $this->kol('proyeksi_saldo', 'Proyeksi Saldo', 'dec'), $this->kol('kekurangan', 'Kekurangan', 'dec'),
                $this->kol('status', 'Status'),
            ],
            'baris' => $baris,
            'ringkasan' => [
                $this->ring('Bahan Dibutuhkan', $baris->count(), 'int'),
                $this->ring('Bahan Kurang', $baris->where('status', 'Kurang')->count(), 'int'),
                $this->ring('Bahan Tergantung PO', $baris->where('status', 'Tergantung PO')->count(), 'int'),
            ],
        ];
    }

    // =====================================================================
    // 10. PEMAKAIAN BAHAN
    // =====================================================================

    protected function laporanPemakaianBahan(array $f, ?string $scope): array
    {
        $q = DB::table('wo_bahan as wb')
            ->join('work_orders as wo', 'wo.id', '=', 'wb.wo_id')
            ->join('orders as o', 'o.id', '=', 'wo.order_id')
            ->join('bahan as b', 'b.id', '=', 'wb.bahan_id')
            ->join('satuan as s', 's.id', '=', 'b.satuan_id')
            ->join('tahap as t', 't.id', '=', 'wb.tahap_id')
            ->whereNull('o.deleted_at')
            ->where('wo.status', '!=', 'dibatalkan')
            ->whereBetween('o.tgl_order', [$f['tgl_dari'], $f['tgl_sampai']]);

        if ($id = $this->filterInt($f, 'bahan_id')) {
            $q->where('wb.bahan_id', $id);
        }

        $baris = $q->orderBy('wo.id')->orderBy('t.urutan')->orderBy('b.nama')->limit(self::MAKS_BARIS)
            ->get(['wo.no_wo', 'o.no_order', 'b.kode', 'b.nama', 't.nama as tahap', 's.kode as satuan', 'wb.qty_rencana', 'wb.qty_keluar', 'wb.qty_retur'])
            ->map(function ($r) {
                $rencana = (float) $r->qty_rencana;
                $aktual = round((float) $r->qty_keluar - (float) $r->qty_retur, 3);
                $selisih = round($aktual - $rencana, 3);

                return [
                    'no_wo' => $r->no_wo,
                    'no_order' => $r->no_order,
                    'kode_bahan' => $r->kode,
                    'bahan' => $r->nama,
                    'tahap' => $r->tahap,
                    'satuan' => $r->satuan,
                    'rencana' => $rencana,
                    'keluar' => (float) $r->qty_keluar,
                    'retur' => (float) $r->qty_retur,
                    'aktual' => $aktual,
                    'selisih' => $selisih,
                    'selisih_persen' => $this->persen($selisih, $rencana, false),
                ];
            });

        return [
            'kolom' => [
                $this->kol('no_wo', 'No WO'), $this->kol('no_order', 'No Order'), $this->kol('kode_bahan', 'Kode Bahan'),
                $this->kol('bahan', 'Bahan'), $this->kol('tahap', 'Tahap'), $this->kol('satuan', 'Satuan'),
                $this->kol('rencana', 'Rencana', 'dec'), $this->kol('keluar', 'Keluar', 'dec'), $this->kol('retur', 'Retur', 'dec'),
                $this->kol('aktual', 'Aktual (Keluar - Retur)', 'dec'), $this->kol('selisih', 'Selisih', 'dec'),
                $this->kol('selisih_persen', 'Selisih %', 'pct'),
            ],
            'baris' => $baris,
            'ringkasan' => [
                $this->ring('Baris Pemakaian', $baris->count(), 'int'),
                $this->ring('Baris Boros (selisih > 0)', $baris->where('selisih', '>', 0)->count(), 'int'),
                $this->ring('Baris Hemat (selisih < 0)', $baris->where('selisih', '<', 0)->count(), 'int'),
            ],
        ];
    }

    // =====================================================================
    // 11. PEMBELIAN
    // =====================================================================

    protected function laporanPembelian(array $f, ?string $scope): array
    {
        $q = DB::table('purchase_orders as po')
            ->join('supplier as s', 's.id', '=', 'po.supplier_id')
            ->leftJoin('purchase_order_items as poi', 'poi.po_id', '=', 'po.id')
            ->whereBetween('po.tgl_po', [$f['tgl_dari'], $f['tgl_sampai']]);

        if (! empty($f['status'])) {
            $q->where('po.status', $f['status']);
        }
        if ($id = $this->filterInt($f, 'supplier_id')) {
            $q->where('po.supplier_id', $id);
        }

        $baris = $q->groupBy('po.id', 'po.no_po', 'po.tgl_po', 'po.tgl_estimasi_tiba', 'po.status', 's.nama')
            ->orderBy('po.tgl_po')->orderBy('po.id')->limit(self::MAKS_BARIS)
            ->selectRaw('po.no_po as no_po, po.tgl_po as tgl_po, po.tgl_estimasi_tiba as tgl_estimasi_tiba, po.status as status, s.nama as supplier, COUNT(poi.id) as jumlah_item, COALESCE(SUM(poi.qty_pesan * poi.harga_satuan), 0) as total_nilai, COALESCE(SUM(poi.qty_diterima * poi.harga_satuan), 0) as nilai_diterima')
            ->get()
            ->map(fn ($r) => [
                'no_po' => $r->no_po,
                'tgl_po' => $r->tgl_po,
                'tgl_estimasi_tiba' => $r->tgl_estimasi_tiba,
                'supplier' => $r->supplier,
                'status' => $r->status,
                'jumlah_item' => (int) $r->jumlah_item,
                'total_nilai' => (float) $r->total_nilai,
                'nilai_diterima' => (float) $r->nilai_diterima,
            ]);

        $efektif = $baris->where('status', '!=', 'dibatalkan');

        return [
            'kolom' => [
                $this->kol('no_po', 'No PO'), $this->kol('tgl_po', 'Tgl PO', 'date'),
                $this->kol('tgl_estimasi_tiba', 'Estimasi Tiba', 'date'), $this->kol('supplier', 'Supplier'),
                $this->kol('status', 'Status', 'status'), $this->kol('jumlah_item', 'Jumlah Item', 'int'),
                $this->kol('total_nilai', 'Nilai PO', 'money'), $this->kol('nilai_diterima', 'Nilai Diterima', 'money'),
            ],
            'baris' => $baris,
            'ringkasan' => [
                $this->ring('Jumlah PO (tanpa dibatalkan)', $efektif->count(), 'int'),
                $this->ring('Total Nilai PO (tanpa dibatalkan)', round((float) $efektif->sum('total_nilai'), 2), 'money'),
                $this->ring('Total Nilai Diterima', round((float) $efektif->sum('nilai_diterima'), 2), 'money'),
            ],
        ];
    }

    // =====================================================================
    // 12. PENERIMAAN BAHAN
    // =====================================================================

    protected function laporanPenerimaan(array $f, ?string $scope): array
    {
        $q = DB::table('penerimaan_bahan_items as pbi')
            ->join('penerimaan_bahan as pb', 'pb.id', '=', 'pbi.penerimaan_id')
            ->join('supplier as s', 's.id', '=', 'pb.supplier_id')
            ->join('bahan as b', 'b.id', '=', 'pbi.bahan_id')
            ->join('satuan as sa', 'sa.id', '=', 'b.satuan_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'pb.po_id')
            ->whereBetween('pb.tgl_terima', [$f['tgl_dari'], $f['tgl_sampai']])
            ->where('pb.status', ! empty($f['status']) && in_array($f['status'], ['aktif', 'void'], true) ? $f['status'] : 'aktif');

        if ($id = $this->filterInt($f, 'supplier_id')) {
            $q->where('pb.supplier_id', $id);
        }
        if ($id = $this->filterInt($f, 'bahan_id')) {
            $q->where('pbi.bahan_id', $id);
        }

        $rows = $q->orderBy('pb.tgl_terima')->orderBy('pb.id')->orderBy('pbi.id')->limit(self::MAKS_BARIS)
            ->get(['pb.id as pb_id', 'pb.no_bm', 'pb.tgl_terima', 's.nama as supplier', 'po.no_po', 'pb.no_sj_supplier', 'b.kode', 'b.nama', 'sa.kode as satuan', 'pbi.qty', 'pbi.harga_satuan']);

        $baris = $rows->map(fn ($r) => [
            'no_bm' => $r->no_bm,
            'tgl_terima' => $r->tgl_terima,
            'supplier' => $r->supplier,
            'no_po' => $r->no_po,
            'no_sj_supplier' => $r->no_sj_supplier,
            'kode_bahan' => $r->kode,
            'bahan' => $r->nama,
            'satuan' => $r->satuan,
            'qty' => (float) $r->qty,
            'harga_satuan' => $r->harga_satuan !== null ? (float) $r->harga_satuan : null,
            'nilai' => $r->harga_satuan !== null ? round((float) $r->qty * (float) $r->harga_satuan, 2) : null,
        ]);

        return [
            'kolom' => [
                $this->kol('no_bm', 'No BM'), $this->kol('tgl_terima', 'Tgl Terima', 'date'), $this->kol('supplier', 'Supplier'),
                $this->kol('no_po', 'No PO'), $this->kol('no_sj_supplier', 'SJ Supplier'), $this->kol('kode_bahan', 'Kode Bahan'),
                $this->kol('bahan', 'Bahan'), $this->kol('satuan', 'Satuan'), $this->kol('qty', 'Qty', 'dec'),
                $this->kol('harga_satuan', 'Harga Satuan', 'money'), $this->kol('nilai', 'Nilai', 'money'),
            ],
            'baris' => $baris,
            'ringkasan' => [
                $this->ring('Jumlah Dokumen Penerimaan', $rows->pluck('pb_id')->unique()->count(), 'int'),
                $this->ring('Jumlah Baris Bahan', $baris->count(), 'int'),
                $this->ring('Total Nilai', round((float) $baris->sum('nilai'), 2), 'money'),
            ],
        ];
    }

    // =====================================================================
    // 13. PENGIRIMAN
    // =====================================================================

    protected function laporanPengiriman(array $f, ?string $scope): array
    {
        $q = DB::table('pengiriman as sj')
            ->join('orders as o', 'o.id', '=', 'sj.order_id')
            ->join('pelanggan as p', 'p.id', '=', 'o.pelanggan_id')
            ->whereRaw('COALESCE(sj.tgl_kirim, DATE(sj.created_at)) BETWEEN ? AND ?', [$f['tgl_dari'], $f['tgl_sampai']]);

        if (! empty($f['status'])) {
            $q->where('sj.status', $f['status']);
        }
        if ($id = $this->filterInt($f, 'pelanggan_id')) {
            $q->where('o.pelanggan_id', $id);
        }

        $rows = $q->orderBy('sj.id')->limit(self::MAKS_BARIS)
            ->get(['sj.id', 'sj.no_sj', 'sj.tgl_kirim', 'o.no_order', 'p.nama as pelanggan', 'sj.ekspedisi', 'sj.no_resi', 'sj.status', 'sj.tgl_diterima']);

        $isi = DB::table('pengiriman_koli as pk')
            ->join('packing_items as pbi', 'pbi.packing_id', '=', 'pk.packing_id')
            ->whereIn('pk.pengiriman_id', $rows->pluck('id')->all())
            ->groupBy('pk.pengiriman_id')
            ->selectRaw('pk.pengiriman_id as pengiriman_id, COUNT(DISTINCT pk.packing_id) as koli, SUM(pbi.qty) as qty')
            ->get()
            ->keyBy('pengiriman_id');

        $baris = $rows->map(fn ($r) => [
            'no_sj' => $r->no_sj,
            'tgl_kirim' => $r->tgl_kirim,
            'no_order' => $r->no_order,
            'pelanggan' => $r->pelanggan,
            'ekspedisi' => $r->ekspedisi,
            'no_resi' => $r->no_resi,
            'status' => $r->status,
            'jumlah_koli' => (int) ($isi->get($r->id)->koli ?? 0),
            'total_qty' => (int) ($isi->get($r->id)->qty ?? 0),
            'tgl_diterima' => $r->tgl_diterima,
        ]);

        $efektif = $baris->where('status', '!=', 'dibatalkan');

        return [
            'kolom' => [
                $this->kol('no_sj', 'No SJ'), $this->kol('tgl_kirim', 'Tgl Kirim', 'date'), $this->kol('no_order', 'No Order'),
                $this->kol('pelanggan', 'Pelanggan'), $this->kol('ekspedisi', 'Ekspedisi'), $this->kol('no_resi', 'No Resi'),
                $this->kol('status', 'Status', 'status'), $this->kol('jumlah_koli', 'Koli', 'int'),
                $this->kol('total_qty', 'Total Qty', 'int'), $this->kol('tgl_diterima', 'Tgl Diterima', 'date'),
            ],
            'baris' => $baris,
            'ringkasan' => [
                $this->ring('Jumlah Surat Jalan (tanpa dibatalkan)', $efektif->count(), 'int'),
                $this->ring('Total Koli', (int) $efektif->sum('jumlah_koli'), 'int'),
                $this->ring('Total Qty', (int) $efektif->sum('total_qty'), 'int'),
            ],
        ];
    }

    // =====================================================================
    // 14. ON-TIME DELIVERY
    // =====================================================================

    protected function laporanOnTimeDelivery(array $f, ?string $scope): array
    {
        $rows = $this->orderTerkirimPeriode($f['tgl_dari'], $f['tgl_sampai'])
            ->orderBy('pt.tgl_terkirim')->orderBy('o.id')->limit(self::MAKS_BARIS)
            ->get(['o.id', 'o.no_order', 'p.nama as pelanggan', 'o.deadline', 'pt.tgl_terkirim']);

        $baris = $rows->map(function ($r) {
            $selisih = (int) round((strtotime($r->tgl_terkirim) - strtotime($r->deadline)) / 86400);

            return [
                'no_order' => $r->no_order,
                'pelanggan' => $r->pelanggan,
                'deadline' => $r->deadline,
                'tgl_terkirim' => $r->tgl_terkirim,
                'selisih_hari' => $selisih,
                'tepat_waktu' => $selisih <= 0,
            ];
        });

        $kpi = $this->hitungOnTime($rows);

        return [
            'kolom' => [
                $this->kol('no_order', 'No Order'), $this->kol('pelanggan', 'Pelanggan'), $this->kol('deadline', 'Deadline', 'date'),
                $this->kol('tgl_terkirim', 'Tgl Terkirim (terakhir)', 'date'), $this->kol('selisih_hari', 'Selisih Hari (+ = telat)', 'int'),
                $this->kol('tepat_waktu', 'Tepat Waktu', 'bool'),
            ],
            'baris' => $baris,
            'ringkasan' => [
                $this->ring('Total Order Terkirim', $kpi['total_order_terkirim'], 'int'),
                $this->ring('Tepat Waktu', $kpi['tepat_waktu'], 'int'),
                $this->ring('Terlambat', $kpi['terlambat'], 'int'),
                $this->ring('On-Time Delivery', $kpi['persen'], 'pct'),
            ],
        ];
    }

    // =====================================================================
    // 15. OPNAME
    // =====================================================================

    protected function laporanOpname(array $f, ?string $scope): array
    {
        $q = DB::table('stok_opname_items as oi')
            ->join('stok_opname as op', 'op.id', '=', 'oi.opname_id')
            ->join('bahan as b', 'b.id', '=', 'oi.bahan_id')
            ->join('satuan as s', 's.id', '=', 'b.satuan_id')
            ->whereBetween('op.tanggal', [$f['tgl_dari'], $f['tgl_sampai']]);

        if (! empty($f['status'])) {
            $q->where('op.status', $f['status']);
        }
        if ($id = $this->filterInt($f, 'bahan_id')) {
            $q->where('oi.bahan_id', $id);
        }

        $rows = $q->orderBy('op.tanggal')->orderBy('op.id')->orderBy('oi.id')->limit(self::MAKS_BARIS)
            ->get(['op.id as opname_id', 'op.no_opname', 'op.tanggal', 'op.status', 'b.kode', 'b.nama', 's.kode as satuan', 'oi.stok_sistem', 'oi.stok_fisik', 'oi.selisih', 'oi.alasan']);

        $baris = $rows->map(fn ($r) => [
            'no_opname' => $r->no_opname,
            'tanggal' => $r->tanggal,
            'status' => $r->status,
            'kode_bahan' => $r->kode,
            'bahan' => $r->nama,
            'satuan' => $r->satuan,
            'stok_sistem' => (float) $r->stok_sistem,
            'stok_fisik' => (float) $r->stok_fisik,
            'selisih' => (float) $r->selisih,
            'alasan' => $r->alasan,
        ]);

        return [
            'kolom' => [
                $this->kol('no_opname', 'No Opname'), $this->kol('tanggal', 'Tanggal', 'date'), $this->kol('status', 'Status', 'status'),
                $this->kol('kode_bahan', 'Kode Bahan'), $this->kol('bahan', 'Bahan'), $this->kol('satuan', 'Satuan'),
                $this->kol('stok_sistem', 'Stok Sistem', 'dec'), $this->kol('stok_fisik', 'Stok Fisik', 'dec'),
                $this->kol('selisih', 'Selisih', 'dec'), $this->kol('alasan', 'Alasan'),
            ],
            'baris' => $baris,
            'ringkasan' => [
                $this->ring('Jumlah Dokumen Opname', $rows->pluck('opname_id')->unique()->count(), 'int'),
                $this->ring('Baris Bahan', $baris->count(), 'int'),
                $this->ring('Baris Ada Selisih', $baris->filter(fn (array $b) => abs($b['selisih']) > 0.0005)->count(), 'int'),
            ],
        ];
    }

    // =====================================================================
    // 16. AUDIT LOG
    // =====================================================================

    protected function laporanAuditLog(array $f, ?string $scope): array
    {
        $q = DB::table('activity_log as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->whereBetween('al.created_at', [$f['tgl_dari'].' 00:00:00', $f['tgl_sampai'].' 23:59:59']);

        if ($id = $this->filterInt($f, 'user_id')) {
            $q->where('al.user_id', $id);
        }
        if (! empty($f['aksi'])) {
            $q->where('al.aksi', $f['aksi']);
        }
        if (! empty($f['subjek_tipe'])) {
            $q->where('al.subjek_tipe', $f['subjek_tipe']);
        }

        $baris = $q->orderByDesc('al.created_at')->orderByDesc('al.id')->limit(self::MAKS_BARIS)
            ->get(['al.created_at', 'u.name as user', 'al.aksi', 'al.subjek_tipe', 'al.subjek_id', 'al.alasan', 'al.sebelum', 'al.sesudah', 'al.ip'])
            ->map(fn ($r) => [
                'waktu' => $r->created_at,
                'user' => $r->user ?? 'Sistem',
                'aksi' => $r->aksi,
                'subjek_tipe' => $r->subjek_tipe,
                'subjek_id' => $r->subjek_id !== null ? (int) $r->subjek_id : null,
                'alasan' => $r->alasan,
                'sebelum' => $r->sebelum,
                'sesudah' => $r->sesudah,
                'ip' => $r->ip,
            ]);

        return [
            'kolom' => [
                $this->kol('waktu', 'Waktu', 'datetime'), $this->kol('user', 'User'), $this->kol('aksi', 'Aksi'),
                $this->kol('subjek_tipe', 'Subjek'), $this->kol('subjek_id', 'ID', 'int'), $this->kol('alasan', 'Alasan'),
                $this->kol('sebelum', 'Sebelum', 'text', null, false), $this->kol('sesudah', 'Sesudah', 'text', null, false),
                $this->kol('ip', 'IP'),
            ],
            'baris' => $baris,
            'ringkasan' => [$this->ring('Jumlah Aktivitas', $baris->count(), 'int')],
        ];
    }
}