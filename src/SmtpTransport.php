<?php

declare(strict_types=1);

namespace Niang\Core;

use Niang\Core\Exceptions\MailException;

/**
 * Client SMTP écrit à la main (RFC 5321), sans extension ni dépendance : une socket PHP
 * (stream_socket_client), STARTTLS ou TLS implicite, AUTH PLAIN/LOGIN, puis le message au format
 * RFC 5322 — texte seul, ou texte + HTML (multipart/alternative) si le Mailable fournit html().
 *
 * Deux choix de sécurité assumés :
 *  - MAIL_ENCRYPTION=tls (le défaut) exige STARTTLS : si le serveur ne le propose pas, l'envoi
 *    échoue plutôt que de continuer en clair.
 *  - un mot de passe n'est jamais envoyé sur une connexion non chiffrée, sauf vers localhost
 *    (Mailpit, MailHog... en développement).
 */
final class SmtpTransport
{
    private const CRLF = "\r\n";

    /** @var resource|null */
    private $socket = null;

    /** @var list<string> extensions annoncées par le serveur en réponse à EHLO (en majuscules) */
    private array $extensions = [];

    /**
     * @param array{
     *     host: string, port: int, encryption: string, username: ?string, password: ?string,
     *     from_address: string, from_name: ?string, timeout: int, ehlo_domain: string,
     *     stream_options?: array
     * } $config
     */
    public function __construct(private array $config)
    {
        if (!in_array($config['encryption'], ['tls', 'ssl', 'none'], true)) {
            throw new MailException("MAIL_ENCRYPTION invalide : « {$config['encryption']} » (attendu : tls, ssl ou none).");
        }
    }

    /** Configuration lue dans .env (MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION, MAIL_USERNAME...). */
    public static function fromEnv(): static
    {
        $host = (string) Env::get('MAIL_HOST', '');
        $from = (string) Env::get('MAIL_FROM_ADDRESS', '');

        if ($host === '' || $from === '') {
            throw new MailException('MAIL_MAILER=smtp demande MAIL_HOST et MAIL_FROM_ADDRESS dans .env.');
        }

        $encryption = strtolower((string) Env::get('MAIL_ENCRYPTION', 'tls'));
        $appHost = parse_url((string) Env::get('APP_URL', ''), PHP_URL_HOST);

        return new static([
            'host' => $host,
            'port' => (int) Env::get('MAIL_PORT', $encryption === 'ssl' ? '465' : '587'),
            'encryption' => $encryption,
            'username' => Env::get('MAIL_USERNAME') ?: null,
            'password' => Env::get('MAIL_PASSWORD') ?: null,
            'from_address' => $from,
            'from_name' => Env::get('MAIL_FROM_NAME', Env::get('APP_NAME', 'NiangPro')) ?: null,
            'timeout' => (int) Env::get('MAIL_TIMEOUT', '10'),
            'ehlo_domain' => is_string($appHost) && $appHost !== '' ? $appHost : 'localhost',
        ]);
    }

