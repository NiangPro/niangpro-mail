<?php

declare(strict_types=1);

namespace Niang\Core\Notifications;

use Niang\Core\Contracts\NotificationChannel;
use Niang\Core\Exceptions\NotificationException;
use Niang\Core\Http\Client;
use Niang\Core\Notification;

/**
 * POST JSON de Notification::toWebhook() vers Notification::webhookUrl(), via Http\Client (sans
 * extension, http/https seulement, redirections non suivies). Avec un secret, en-tête
 * X-Niang-Signature: sha256=<HMAC du corps> pour que le destinataire vérifie l'origine. Une réponse
 * hors 2xx lève une erreur (et, via la file, le job est retenté).
 */
final class WebhookChannel implements NotificationChannel
{
    public function send(array $notifiable, Notification $notification): void
    {
        $url = $notification->webhookUrl($notifiable);
        $body = json_encode($notification->toWebhook($notifiable), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'User-Agent' => 'NiangPro-Webhook'];
        $secret = $notification->webhookSecret();

        if ($secret !== null && $secret !== '') {
            $headers['X-Niang-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        try {
            $status = Client::request('POST', $url, $headers, $body)['status'];
        } catch (\InvalidArgumentException $e) {
            throw new NotificationException('Canal webhook : ' . $e->getMessage(), 0, $e);
        } catch (\RuntimeException $e) {
            throw new NotificationException('Canal webhook : ' . $e->getMessage(), 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            throw new NotificationException("Canal webhook : $url a répondu $status.");
        }
    }
}
