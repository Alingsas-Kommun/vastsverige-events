<?php

declare(strict_types=1);

namespace VastsverigeEvents\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use VastsverigeEvents\Mapping\MediaSideloader;

final class MediaSideloaderTest extends TestCase
{
    public function testJfifBecomesJpg(): void
    {
        self::assertSame('julbord-2026.jpg', MediaSideloader::fileName('/contentassets/abc/julbord-2026.jfif'));
    }

    public function testUsualExtensionsAreKept(): void
    {
        self::assertSame('a.png', MediaSideloader::fileName('/x/a.png'));
        self::assertSame('a.webp', MediaSideloader::fileName('/x/a.webp'));
    }

    public function testEmptyPathFallsBackToADefaultName(): void
    {
        self::assertSame('event.jpg', MediaSideloader::fileName(''));
        self::assertSame('event.jpg', MediaSideloader::fileName('/'));
    }
}
