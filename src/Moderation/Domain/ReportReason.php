<?php

declare(strict_types=1);

namespace App\Moderation\Domain;

/**
 * Por qué se denuncia. La app enseña la lista traducida; aquí se guarda la
 * intención, como con `link_action`, para que el panel la lea en su idioma.
 *
 * `Blocked` no se elige: es la denuncia que deja un bloqueo. Apple pide que
 * bloquear a alguien **avise también a quien modera**, y así cae en la misma
 * cola que el resto en vez de tener una propia que nadie mire.
 */
enum ReportReason: string
{
    case Spam      = 'spam';
    case Sexual    = 'sexual';
    case Violence  = 'violence';
    case Hate      = 'hate';
    case Fraud     = 'fraud';
    case FalseInfo = 'false_info';
    case Other     = 'other';
    case Blocked   = 'blocked';

    /** Lo que puede mandar quien denuncia: todo menos lo que pone el bloqueo. */
    public static function tryFromUser(?string $value): ?self
    {
        $reason = $value === null ? null : self::tryFrom(strtolower(trim($value)));

        return $reason === self::Blocked ? null : $reason;
    }

    /** Para el correo y el panel. */
    public function label(): string
    {
        return match ($this) {
            self::Spam      => 'Spam o publicidad engañosa',
            self::Sexual    => 'Contenido sexual',
            self::Violence  => 'Violencia o contenido peligroso',
            self::Hate      => 'Odio o acoso',
            self::Fraud     => 'Fraude o estafa',
            self::FalseInfo => 'Información falsa',
            self::Other     => 'Otro motivo',
            self::Blocked   => 'Bloqueado por un usuario',
        };
    }
}
