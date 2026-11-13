<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Penjadwalan SIMA Garmen (Batch 9). Server menjalankan: php artisan schedule:run setiap menit.
Schedule::command('sima:check-integrity')->dailyAt('01:00')->timezone('Asia/Jakarta')->withoutOverlapping();
Schedule::command('sima:deadline-alerts')->dailyAt('07:00')->timezone('Asia/Jakarta')->withoutOverlapping();
Schedule::command('sima:stock-alerts')->dailyAt('07:00')->timezone('Asia/Jakarta')->withoutOverlapping();