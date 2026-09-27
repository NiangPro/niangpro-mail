<?php

declare(strict_types=1);

namespace Niang\Core\Exceptions;

/** Envoi d'email impossible : serveur injoignable, réponse SMTP en erreur, adresse invalide... */
class MailException extends \RuntimeException
{
}
