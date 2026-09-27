<?php

declare(strict_types=1);

namespace App\Moderation\Domain;

enum ReportStatus: string
{
    /** Esperando a que alguien la mire. */
    case Open = 'open';
    /** Mirada, y no había nada que quitar. */
    case Dismissed = 'dismissed';
    /** Mirada, y se retiró el contenido o la cuenta. */
    case Actioned = 'actioned';
}
