<?php

declare(strict_types=1);

namespace App\Tests\GeoStories;

use App\Categories\Domain\Category;
use App\Categories\Domain\CategoryRepository;
use App\GeoStories\Domain\GeoStory;
use App\GeoStories\Infrastructure\Service\StorySchedule;
use PHPUnit\Framework\TestCase;

/**
 * Republicar una noticia desde el panel.
 *
 * Una noticia vive una semana desde que se publica y **reeditarla no le regala
 * otra**: si la fecha se moviera al guardar, bastaría con volver a entrar en
 * ella para que no caducara nunca. Pero quien modera necesita justo lo
 * contrario —volver a sacar una noticia vieja— y por eso la excepción va atada
 * al permiso, no al campo.
 */
final class StoryScheduleNewsDateTest extends TestCase
{
    public function testTheOwnerCannotMoveTheDateOfANewsStory(): void
    {
        $story    = $this->news($original = new \DateTimeImmutable('2025-01-06 10:00:00'));
        $schedule = $this->schedule();

        self::assertNull($schedule->apply($story, 'noticias', '2026-09-15T12:00:00Z', null));

        self::assertEquals($original, $story->getStartedAt());
    }

    public function testModerationRepublishesTheStoryFromTheDateItIsGiven(): void
    {
        $story    = $this->news(new \DateTimeImmutable('2025-01-06 10:00:00'));
        $schedule = $this->schedule();

        self::assertNull($schedule->apply(
            $story,
            'noticias',
            '2026-09-15T12:00:00+00:00',
            null,
            allowManualDates: true,
        ));

        self::assertSame('2026-09-15T12:00:00+00:00', $story->getStartedAt()?->format(\DateTimeInterface::ATOM));
        // La ventana la sigue fijando la regla de la categoría, no quien edita:
        // se recoloca entera desde la fecha nueva.
        self::assertSame('2026-09-22T12:00:00+00:00', $story->getEndedAt()?->format(\DateTimeInterface::ATOM));
    }

    public function testAnUnreadableDateIsRejectedWithoutTouchingTheStory(): void
    {
        $story    = $this->news($original = new \DateTimeImmutable('2025-01-06 10:00:00'));
        $schedule = $this->schedule();

        self::assertSame(
            StorySchedule::ERROR_INVALID_DATE,
            $schedule->apply($story, 'noticias', 'el martes', null, allowManualDates: true),
        );
        self::assertEquals($original, $story->getStartedAt());
    }

    public function testWithoutADateModerationLeavesTheStoryWhereItWas(): void
    {
        // Guardar otra cosa de la noticia —el título— no es republicarla.
        $story    = $this->news($original = new \DateTimeImmutable('2025-01-06 10:00:00'));
        $schedule = $this->schedule();

        self::assertNull($schedule->apply($story, 'noticias', '', null, allowManualDates: true));

        self::assertEquals($original, $story->getStartedAt());
    }

    private function news(\DateTimeImmutable $startedAt): GeoStory
    {
        $story = new GeoStory(
            id:         'geo-1',
            thumbnail:  'https://example.test/t.jpg',
            url:        'https://example.test/v.m3u8',
            title:      'Mercadillo de Navidad',
            categoryId: 'noticias',
        );
        $story->scheduleNews($startedAt);

        return $story;
    }

    private function schedule(): StorySchedule
    {
        return new StorySchedule(new NewsOnlyCategories());
    }
}

/** Sólo hace falta que el slug de la categoría sea `news`. */
final class NewsOnlyCategories implements CategoryRepository
{
    public function findById(string $id): ?Category
    {
        return $this->findBySlug($id);
    }

    public function findBySlug(string $slug): ?Category
    {
        return new Category(id: 'cat-news', name: 'Noticias', slug: 'news');
    }

    public function findAll(): array { return []; }
    public function save(Category $category): void {}
    public function delete(Category $category): void {}
}
