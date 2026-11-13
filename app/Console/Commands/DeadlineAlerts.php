<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationService;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeadlineAlerts extends Command
{
    protected $signature = 'sima:deadline-alerts';

    protected $description = 'Notifikasi order yang deadline-nya mendekati atau terlambat (maksimal satu per order per hari)';

    public function handle(): int
    {
        $batas = Setting::int('deadline_alert_hari', 3);
        $hariIni = today();

        $orders = DB::table('orders as o')
            ->join('pelanggan as p', 'p.id', '=', 'o.pelanggan_id')
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', ['menunggu_bahan', 'siap_produksi', 'produksi', 'siap_kirim', 'dikirim_sebagian'])
            ->whereDate('o.deadline', '<=', $hariIni->copy()->addDays($batas)->toDateString())
            ->get(['o.id', 'o.no_order', 'o.deadline', 'o.dibuat_oleh', 'p.nama as pelanggan']);

        $manajerIds = User::where('is_active', true)->where('role', 'manajer')->pluck('id');
        $stafOrderIds = User::where('is_active', true)->where('role', 'staf_order')->pluck('id');
        $terkirim = 0;

        foreach ($orders as $o) {
            $kunci = "deadline-{$o->id}-{$hariIni->toDateString()}";
            if (NotificationService::sudahTerkirimHariIni($kunci)) {
                continue;
            }

            $deadline = Carbon::parse($o->deadline)->startOfDay();
            $sisa = (int) round($hariIni->diffInDays($deadline, false));
            $tanggal = $deadline->format('d-m-Y');

            if ($sisa < 0) {
                $judul = 'Order terlambat';
                $pesan = "Order {$o->no_order} ({$o->pelanggan}) terlambat ".abs($sisa)." hari dari deadline {$tanggal}.";
                $tipe = 'bahaya';
            } else {
                $judul = 'Deadline order mendekati';
                $pesan = "Deadline order {$o->no_order} ({$o->pelanggan}) ".($sisa === 0 ? 'hari ini' : "tinggal {$sisa} hari")." ({$tanggal}).";
                $tipe = 'peringatan';
            }

            // Sesuai matriks notifikasi: seluruh Manajer dan Staf Order aktif menerima alert.
            $penerima = $manajerIds->concat($stafOrderIds)->filter()->unique()->values()->all();
            NotificationService::toUsers($penerima, $judul, $pesan, null, $tipe, $kunci);
            $terkirim++;
        }

        $this->info("Alert deadline terkirim untuk {$terkirim} order.");

        return self::SUCCESS;
    }
}