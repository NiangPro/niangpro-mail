<?php

declare(strict_types=1);

namespace Niang\Core\Notifications;

use Niang\Core\Contracts\NotificationChannel;
use Niang\Core\Database\QueryBuilder;
use Niang\Core\Exceptions\NotificationException;
use Niang\Core\Notification;

/** Enregistre Notification::toDatabase() dans la table notifications (./bin/niang migrate). */
final class DatabaseChannel implements NotificationChannel
{
    public function send(array $notifiable, Notification $notification): void
    {
        if (!isset($notifiable['id'])) {
            throw new NotificationException("Canal database : le destinataire n'a pas de colonne id.");
        }

        (new QueryBuilder('notifications'))->insert([
            'notifiable_type' => Notification::notifiableType(),
            'notifiable_id' => (string) $notifiable['id'],
            'type' => $notification::class,
            'data' => json_encode($notification->toDatabase($notifiable), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
