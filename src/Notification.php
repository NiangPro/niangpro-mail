<?php

declare(strict_types=1);

namespace Niang\Core;

use Niang\Core\Contracts\NotificationChannel;
use Niang\Core\Contracts\ShouldQueue;
use Niang\Core\Database\DB;
use Niang\Core\Database\QueryBuilder;
use Niang\Core\Exceptions\NotificationException;
use Niang\Core\Jobs\SendQueuedNotification;
use Niang\Core\Notifications\DatabaseChannel;
use Niang\Core\Notifications\MailChannel;
use Niang\Core\Notifications\WebhookChannel;

/**
 * Une notification = un message envoyé à un destinataire sur un ou plusieurs canaux :
 *
 *   class CommandeExpediee extends Notification
 *   {
 *       public function via(array $user): array { return ['mail', 'database']; }
 *       public function toMail(array $user): Mailable { return new CommandeExpedieeMailable(...); }
 *       public function toDatabase(array $user): array { return ['commande' => 42]; }
 *   }
 *
 *   Notification::send($user, new CommandeExpediee($commande));
 *
 * Le destinataire est un tableau (une ligne de users, comme partout dans le framework) ; le canal
 * mail utilise sa colonne `email`. Une notification qui implémente ShouldQueue part par la file.
 */
abstract class Notification
{
    private const CHANNELS = [
        'mail' => MailChannel::class,
        'database' => DatabaseChannel::class,
        'webhook' => WebhookChannel::class,
    ];

    /** @var list<array{notifiable: array, notification: Notification, channels: list<string>}> */
    private static array $sent = [];
    private static bool $faked = false;

    /**
     * Canaux : 'mail', 'database', 'webhook', ou le nom d'une classe NotificationChannel.
     *
     * @param array<string, mixed> $notifiable
     * @return list<string>
     */
    abstract public function via(array $notifiable): array;

    /** @param array<string, mixed> $notifiable */
    public function toMail(array $notifiable): Mailable
    {
        throw new NotificationException(static::class . " envoie sur le canal mail mais n'a pas de méthode toMail().");
    }

    /**
     * Données enregistrées dans la table notifications (colonne data, en JSON).
     *
     * @param array<string, mixed> $notifiable
     * @return array<string, mixed>
     */
    public function toDatabase(array $notifiable): array
    {
        throw new NotificationException(static::class . " envoie sur le canal database mais n'a pas de méthode toDatabase().");
    }

    /**
     * Corps JSON envoyé au webhook.
     *
     * @param array<string, mixed> $notifiable
     * @return array<string, mixed>
     */
    public function toWebhook(array $notifiable): array
    {
        throw new NotificationException(static::class . " envoie sur le canal webhook mais n'a pas de méthode toWebhook().");
    }

    /** @param array<string, mixed> $notifiable */
    public function webhookUrl(array $notifiable): string
    {
        throw new NotificationException(static::class . " envoie sur le canal webhook mais n'a pas de méthode webhookUrl().");
    }

    /** Secret partagé avec le destinataire du webhook : le corps est alors signé (en-tête X-Niang-Signature). */
    public function webhookSecret(): ?string
    {
        return null;
    }

    /**
     * Envoie à un destinataire ou à une liste de destinataires. Une notification ShouldQueue est
     * mise en file (un job par destinataire) ; sinon elle part immédiatement.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $notifiables
     */
    final public static function send(array $notifiables, Notification $notification): void
    {
        foreach (self::normalize($notifiables) as $notifiable) {
            if ($notification instanceof ShouldQueue && !self::$faked) {
                Queue::push(new SendQueuedNotification($notifiable, $notification));
                continue;
            }

            self::deliver($notifiable, $notification);
        }
    }

    /**
     * Envoie immédiatement, même pour une notification ShouldQueue.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $notifiables
     */
    final public static function sendNow(array $notifiables, Notification $notification): void
    {
        foreach (self::normalize($notifiables) as $notifiable) {
            self::deliver($notifiable, $notification);
        }
    }

