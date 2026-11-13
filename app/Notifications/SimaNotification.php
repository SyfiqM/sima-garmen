<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class SimaNotification extends Notification
{
    public function __construct(
        public string $judul,
        public string $pesan,
        public ?string $url = null,
        public string $tipe = 'info',
        public ?string $kunci = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $data = [
            'judul' => $this->judul,
            'pesan' => $this->pesan,
            'url' => $this->url,
            'tipe' => $this->tipe,
        ];

        // Kunci unik peristiwa (mis. deadline-12-2026-11-13) agar alert terjadwal tidak terkirim ganda.
        if ($this->kunci !== null) {
            $data['kunci'] = $this->kunci;
        }

        return $data;
    }
}