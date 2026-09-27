<?php

declare(strict_types=1);

namespace Niang\Core\Exceptions;

/** Canal inconnu, méthode toMail()/toDatabase()/toWebhook() manquante, webhook refusé... */
class NotificationException extends \RuntimeException
{
}
