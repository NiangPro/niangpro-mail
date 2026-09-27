<?php

declare(strict_types=1);

namespace Niang\Core;

abstract class Mailable
{
    abstract public function subject(): string;

    /** Version texte, toujours envoyée (lue par les clients mail qui n'affichent pas le HTML). */
    abstract public function body(): string;

    /** Version HTML facultative : si elle est fournie, l'email part en texte + HTML (multipart/alternative). */
    public function html(): ?string
    {
        return null;
    }

    /**
     * Pièces jointes, ex. [MailAttachment::fromPath(Storage::path($facture['path']), 'Facture.pdf')].
     *
     * @return list<MailAttachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
