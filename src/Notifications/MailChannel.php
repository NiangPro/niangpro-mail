<?php

declare(strict_types=1);

namespace Niang\Core\Notifications;

use Niang\Core\Contracts\NotificationChannel;
use Niang\Core\Exceptions\NotificationException;
use Niang\Core\Mail;
use Niang\Core\Notification;

/** Envoie Notification::toMail() à la colonne email du destinataire. */
final class MailChannel implements NotificationChannel
{
    public function send(array $notifiable, Notification $notification): void
    {
        $email = $notifiable['email'] ?? null;

        if (!is_string($email) || $email === '') {
            throw new NotificationException("Canal mail : le destinataire n'a pas de colonne email.");
        }

        Mail::to($email)->send($notification->toMail($notifiable));
    }
}
