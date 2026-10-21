<?php

declare(strict_types=1);

namespace VastsverigeEvents\Tests\Import;

use PHPUnit\Framework\TestCase;
use VastsverigeEvents\Import\TrashGuard;

final class TrashGuardTest extends TestCase
{
    /**
     * @return array<int, list<string>>
     */
    private function managed(int $count): array
    {
        $posts = [];
        for ($i = 1; $i <= $count; $i++) {
            $posts[$i * 10] = [(string) $i];
        }

        return $posts;
    }

    public function testNothingToTrashWhenEverythingIsListed(): void
    {
        $plan = (new TrashGuard())->plan($this->managed(10), range(1, 10));

        self::assertSame([], $plan->postIds);
        self::assertNull($plan->refusal);
    }

    public function testPostsMissingFromTheApiAreTrashed(): void
    {
        $plan = (new TrashGuard())->plan($this->managed(10), range(1, 9));

        self::assertSame([100], $plan->postIds);
        self::assertNull($plan->refusal);
    }

    public function testAnEmptyApiListNeverTrashes(): void
    {
        $plan = (new TrashGuard())->plan($this->managed(10), [], true);

        self::assertSame([], $plan->postIds);
        self::assertNotNull($plan->refusal);
    }

    public function testNoManagedPostsMeansNoPlanEvenForAnEmptyList(): void
    {
        $plan = (new TrashGuard())->plan([], []);

        self::assertSame([], $plan->postIds);
        self::assertNull($plan->refusal);
    }

    public function testPostIsKeptWhenAnyOfItsSourceIdsIsStillListed(): void
    {
        $plan = (new TrashGuard())->plan([10 => ['1', '2'], 20 => ['3']], [2, 3]);

        self::assertSame([], $plan->postIds);
    }

    public function testPostWithoutSourceIdsIsNeverTrashed(): void
    {
        $plan = (new TrashGuard())->plan([10 => [], 20 => ['3']], [3]);

        self::assertSame([], $plan->postIds);
    }

    public function testLargeCleanUpIsRefusedUnlessAllowed(): void
    {
        $guard = new TrashGuard(0.3, 5);

        $refused = $guard->plan($this->managed(20), range(1, 10));
        $allowed = $guard->plan($this->managed(20), range(1, 10), true);

        self::assertSame([], $refused->postIds);
        self::assertStringContainsString('Refusing to trash 10 of 20', (string) $refused->refusal);
        self::assertCount(10, $allowed->postIds);
        self::assertNull($allowed->refusal);
    }

    public function testSmallSetsAlwaysAllowTheMinimumNumberOfTrashes(): void
    {
        $plan = (new TrashGuard(0.3, 5))->plan($this->managed(6), [1]);

        self::assertCount(5, $plan->postIds);
        self::assertNull($plan->refusal);
    }

    public function testRatioBoundaryIsInclusive(): void
    {
        $guard = new TrashGuard(0.3, 0);

        self::assertNull($guard->plan($this->managed(10), range(4, 10))->refusal);
        self::assertNotNull($guard->plan($this->managed(10), range(5, 10))->refusal);
    }
}
