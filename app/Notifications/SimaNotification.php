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
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'judul' => $this->judul,
            'pesan' => $this->pesan,
            'url' => $this->url,
            'tipe' => $this->tipe,
        ];
    }
}
