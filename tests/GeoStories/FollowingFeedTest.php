<?php

declare(strict_types=1);

namespace App\Tests\GeoStories;

use App\GeoStories\Infrastructure\Repository\DoctrineGeoStoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** «Siguiendo» sin sesión: lista vacía, y sin llegar a consultar la base. */
final class FollowingFeedTest extends TestCase
{
    public function testFollowingWithoutViewerIsEmptyWithoutQuerying(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getConnection');

        $result = (new DoctrineGeoStoryRepository($em))->findFeed(
            latitude:  40.4,
            longitude: -3.7,
            feedType:  'events',
            viewerId:  null,
            following: true,
        );

        self::assertSame(['items' => [], 'total' => 0], $result);
    }
}
