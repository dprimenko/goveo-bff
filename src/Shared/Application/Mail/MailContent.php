<?php

declare(strict_types=1);

namespace App\Shared\Application\Mail;

/**
 * Un correo ya redactado, sin destinatario todavía.
 *
 * Separar el texto del envío es lo que permite verlo sin mandárselo a nadie
 * (`goveo:mail:preview`) y probar la redacción sin base de datos ni SMTP.
 *
 * Lleva **las dos versiones**: el HTML y el texto plano. Sin la segunda, los
 * clientes que no pintan HTML —y los filtros que puntúan el correo por llegar
 * en una sola parte— ven un mensaje vacío.
 */
final readonly class MailContent
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
    ) {}
}
