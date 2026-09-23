<?php

declare(strict_types=1);

namespace App\EventScraping\Application;

use App\Influencers\Domain\Influencer;
use App\Influencers\Domain\InfluencerRepository;
use App\Users\Domain\User;
use App\Users\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * El perfil «Agenda Goveo · {ciudad}», dueño de lo importado cuya sala no está
 * en Goveo (y de todo lo del Ayuntamiento que no se reconoce).
 *
 * Es un publisher —un influencer— porque una geostory tiene que ser de alguien,
 * y lo es de **uno por ciudad** para que el perfil tenga sentido al abrirlo.
 *
 * Se crea solo la primera vez, con un usuario sin correo detrás: nadie entra
 * con él, sólo existe para ser dueño.
 */
final class AgendaPublisher
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        private readonly InfluencerRepository $influencers,
        private readonly UserRepository $users,
    ) {}

    public function forCity(string $city): string
    {
        $username = 'agenda-goveo-' . $this->slug($city);

        if (isset($this->cache[$username])) {
            return $this->cache[$username];
        }

        $existing = $this->influencers->findByUsername($username);
        if ($existing !== null) {
            return $this->cache[$username] = $existing->getId();
        }

        $user = new User(id: Uuid::v4()->toRfc4122(), email: null, name: 'Agenda Goveo');
        $this->users->save($user);

        $influencer = new Influencer(
            id: Uuid::v4()->toRfc4122(),
            userId: $user->getId(),
            username: $username,
            name: 'Agenda Goveo · ' . $city,
            bio: sprintf('Lo que pasa en %s esta semana, recogido por Goveo.', $city),
            meta: ['system' => 'event-scraping'],
        );
        $influencer->verify();
        $this->influencers->save($influencer);

        return $this->cache[$username] = $influencer->getId();
    }

    private function slug(string $city): string
    {
        $city = mb_strtolower($city);
        $city = strtr($city, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim(preg_replace('/[^a-z0-9]+/', '-', $city) ?? '', '-');
    }
}
