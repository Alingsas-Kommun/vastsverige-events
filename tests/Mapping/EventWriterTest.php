<?php

declare(strict_types=1);

namespace VastsverigeEvents\Tests\Mapping;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VastsverigeEvents\Import\EventMerger;
use VastsverigeEvents\Import\MergedEvent;
use VastsverigeEvents\Mapping\EventWriter;
use VastsverigeEvents\Tests\Support\FakeSource;

final class EventWriterTest extends TestCase
{
    private function merge(array $detail): MergedEvent
    {
        return (new EventMerger())->merge([$detail], [], new DateTimeImmutable('2026-10-21'));
    }

    /**
     * Replicates ModularityFrontendForm\FieldFormatting\FormatMapFieldOnSubmit::isSchemaFormat(string $string)
     * hooked to acf/update_value/type=google_map before ACF's own google_map::update_value runs.
     */
    private function stringTypedMapFilter(string $string): string
    {
        return $string;
    }

    public function testLocationAddressPassesStringTypedGoogleMapFiltersAndDecodesForAcf(): void
    {
        $detail             = FakeSource::event(1, 'Konsert');
        $detail['Position'] = ['lat' => 57.9285732, 'lng' => 12.5327027];

        $raw      = EventWriter::locationAddress($this->merge($detail));
        $filtered = $this->stringTypedMapFilter($raw);
        $decoded  = json_decode(stripslashes($filtered), true);

        self::assertSame(
            [
                'lat'  => '57.9285732',
                'lng'  => '12.5327027',
                'zoom' => 14,
            ],
            $decoded
        );
    }

    public function testLocationAddressSurvivesWpUnslashWhenAddressContainsQuotes(): void
    {
        $event = new MergedEvent(
            title: 'Konsert',
            content: '',
            url: '',
            locationName: 'Kafé',
            address: 'Kafé "Solrosen", Alingsås',
            lat: '57.9285732',
            lng: '12.5327027',
            image: '',
            imageAlt: '',
            categories: [],
            contact: [],
            occasions: [],
            dropped: [],
            uid: 'vast-sverige-1',
            sourceIds: ['1'],
        );

        $filtered = $this->stringTypedMapFilter(EventWriter::locationAddress($event));
        $decoded  = json_decode(stripslashes($filtered), true);

        self::assertSame(
            [
                'address' => 'Kafé "Solrosen", Alingsås',
                'lat'     => '57.9285732',
                'lng'     => '12.5327027',
                'zoom'    => 14,
            ],
            $decoded
        );
    }

    public function testEmptyLocationAddressIsAnEmptyString(): void
    {
        $detail = FakeSource::event(1, 'Konsert');

        $filtered = $this->stringTypedMapFilter(EventWriter::locationAddress($this->merge($detail)));

        self::assertSame('', $filtered);
    }
}
