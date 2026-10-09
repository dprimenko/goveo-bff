<?php

declare(strict_types=1);

namespace App\Installs\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Cuántas instalaciones atribuidas por Branch hubo un día, por combinación de
 * plataforma, canal, feature, campaña y tipo de contenido.
 *
 * **Agregado y nada más**: ni una fila por instalación ni ningún identificador
 * de dispositivo, usuario o IP. La clave es la combinación entera; sumar una
 * instalación es `installs + 1` (ver `InstallCounter`).
 *
 * El día es el natural **de Madrid**, como el mes de las métricas del panel
 * (`MonthWindow`): cortado en UTC, una instalación del 1 a la una de la
 * madrugada caería en el mes anterior.
 *
 * Mapeada sólo para que `doctrine:schema:validate` y `migrations:diff` la
 * conozcan: se lee y se escribe con DBAL, nunca se carga como entidad.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_installs_daily')]
class AppInstallDay
{
    #[ORM\Id]
    #[ORM\Column(name: 'day', type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Id]
    #[ORM\Column(name: 'platform', type: 'string', length: 10)]
    private string $platform;

    #[ORM\Id]
    #[ORM\Column(name: 'channel', type: 'string', length: 32)]
    private string $channel;

    #[ORM\Id]
    #[ORM\Column(name: 'feature', type: 'string', length: 32)]
    private string $feature;

    #[ORM\Id]
    #[ORM\Column(name: 'campaign', type: 'string', length: 64)]
    private string $campaign;

    #[ORM\Id]
    #[ORM\Column(name: 'kind', type: 'string', length: 16)]
    private string $kind;

    #[ORM\Column(name: 'installs', type: 'integer', options: ['default' => 0])]
    private int $installs = 0;

    private function __construct() {}
}
