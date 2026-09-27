<?php

declare(strict_types=1);

namespace Niang\Core\Jobs;

use Niang\Core\Job;
use Niang\Core\Notification;

/** Poussé par Notification::send() pour une notification ShouldQueue : un job par destinataire. */
class SendQueuedNotification extends Job
{
    /** Un serveur SMTP ou un webhook momentanément indisponible ne fait pas perdre la notification. */
    public int $tries = 3;

    /** @param array<string, mixed> $notifiable */
    public function __construct(public readonly array $notifiable, public readonly Notification $notification)
    {
    }

    public function handle(): void
    {
        Notification::deliver($this->notifiable, $this->notification);
    }
}
