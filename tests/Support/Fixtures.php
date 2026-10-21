<?php

declare(strict_types=1);

namespace VastsverigeEvents\Tests\Support;

/**
 * Real API payloads, trimmed, kept under tests/fixtures.
 */
final class Fixtures
{
    /**
     * @return array<string, mixed> Detail payload for an event id from the live API.
     */
    public static function detail(int $id): array
    {
        $all = self::load('details.json');

        return $all[(string) $id] ?? throw new \OutOfBoundsException('No fixture for id ' . $id);
    }

    public static function categoriesBody(): string
    {
        return (string) file_get_contents(self::path('categories.json'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(string $file): array
    {
        return json_decode((string) file_get_contents(self::path($file)), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function path(string $file): string
    {
        return dirname(__DIR__) . '/fixtures/' . $file;
    }
}
