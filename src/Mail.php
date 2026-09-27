<?php

declare(strict_types=1);

namespace Niang\Core;

use Niang\Core\Exceptions\ConfigurationException;

/**
 * Trois drivers, pilotés par MAIL_MAILER dans .env ('log' par défaut) :
 *  - 'smtp' : envoi réel via Niang\Core\SmtpTransport (MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION,
 *    MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS, MAIL_FROM_NAME) — le driver de production ;
 *  - 'log' : écrit le contenu de l'email dans storage/logs/ via Log — pratique en développement,
 *    sans configuration ni serveur SMTP ;
 *  - 'array' : garde les emails envoyés en mémoire du process, pour les assertions de test
 *    (voir fake()/sent()).
 * Une valeur inconnue lève une ConfigurationException plutôt que de retomber silencieusement sur
 * 'log' : une faute de frappe en production ne doit pas faire disparaître des emails sans bruit.
 */
class Mail
{
    /** @var array<int, array{to: string, cc: list<string>, bcc: list<string>, mailable: Mailable}> */
    private static array $sent = [];
    private static bool $faked = false;

    public static function to(string $address): PendingMail
    {
        return new PendingMail($address);
    }

    /**
     * @internal appelé par PendingMail::send() et le job SendQueuedMail
     *
     * @param list<string> $cc
     * @param list<string> $bcc
     */
    public static function dispatch(string $to, Mailable $mailable, array $cc = [], array $bcc = []): void
    {
        $mailer = self::$faked ? 'array' : (string) Env::get('MAIL_MAILER', 'log');

        if ($mailer === 'array') {
            self::$sent[] = ['to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'mailable' => $mailable];
            return;
        }

        if ($mailer === 'smtp') {
            SmtpTransport::fromEnv()->send($to, $mailable, $cc, $bcc);
            return;
        }

        if ($mailer !== 'log') {
            throw new ConfigurationException("MAIL_MAILER inconnu : « $mailer » (attendu : smtp, log ou array).");
        }

        // Le corps complet est loggé (utile en dev pour lire un lien de vérification sans boîte mail
        // réelle) : ne pas garder MAIL_MAILER=log en production si vos emails contiennent des secrets.
        Log::info('Email à {to} : {subject}', [
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $mailable->subject(),
            'body' => $mailable->body(),
            'attachments' => array_map(fn (MailAttachment $attachment) => $attachment->name, $mailable->attachments()),
        ]);
    }

    /** Bascule sur le driver 'array' pour la suite du test, quel que soit MAIL_MAILER dans .env. */
    public static function fake(): void
    {
        self::$faked = true;
        self::$sent = [];
    }

    /** @return array<int, array{to: string, cc: list<string>, bcc: list<string>, mailable: Mailable}> rempli seulement après fake() ou avec MAIL_MAILER=array */
    public static function sent(): array
    {
        return self::$sent;
    }

    /** @internal remet Mail dans son état par défaut — à appeler en tearDown entre deux tests. */
    public static function reset(): void
    {
        self::$faked = false;
        self::$sent = [];
    }
}
