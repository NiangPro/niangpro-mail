<?php

declare(strict_types=1);

namespace Niang\Core\Jobs;

use Niang\Core\Job;
use Niang\Core\Mail;
use Niang\Core\Mailable;

/** Poussé par Mail::to(...)->queue() : l'envoi (SMTP, parfois lent) sort de la requête HTTP. */
class SendQueuedMail extends Job
{
    /** Un serveur SMTP momentanément indisponible ne fait pas perdre l'email. */
    public int $tries = 3;

    /**
     * @param list<string> $cc
     * @param list<string> $bcc
     */
    public function __construct(
        public readonly string $to,
        public readonly Mailable $mailable,
        public readonly array $cc = [],
        public readonly array $bcc = [],
    ) {
    }

    public function handle(): void
    {
        Mail::dispatch($this->to, $this->mailable, $this->cc, $this->bcc);
    }
}
