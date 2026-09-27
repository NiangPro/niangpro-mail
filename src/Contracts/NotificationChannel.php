<?php

declare(strict_types=1);

namespace Niang\Core\Contracts;

use Niang\Core\Notification;

/**
 * Canal de notification sur mesure (SMS, Slack, push...) : renvoyez le nom de la classe depuis
 * Notification::via(), elle est instanciée par le Container puis send() est appelée.
 */
interface NotificationChannel
{
    /** @param array<string, mixed> $notifiable le destinataire (en général une ligne de users) */
    public function send(array $notifiable, Notification $notification): void;
}
