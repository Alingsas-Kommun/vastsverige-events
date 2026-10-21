<?php

declare(strict_types=1);

namespace VastsverigeEvents\Api;

/**
 * Validates and decodes raw API response bodies.
 */
final class Payload
{
    public const CATEGORY_GROUP = 73;

    /**
     * @return array{ids: list<int>, total: int|null}
     * @throws ApiException When the body is not a valid id page.
     */
    public static function idPage(string $body): array
    {
        $payload = self::decode($body, 'id list');
        if (! array_key_exists('IDs', $payload) || ! is_array($payload['IDs'])) {
            throw new ApiException('Id list response has no "IDs" array.');
        }

        $ids = [];
        foreach ($payload['IDs'] as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        $total = isset($payload['TotalCount']) && is_numeric($payload['TotalCount'])
            ? (int) $payload['TotalCount']
            : null;

        return ['ids' => $ids, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null Null for an empty body or an empty object.
     * @throws ApiException When the body is present but not valid JSON.
     */
    public static function detail(string $body): ?array
    {
        $trimmed = trim($body);
        if ($trimmed === '' || $trimmed === 'null') {
            return null;
        }

        $payload = self::decode($body, 'detail');

        return $payload === [] ? null : $payload;
    }

    /**
     * @return array<int, string>
     * @throws ApiException When the body is invalid or the category group is missing.
     */
    public static function categories(string $body): array
    {
        $payload = self::decode($body, 'categories');

        foreach ($payload as $group) {
            if (! is_array($group) || (int) ($group['id'] ?? 0) !== self::CATEGORY_GROUP) {
                continue;
            }

            $map = [];
            foreach ($group['categories'] ?? [] as $category) {
                if (is_array($category) && isset($category['id'], $category['text'])) {
                    $map[(int) $category['id']] = trim((string) $category['text']);
                }
            }

            return $map;
        }

        throw new ApiException(sprintf('Category group %d is missing from the categories response.', self::CATEGORY_GROUP));
    }

    /**
     * @return array<mixed>
     * @throws ApiException
     */
    private static function decode(string $body, string $what): array
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new ApiException(sprintf('The %s response is not valid JSON.', $what));
        }

        return $decoded;
    }
}