    /**
     * @param list<string> $cc  visibles de tous (en-tête Cc)
     * @param list<string> $bcc copie cachée : reçoivent le message (RCPT TO) sans jamais apparaître dans ses en-têtes
     */
    public function send(string $to, Mailable $mailable, array $cc = [], array $bcc = []): void
    {
        $recipients = array_values(array_unique([$to, ...$cc, ...$bcc]));

        foreach ($recipients as $recipient) {
            $this->assertAddress($recipient);
        }

        $this->assertAddress($this->config['from_address']);
        $message = $this->buildMessage($to, $mailable, $cc);

        try {
            $this->connect();
            $this->hello();

            if ($this->config['encryption'] === 'tls') {
                $this->startTls();
            }

            $this->authenticate();

            $this->command('MAIL FROM:<' . $this->config['from_address'] . '>', [250]);
            foreach ($recipients as $recipient) {
                $this->command("RCPT TO:<$recipient>", [250, 251]);
            }

            $this->command('DATA', [354]);
            $this->command($this->dotStuff($message) . self::CRLF . '.', [250]);
            $this->command('QUIT', [221]);
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Message complet (en-têtes + corps), lignes terminées par CRLF. Aucun en-tête Bcc : le
     * mettre dans le message le révélerait à tous les destinataires.
     *
     * @param list<string> $cc
     */
    public function buildMessage(string $to, Mailable $mailable, array $cc = []): string
    {
        foreach ($cc as $address) {
            $this->assertAddress($address);
        }

        $subject = $mailable->subject();

        if (preg_match('/[\r\n]/', $subject) === 1) {
            throw new MailException("Le sujet d'un email ne peut pas contenir de retour à la ligne.");
        }

        $domain = substr(strrchr($this->config['from_address'], '@') ?: '@localhost', 1);
        $from = $this->config['from_name'] !== null
            ? $this->encodeHeader($this->config['from_name']) . ' <' . $this->config['from_address'] . '>'
            : $this->config['from_address'];

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $from,
            'To: <' . $to . '>',
            ...($cc !== [] ? ['Cc: ' . implode(', ', array_map(fn (string $address) => "<$address>", $cc))] : []),
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
        ];

        [$contentHeaders, $body] = $this->buildContent($mailable->body(), $mailable->html());
        $attachments = $mailable->attachments();

        if ($attachments === []) {
            return implode(self::CRLF, [...$headers, ...$contentHeaders]) . self::CRLF . self::CRLF . $body;
        }

        // multipart/mixed : le contenu (texte, ou texte + HTML) d'abord, puis une partie par pièce jointe.
        $boundary = 'niang-mixed-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $message = implode(self::CRLF, $headers) . self::CRLF . self::CRLF
            . '--' . $boundary . self::CRLF
            . implode(self::CRLF, $contentHeaders) . self::CRLF . self::CRLF . $body . self::CRLF;

        foreach ($attachments as $attachment) {
            $message .= '--' . $boundary . self::CRLF . $this->buildAttachment($attachment) . self::CRLF;
        }

        return $message . '--' . $boundary . '--';
    }

    /** @return array{0: list<string>, 1: string} en-têtes Content-* et corps encodé */
    private function buildContent(string $text, ?string $html): array
    {
        if ($html === null) {
            return [['Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: quoted-printable'], $this->encodeBody($text)];
        }

        $boundary = 'niang-' . bin2hex(random_bytes(12));

        $part = fn (string $type, string $content): string => '--' . $boundary . self::CRLF
            . "Content-Type: $type; charset=UTF-8" . self::CRLF
            . 'Content-Transfer-Encoding: quoted-printable' . self::CRLF . self::CRLF
            . $this->encodeBody($content) . self::CRLF;

        return [
            ['Content-Type: multipart/alternative; boundary="' . $boundary . '"'],
            $part('text/plain', $text) . $part('text/html', $html) . '--' . $boundary . '--',
        ];
    }

    /**
     * Nom ASCII entre guillemets ; nom accentué selon RFC 2231 (filename*) SEUL — avec un repli
     * filename= à côté, certains clients mail afficheraient le repli. Guillemets et retours à la
     * ligne neutralisés (injection d'en-têtes).
     */
    private function buildAttachment(MailAttachment $attachment): string
    {
        $name = str_replace(["\r", "\n", '"', '\\', '/'], ' ', $attachment->name);
        $mime = preg_match('#^[\w.+-]+/[\w.+-]+$#', $attachment->mime()) === 1 ? $attachment->mime() : 'application/octet-stream';
        $ascii = preg_match('/[^\x20-\x7E]/', $name) !== 1;

        $typeName = $ascii ? "\"$name\"" : '"' . $this->encodeHeader($name) . '"';
        $disposition = $ascii ? "filename=\"$name\"" : "filename*=UTF-8''" . rawurlencode($name);

        return "Content-Type: $mime; name=$typeName" . self::CRLF
            . 'Content-Transfer-Encoding: base64' . self::CRLF
            . "Content-Disposition: attachment; $disposition" . self::CRLF . self::CRLF
            . rtrim(chunk_split(base64_encode($attachment->content()), 76, self::CRLF));
    }

    private function connect(): void
    {
        $scheme = $this->config['encryption'] === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create($this->config['stream_options'] ?? []);

        $socket = @stream_socket_client(
            "$scheme://{$this->config['host']}:{$this->config['port']}",
            $errorCode,
            $errorMessage,
            $this->config['timeout'],
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new MailException("Connexion SMTP impossible à {$this->config['host']}:{$this->config['port']} : $errorMessage ($errorCode).");
        }

        stream_set_timeout($socket, $this->config['timeout']);
        $this->socket = $socket;

        $this->expect([220], 'connexion');
    }

    private function hello(): void
    {
        $response = $this->command('EHLO ' . $this->config['ehlo_domain'], [250]);

        // La première ligne est le salut du serveur ; les suivantes, ses extensions (STARTTLS, AUTH PLAIN LOGIN...).
        $this->extensions = array_map(
            fn (string $line) => strtoupper(trim(substr($line, 4))),
            array_slice(explode("\n", trim($response)), 1)
        );
    }

    private function startTls(): void
    {
        if (!$this->supports('STARTTLS')) {
            throw new MailException(
                "Le serveur SMTP {$this->config['host']} ne propose pas STARTTLS. L'envoi est interrompu plutôt que de "
                . 'continuer en clair — utilisez MAIL_ENCRYPTION=ssl (port 465) ou, en local seulement, MAIL_ENCRYPTION=none.'
            );
        }

        $this->command('STARTTLS', [220]);

        $crypto = @stream_socket_enable_crypto(
            $this->socket,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
        );

        if ($crypto !== true) {
            throw new MailException("Négociation TLS échouée avec {$this->config['host']} (certificat invalide ?).");
        }

        // RFC 3207 : tout ce qui a été annoncé avant TLS est oublié, on redemande.
        $this->hello();
    }

    private function authenticate(): void
    {
        $username = $this->config['username'];

        if ($username === null) {
            return;
        }

        if ($this->config['encryption'] === 'none' && !in_array($this->config['host'], ['localhost', '127.0.0.1', '::1'], true)) {
            throw new MailException(
                'Refus d\'envoyer MAIL_USERNAME/MAIL_PASSWORD sur une connexion non chiffrée vers '
                . "{$this->config['host']} : utilisez MAIL_ENCRYPTION=tls ou ssl."
            );
        }

        $password = (string) $this->config['password'];
        $auth = $this->authMechanisms();

        if (in_array('PLAIN', $auth, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0$username\0$password"), [235], 'AUTH PLAIN');
        } elseif (in_array('LOGIN', $auth, true)) {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($username), [334], 'AUTH LOGIN (identifiant)');
            $this->command(base64_encode($password), [235], 'AUTH LOGIN (mot de passe)');
        } else {
            throw new MailException('Le serveur SMTP ne propose ni AUTH PLAIN ni AUTH LOGIN (' . implode(', ', $auth) . ').');
        }
    }

    /** @return list<string> ex. ['PLAIN', 'LOGIN'] */
    private function authMechanisms(): array
    {
        foreach ($this->extensions as $extension) {
            if (str_starts_with($extension, 'AUTH ') || str_starts_with($extension, 'AUTH=')) {
                return preg_split('/\s+/', trim(substr($extension, 5))) ?: [];
            }
        }

        return [];
    }

    private function supports(string $extension): bool
    {
        foreach ($this->extensions as $announced) {
            if ($announced === $extension || str_starts_with($announced, "$extension ")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Envoie une commande et vérifie le code de réponse. $label remplace la commande dans le message
     * d'erreur quand elle contient un secret (AUTH) — jamais de mot de passe dans une exception ou un log.
     *
     * @param list<int> $expected
     */
    private function command(string $line, array $expected, ?string $label = null): string
    {
        if (@fwrite($this->socket, $line . self::CRLF) === false) {
            throw new MailException('Connexion SMTP interrompue pendant l\'envoi.');
        }

        return $this->expect($expected, $label ?? explode("\r\n", $line, 2)[0]);
    }

    /** @param list<int> $expected */
    private function expect(array $expected, string $context): string
    {
        $response = '';

        // Réponse multi-lignes : « 250-... » continue, « 250 ... » termine.
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;

            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        if ($response === '') {
            $timedOut = stream_get_meta_data($this->socket)['timed_out'];
            throw new MailException('Le serveur SMTP n\'a pas répondu' . ($timedOut ? ' à temps' : '') . " ($context).");
        }

        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $expected, true)) {
            throw new MailException("Réponse SMTP inattendue à « $context » : " . trim($response));
        }

        return $response;
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    /** Protège les en-têtes : une adresse avec un retour à la ligne injecterait des en-têtes (Bcc...). */
    private function assertAddress(string $address): void
    {
        if (preg_match('/[\r\n<>]/', $address) === 1 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException("Adresse email invalide : « $address ».");
        }
    }

    /** RFC 2047 : un en-tête non ASCII (accents dans le sujet ou le nom) est encodé en base64 UTF-8. */
    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function encodeBody(string $content): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);

        return quoted_printable_encode(str_replace("\n", self::CRLF, $normalized));
    }

    /** RFC 5321 §4.5.2 : une ligne qui commence par « . » est doublée, sinon le serveur y verrait la fin du message. */
    private function dotStuff(string $message): string
    {
        return preg_replace('/^\./m', '..', $message);
    }
}
