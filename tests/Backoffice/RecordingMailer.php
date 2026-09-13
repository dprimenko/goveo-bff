<?php

declare(strict_types=1);

namespace App\Tests\Backoffice;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Se queda los correos en vez de mandarlos.
 *
 * En fichero propio —y no dentro del test que lo estrenó— porque lo usan varios:
 * con PSR-4, una clase de ayuda escondida en otro fichero sólo existe si PHPUnit
 * ya lo había cargado, y el test que la usaba pasaba o no según el orden.
 */
final class RecordingMailer implements MailerInterface
{
    /** @var Email[] */
    private array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($message instanceof Email) {
            $this->sent[] = $message;
        }
    }

    /** @return string[] */
    public function recipients(): array
    {
        return array_map(
            static fn (Email $mail): string => $mail->getTo()[0]->getAddress(),
            $this->sent,
        );
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function last(): ?Email
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }

    /** @return string[] */
    public function subjects(): array
    {
        return array_map(static fn (Email $mail): string => (string) $mail->getSubject(), $this->sent);
    }
}
