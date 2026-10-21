<?php

declare(strict_types=1);

namespace VastsverigeEvents\Tests\Support;

use VastsverigeEvents\Api\ApiException;
use VastsverigeEvents\Api\EventSource;
use VastsverigeEvents\Api\IdList;

/**
 * In-memory API for importer tests.
 */
final class FakeSource implements EventSource
{
    /** @var array<int, array<string, mixed>|null> */
    public array $details = [];

    /** @var array<int, string>|null */
    public ?array $categories = [1 => 'Musik'];

    public ?int $total = null;

    public bool $listFails = false;

    /** @var list<int> */
    public array $failingDetails = [];

    public bool $categoriesFail = false;

    /** @var list<int> */
    public array $requestedDetails = [];

    /**
     * @param array<int, array<string, mixed>|null> $details
     */
    public function __construct(array $details = [])
    {
        $this->details = $details;
    }

    public function listIds(string $configId): IdList
    {
        if ($this->listFails) {
            throw new ApiException('list down');
        }

        return new IdList(array_keys($this->details), $this->total);
    }

    public function detail(int $id): ?array
    {
        $this->requestedDetails[] = $id;
        if (in_array($id, $this->failingDetails, true)) {
            throw new ApiException('detail down');
        }

        return $this->details[$id] ?? null;
    }

    public function categories(): array
    {
        if ($this->categoriesFail) {
            throw new ApiException('categories down');
        }

        return $this->categories ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(int $id, string $heading, string $date = '2026-11-05T00:00:00+01:00'): array
    {
        return [
            'Id'         => $id,
            'Heading'    => $heading,
            'Description' => '<p>About ' . $heading . '</p>',
            'City'       => 'Alingsås',
            'Url'        => '/alingsas/evenemang/' . $id . '/',
            'End'        => $date,
            'Categories' => ['Event' => [1]],
            'Dates'      => [['Date' => $date, 'Times' => [['Start' => '18:00', 'End' => '20:00']]]],
        ];
    }
}
