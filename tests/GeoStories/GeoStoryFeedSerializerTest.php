<?php

declare(strict_types=1);

namespace App\Tests\GeoStories;

use App\GeoStories\Domain\GeoStoryWithDistance;
use App\GeoStories\Infrastructure\Service\GeoStoryFeedSerializer;
use PHPUnit\Framework\TestCase;

/** La forma de un vídeo en el feed, la misma que en Guardados. */
final class GeoStoryFeedSerializerTest extends TestCase
{
    private static function story(array $row = []): GeoStoryWithDistance
    {
        return GeoStoryWithDistance::fromRow($row + [
            'id'          => 'a',
            'status'      => 'ready',
            'lat'         => '40.4',
            'long'        => '-3.7',
            'dist_meters' => '12.5',
        ]);
    }

    public function testLinkWithoutActionDefaultsToInfo(): void
    {
        $item = GeoStoryFeedSerializer::serialize(self::story(['meta' => '{"link_url":"https://x.es"}']));

        self::assertSame('https://x.es', $item['link_url']);
        self::assertSame('info', $item['link_action']);
    }

    public function testNoLinkNoAction(): void
    {
        $item = GeoStoryFeedSerializer::serialize(self::story(['meta' => '{"link_action":"buy"}']));

        self::assertNull($item['link_url']);
        self::assertNull($item['link_action']);
    }

    public function testProgressOnlyWhileProcessing(): void
    {
        $ready = GeoStoryFeedSerializer::serialize(self::story(['provider_video_id' => 'v']), 40);
        $processing = GeoStoryFeedSerializer::serialize(
            self::story(['status' => 'processing', 'provider_video_id' => 'v']),
            40,
        );

        self::assertNull($ready['processing_progress']);
        self::assertSame(40, $processing['processing_progress']);
    }
}
