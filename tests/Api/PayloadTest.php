<?php

declare(strict_types=1);

namespace VastsverigeEvents\Tests\Api;

use PHPUnit\Framework\TestCase;
use VastsverigeEvents\Api\ApiException;
use VastsverigeEvents\Api\IdList;
use VastsverigeEvents\Api\Payload;
use VastsverigeEvents\Tests\Support\Fixtures;

final class PayloadTest extends TestCase
{
    public function testIdPageReadsIdsAndTotal(): void
    {
        $page = Payload::idPage('{"TotalCount":3,"IDs":[1,"2",3]}');

        self::assertSame([1, 2, 3], $page['ids']);
        self::assertSame(3, $page['total']);
    }

    public function testIdPageWithoutTotalReportsNull(): void
    {
        self::assertNull(Payload::idPage('{"IDs":[]}')['total']);
    }

    public function testIdPageSkipsNonNumericIds(): void
    {
        self::assertSame([5], Payload::idPage('{"IDs":[5,"x",null,1.5]}')['ids']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenIdPages(): array
    {
        return [
            'empty body'        => [''],
            'html error page'   => ['<html>Maintenance</html>'],
            'json null'         => ['null'],
            'no IDs key'        => ['{"TotalCount":4}'],
            'IDs not an array'  => ['{"IDs":"nope"}'],
            'bare list'         => ['"abc"'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenIdPages')]
    public function testIdPageRejectsBrokenResponses(string $body): void
    {
        $this->expectException(ApiException::class);

        Payload::idPage($body);
    }

    public function testDetailReturnsNullForEmptyBodies(): void
    {
        self::assertNull(Payload::detail(''));
        self::assertNull(Payload::detail('  '));
        self::assertNull(Payload::detail('null'));
        self::assertNull(Payload::detail('{}'));
        self::assertNull(Payload::detail('[]'));
    }

    public function testDetailThrowsOnGarbage(): void
    {
        $this->expectException(ApiException::class);

        Payload::detail('<html>');
    }

    public function testDetailDecodesObjects(): void
    {
        self::assertSame(['Id' => 7], Payload::detail('{"Id":7}'));
    }

    public function testCategoriesReturnTheEventGroupOnly(): void
    {
        $map = Payload::categories(Fixtures::categoriesBody());

        self::assertNotEmpty($map);
        self::assertContainsOnlyString($map);
        self::assertContainsOnlyInt(array_keys($map));
    }

    public function testCategoriesThrowWhenGroupIsMissing(): void
    {
        $this->expectException(ApiException::class);

        Payload::categories('[{"id":5,"categories":[]}]');
    }

    public function testCategoriesThrowOnInvalidJson(): void
    {
        $this->expectException(ApiException::class);

        Payload::categories('oops');
    }

    public function testIdListCompleteness(): void
    {
        self::assertTrue((new IdList([1, 2], 2))->isComplete());
        self::assertTrue((new IdList([1, 2], null))->isComplete());
        self::assertFalse((new IdList([1], 2))->isComplete());
    }
}
