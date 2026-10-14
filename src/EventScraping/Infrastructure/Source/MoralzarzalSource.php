<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure\Source;

use App\EventScraping\Infrastructure\JsonLd;

/**
 * Agenda del Ayuntamiento de Moralzarzal (moralzarzal.es/agenda), lo de niños.
 *
 * Modern Events Calendar, que no tiene API pública. Se leen dos cosas:
 *
 * - **El RSS de eventos** (`/events/feed/`): los diez próximos, con hora, sitio
 *   y categoría. ⚠️ Ignora `?paged=`: siempre son los diez siguientes.
 * - **La agenda del mes** (`/agenda/`), en datos estructurados: todo el mes,
 *   pero sólo con el día. Lo que no está en el RSS entra de aquí, como evento de
 *   día entero; cuando entre en los diez próximos ya sale con su hora, pero el
 *   importador no reescribe lo ya importado: se queda de día entero.
 */
final class MoralzarzalSource extends MunicipalAgendaSource
{
    private const SITE = 'https://www.moralzarzal.es';

    public function name(): string
    {
        return 'moralzarzal';
    }

    protected function municipality(): string
    {
        return 'Moralzarzal';
    }

    protected function center(): array
    {
        return [40.6791066, -3.971971, 'Pl. de la Constitución, 1, 28411 Moralzarzal, Madrid'];
    }

    protected function places(): array
    {
        return [
            'biblioteca-municipal'           => ['Biblioteca Municipal Casa Grande', 'C. Rondón, 12, 28411 Moralzarzal, Madrid', 40.6790294, -3.9712884],
            'teatro-municipal'               => ['Teatro Municipal de Moralzarzal', 'Av. Salvador Sánchez Frascuelo, 26, 28411 Moralzarzal, Madrid', 40.6798814, -3.9662988],
            'centro-cultural-de-moralzarzal' => ['Centro Cultural de Moralzarzal', 'Av. Salvador Sánchez Frascuelo, 26, 28411 Moralzarzal, Madrid', 40.679634, -3.9661288],
        ];
    }

    protected function raw(): iterable
    {
        $tz   = new \DateTimeZone('Europe/Madrid');
        $seen = [];

        $rss = $this->web->get(self::SITE . '/events/feed/');
        if ($rss === null) {
            throw new \RuntimeException('No se pudo leer el RSS de eventos de Moralzarzal');
        }
        foreach ($this->rss($rss, $tz) as $event) {
            $seen[$event['url']] = true;
            yield $event;
        }

        $html = $this->web->get(self::SITE . '/agenda/');
        if ($html === null) {
            return;
        }
        foreach (JsonLd::events($html) as $node) {
            $url = JsonLd::text($node['url'] ?? null);
            if ($url === null || isset($seen[$url])) {
                continue;
            }
            $location = $node['location'] ?? null;
            $location = is_array($location) && array_is_list($location) ? ($location[0] ?? null) : $location;

            yield [
                'id'          => $this->id($url),
                'title'       => JsonLd::text($node['name'] ?? null, 'name'),
                // Sólo el día: dura el día entero.
                'start'       => $this->day($node['startDate'] ?? null, $tz),
                'end'         => $this->day($node['endDate'] ?? null, $tz)?->setTime(23, 59),
                'place'       => is_array($location) ? (string) ($location['name'] ?? '') : '',
                'description' => JsonLd::text($node['description'] ?? null, 'text'),
                'image'       => JsonLd::text($node['image'] ?? null),
                'url'         => $url,
            ];
        }
    }

    /** @return iterable<array<string, mixed>> */
    private function rss(string $xml, \DateTimeZone $tz): iterable
    {
        $feed = @simplexml_load_string($xml, \SimpleXMLElement::class, \LIBXML_NOCDATA);
        if ($feed === false) {
            throw new \RuntimeException('El RSS de eventos de Moralzarzal no se puede leer');
        }

        foreach ($feed->channel->item as $item) {
            $mec  = $item->children('http://webnus.net/rss/mec/');
            $url  = trim((string) $item->link);
            $day  = trim((string) $mec->startDate);
            $desc = (string) $item->description;

            yield [
                'id'          => $this->id($url),
                'title'       => (string) $item->title,
                'start'       => $this->at($day, (string) $mec->startHour, $tz),
                'end'         => $this->at(trim((string) $mec->endDate) ?: $day, (string) $mec->endHour, $tz, endOfDay: true),
                'place'       => trim((string) $mec->location),
                'description' => strip_tags($desc),
                'image'       => preg_match('/<img[^>]+src="([^"]+)"/', $desc, $m) ? $m[1] : null,
                'url'         => $url,
                'tags'        => trim((string) $mec->category),
            ];
        }
    }

    /** El slug de la ficha: `/events/cuentaller-en-la-biblioteca-382/`. */
    private function id(string $url): string
    {
        return basename(trim((string) parse_url($url, \PHP_URL_PATH), '/'));
    }

    /** «2026-10-22» y «5:30 pm»; sin hora («Evento de todo el día»), el día entero. */
    private function at(string $day, string $hour, \DateTimeZone $tz, bool $endOfDay = false): ?\DateTimeImmutable
    {
        $date = $this->day($day, $tz);
        if ($date === null) {
            return null;
        }
        if (!preg_match('/^(\d{1,2}):(\d{2})\s*(am|pm)$/i', trim($hour), $m)) {
            return $endOfDay ? $date->setTime(23, 59) : $date;
        }
        $h = (int) $m[1] % 12 + (strtolower($m[3]) === 'pm' ? 12 : 0);

        return $date->setTime($h, (int) $m[2]);
    }

    private function day(mixed $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        return is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)
            ? (\DateTimeImmutable::createFromFormat('!Y-m-d', substr($raw, 0, 10), $tz) ?: null)
            : null;
    }
}
