<?php

declare(strict_types=1);

namespace Niang\Core;

use Niang\Core\Jobs\SendQueuedMail;

/** Retourné par Mail::to() : Mail::to($email)->cc(...)->bcc(...)->send($mailable), ou ->queue($mailable). */
class PendingMail
{
    /** @var list<string> */
    private array $cc = [];

    /** @var list<string> */
    private array $bcc = [];

    public function __construct(private string $address)
    {
    }

    /** @param string|list<string> $addresses */
    public function cc(string|array $addresses): static
    {
        array_push($this->cc, ...(array) $addresses);
        return $this;
    }

    /** Copie cachée : les autres destinataires ne voient jamais ces adresses. @param string|list<string> $addresses */
    public function bcc(string|array $addresses): static
    {
        array_push($this->bcc, ...(array) $addresses);
        return $this;
    }

    public function send(Mailable $mailable): void
    {
        Mail::dispatch($this->address, $mailable, $this->cc, $this->bcc);
    }

    /**
     * Envoi différé par la file (traitée par `niang queue:work`) : la requête HTTP n'attend pas le
     * serveur SMTP. Le Mailable est sérialisé : pas de closure ni de connexion dans ses propriétés.
     *
     * @return string identifiant du job
     */
    public function queue(Mailable $mailable, string $queue = 'default'): string
    {
        return Queue::push(new SendQueuedMail($this->address, $mailable, $this->cc, $this->bcc), $queue);
    }

    /** Comme queue(), envoyé au plus tôt dans $delaySeconds secondes. */
    public function later(int $delaySeconds, Mailable $mailable, string $queue = 'default'): string
    {
        return Queue::later($delaySeconds, new SendQueuedMail($this->address, $mailable, $this->cc, $this->bcc), $queue);
    }
}
