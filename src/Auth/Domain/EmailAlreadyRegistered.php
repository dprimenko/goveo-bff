<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * El correo ya tiene cuenta.
 *
 * Tiene excepción propia porque **no es un fallo del sistema, es una respuesta**:
 * quien se registra con un correo que ya usó necesita que se lo digan, y la app
 * ya sabe enseñarlo («ese email ya tiene cuenta») si recibe un 409.
 *
 * Sin ella, `registerUser` lanzaba un `RuntimeException` como el de cualquier
 * otro problema, el controlador lo trataba como avería y respondía **503**: en
 * la app se leía «no podemos conectar ahora mismo, inténtalo en un momento».
 * El usuario reintenta —el correo sigue existiendo—, vuelve a leer lo mismo, y
 * acaba pensando que la app está rota en vez de recordar que ya tenía cuenta.
 */
final class EmailAlreadyRegistered extends \RuntimeException
{
    public function __construct(public readonly string $email)
    {
        parent::__construct(sprintf('El correo %s ya tiene cuenta.', $email));
    }
}
