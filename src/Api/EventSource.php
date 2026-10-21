<?php

declare(strict_types=1);

namespace VastsverigeEvents\Api;

/**
 * Read access to the Västsverige event API.
 */
interface EventSource
{
    /**
     * @throws ApiException When the list cannot be retrieved in full.
     */
    public function listIds(string $configId): IdList;

    /**
     * @return array<string, mixed>|null Null when the API holds no content for the id.
     * @throws ApiException
     */
    public function detail(int $id): ?array;

    /**
     * Category id to label for the event category group.
     *
     * @return array<int, string>
     * @throws ApiException
     */
    public function categories(): array;
}