    /**
     * @internal Notification::send() et le job SendQueuedNotification
     *
     * @param array<string, mixed> $notifiable
     */
    final public static function deliver(array $notifiable, Notification $notification): void
    {
        $channels = $notification->via($notifiable);

        if (self::$faked) {
            self::$sent[] = ['notifiable' => $notifiable, 'notification' => $notification, 'channels' => $channels];
            return;
        }

        foreach ($channels as $channel) {
            self::channel($channel)->send($notifiable, $notification);
        }
    }

    /**
     * Notifications du destinataire (canal database), les plus récentes d'abord.
     *
     * @return list<array<string, mixed>>
     */
    final public static function for(array $notifiable, bool $unreadOnly = false): array
    {
        $query = self::query($notifiable)->orderBy('id', 'desc');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return array_map(fn (array $row) => ['data' => json_decode((string) $row['data'], true)] + $row, $query->get());
    }

    /** @return list<array<string, mixed>> */
    final public static function unread(array $notifiable): array
    {
        return self::for($notifiable, true);
    }

    final public static function unreadCount(array $notifiable): int
    {
        return self::query($notifiable)->whereNull('read_at')->count();
    }

    /**
     * Toujours limité au destinataire : un utilisateur ne peut pas marquer comme lue la
     * notification d'un autre en changeant l'identifiant dans l'URL.
     */
    final public static function markAsRead(array $notifiable, int|string $id): bool
    {
        return self::markRead($notifiable, 'AND id = ?', [$id]) === 1;
    }

    /** @return int nombre de notifications marquées */
    final public static function markAllAsRead(array $notifiable): int
    {
        return self::markRead($notifiable);
    }

    /** @param list<int|string> $bindings */
    private static function markRead(array $notifiable, string $extra = '', array $bindings = []): int
    {
        self::query($notifiable); // vérifie la colonne id

        return DB::affected(
            "UPDATE notifications SET read_at = ? WHERE notifiable_type = ? AND notifiable_id = ? AND read_at IS NULL $extra",
            [date('Y-m-d H:i:s'), self::notifiableType(), (string) $notifiable['id'], ...$bindings]
        );
    }

    /** En test : les notifications sont enregistrées (sent()) au lieu d'être envoyées, même ShouldQueue. */
    final public static function fake(): void
    {
        self::$faked = true;
        self::$sent = [];
    }

    /** @return list<array{notifiable: array, notification: Notification, channels: list<string>}> */
    final public static function sent(): array
    {
        return self::$sent;
    }

    /** @internal remet l'état par défaut entre deux tests (appelée par TestCase::setUp()). */
    final public static function reset(): void
    {
        self::$faked = false;
        self::$sent = [];
    }

    /** @internal utilisée par DatabaseChannel */
    final public static function notifiableType(): string
    {
        return Auth::model();
    }

    private static function query(array $notifiable): QueryBuilder
    {
        if (!isset($notifiable['id'])) {
            throw new NotificationException("Le destinataire d'une notification doit avoir une colonne id.");
        }

        return (new QueryBuilder('notifications'))
            ->where('notifiable_type', self::notifiableType())
            ->where('notifiable_id', (string) $notifiable['id']);
    }

    private static function channel(string $channel): NotificationChannel
    {
        $class = self::CHANNELS[$channel] ?? $channel;

        if (!class_exists($class) || !is_subclass_of($class, NotificationChannel::class)) {
            throw new NotificationException("Canal de notification inconnu : « $channel » (mail, database, webhook, ou une classe NotificationChannel).");
        }

        return new $class();
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $notifiables
     * @return list<array<string, mixed>>
     */
    private static function normalize(array $notifiables): array
    {
        return array_is_list($notifiables) && ($notifiables === [] || is_array($notifiables[0])) ? $notifiables : [$notifiables];
    }
}
