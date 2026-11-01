<?php

namespace App\Events;

use App\Models\Announcement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AnnouncementNotificationRequested
{
    use Dispatchable, SerializesModels;

    public array $data;
    public Announcement $announcement;

    public function __construct(Announcement $announcement, array $data)
    {
        $this->announcement = $announcement;
        $this->data = $data;
    }
}