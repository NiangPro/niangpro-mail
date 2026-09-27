<?php

declare(strict_types=1);

namespace Niang\Core;

use Niang\Core\Exceptions\MailException;

/**
 * Pièce jointe d'un Mailable (voir Mailable::attachments()) : un fichier lu au moment de l'envoi,
 * ou un contenu déjà en mémoire (un PDF généré, un export CSV...). Sérialisable, donc utilisable
 * avec Mail::to(...)->queue() — un fichier doit alors encore exister quand le worker envoie.
 */
final class MailAttachment
{
    private function __construct(
        private ?string $path,
        private ?string $data,
        public readonly string $name,
        private ?string $mime,
    ) {
    }

    public static function fromPath(string $path, ?string $name = null, ?string $mime = null): self
    {
        return new self($path, null, $name ?? basename($path), $mime);
    }

    public static function fromData(string $data, string $name, string $mime = 'application/octet-stream'): self
    {
        return new self(null, $data, $name, $mime);
    }

    public function content(): string
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if ($this->path === null || !is_file($this->path) || !is_readable($this->path)) {
            throw new MailException("Pièce jointe introuvable : « {$this->path} ».");
        }

        return (string) file_get_contents($this->path);
    }

    public function mime(): string
    {
        if ($this->mime !== null) {
            return $this->mime;
        }

        if ($this->path !== null && is_file($this->path) && function_exists('finfo_open')) {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($this->path);

            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return 'application/octet-stream';
    }
}
