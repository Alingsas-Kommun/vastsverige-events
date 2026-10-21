<?php

declare(strict_types=1);

namespace VastsverigeEvents\Tests\Import;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use VastsverigeEvents\Api\Payload;
use VastsverigeEvents\Import\EventMerger;
use VastsverigeEvents\Tests\Support\FakeSource;
use VastsverigeEvents\Tests\Support\Fixtures;

final class EventMergerTest extends TestCase
{
    private const API = 'https://www.vastsverige.com/api';

    private EventMerger $merger;

    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->merger = new EventMerger(self::API);
        $this->today  = new DateTimeImmutable('2026-10-21', new DateTimeZone('Europe/Stockholm'));
    }

    /**
     * @return array<int, string>
     */
    private function categories(): array
    {
        return Payload::categories(Fixtures::categoriesBody());
    }

    public function testCleanTitleStripsTagsAndCollapsesWhitespace(): void
    {
        self::assertSame('Jul på is', EventMerger::cleanTitle("  <b>Jul</b>\n  på   is "));
    }

    public function testGroupKeyIgnoresCaseAndSpacing(): void
    {
        self::assertSame(EventMerger::groupKey('Quiz  på Skåål'), EventMerger::groupKey(' quiz på skåål '));
    }

    public function testUidUsesLowestIdAndApiHash(): void
    {
        $event = $this->merger->merge([FakeSource::event(900, 'A'), FakeSource::event(12, 'A'), FakeSource::event(300, 'A')], [], $this->today);

        self::assertSame('vast-sverige-' . substr(md5(self::API), 0, 8) . '-12', $event->uid);
        self::assertSame(['12', '300', '900'], $event->sourceIds);
    }

    public function testSourceIdsSortNumericallyNotAsText(): void
    {
        $event = $this->merger->merge([FakeSource::event(100, 'A'), FakeSource::event(9, 'A')], [], $this->today);

        self::assertSame(['9', '100'], $event->sourceIds);
        self::assertStringEndsWith('-9', $event->uid);
    }

    public function testDatesOfMergedDetailsAreCombinedAndDeduplicated(): void
    {
        $one = FakeSource::event(1, 'A', '2026-11-05T00:00:00+01:00');
        $two = FakeSource::event(2, 'A', '2026-11-12T00:00:00+01:00');
        $dup = FakeSource::event(3, 'A', '2026-11-05T00:00:00+01:00');

        $event = $this->merger->merge([$one, $two, $dup], [], $this->today);

        self::assertSame(['2026-11-05', '2026-11-12'], array_column($event->occasions, 'date'));
    }

    public function testRealQuizBecomesOneWeeklyRowWithEveningHours(): void
    {
        $event = $this->merger->merge([Fixtures::detail(576633)], $this->categories(), $this->today);

        self::assertSame('Quiz på Skåål – varje torsdag!', $event->title);
        self::assertCount(1, $event->occasions);
        self::assertSame('byWeek', $event->occasions[0]['repeat']);
        self::assertSame('19:00:00', $event->occasions[0]['startTime']);
        self::assertSame('22:00:00', $event->occasions[0]['endTime']);
    }

    public function testRealFilmFestivalGetsOpeningHoursFromTimes(): void
    {
        $event = $this->merger->merge([Fixtures::detail(669269)], $this->categories(), $this->today);

        foreach ($event->occasions as $row) {
            self::assertSame('09:00:00', $row['startTime']);
            self::assertSame('19:00:00', $row['endTime']);
        }
    }

    public function testRealLightsFestivalBecomesOnePeriod(): void
    {
        $event = $this->merger->merge([Fixtures::detail(615570)], $this->categories(), $this->today);

        self::assertCount(1, $event->occasions);
        self::assertSame('2026-10-02', $event->occasions[0]['date']);
        self::assertSame('2026-11-01', $event->occasions[0]['untilDate']);
        self::assertSame('19:00:00', $event->occasions[0]['startTime']);
        self::assertSame('23:55:00', $event->occasions[0]['endTime']);
    }

    public function testRealWinterEventSkipsExcludedWeekday(): void
    {
        $event = $this->merger->merge([Fixtures::detail(666144)], $this->categories(), $this->today);

        self::assertSame(['2026-11-28', '2026-11-29'], array_column($event->occasions, 'date'));
        self::assertSame('16:00:00', $event->occasions[0]['startTime']);
    }

    public function testRealTomtetagKeepsFirstSlotOnlyWhenSecondSlotIsEmpty(): void
    {
        $event = $this->merger->merge([Fixtures::detail(672541)], $this->categories(), $this->today);

        self::assertSame('10:00:00', $event->occasions[0]['startTime']);
    }

    public function testBookingLinkIsCopiedToEveryOccasionRow(): void
    {
        $detail                       = FakeSource::event(1, 'A');
        $detail['Contact']['BookingLink'] = 'https://tickets.example/1';

        $event = $this->merger->merge([$detail], [], $this->today);

        self::assertSame('https://tickets.example/1', $event->occasions[0]['url']);
    }

    public function testInvalidBookingLinkIsDropped(): void
    {
        $detail                       = FakeSource::event(1, 'A');
        $detail['Contact']['BookingLink'] = 'javascript:alert(1)';

        $event = $this->merger->merge([$detail], [], $this->today);

        self::assertSame('', $event->occasions[0]['url']);
    }

    public function testCategoriesAreMappedFromTheGroup(): void
    {
        $detail                       = FakeSource::event(1, 'A');
        $detail['Categories']['Event'] = [73, 99999];

        $event = $this->merger->merge([$detail], [73 => 'Musik'], $this->today);

        self::assertSame(['Musik'], $event->categories);
    }

    public function testUnavailableCategoriesAreNullSoTermsStayUntouched(): void
    {
        $event = $this->merger->merge([FakeSource::event(1, 'A')], null, $this->today);

        self::assertNull($event->categories);
    }

    public function testKnownButEmptyCategoriesAreAnEmptyList(): void
    {
        $detail                       = FakeSource::event(1, 'A');
        $detail['Categories']['Event'] = [99999];

        $event = $this->merger->merge([$detail], [1 => 'Musik'], $this->today);

        self::assertSame([], $event->categories);
    }

    public function testPlaceNamesAreTrimmedAndCapitalsNormalised(): void
    {
        $messy = FakeSource::event(1, 'A');
        $messy['City'] = '  Alingsås ';
        $caps  = FakeSource::event(2, 'B');
        $caps['City']  = 'ALINGSÅS';
        $venue = FakeSource::event(3, 'C');
        $venue['City'] = 'Nolhaga slott';

        self::assertSame('Alingsås', $this->merger->merge([$messy], [], $this->today)->locationName);
        self::assertSame('Alingsås', $this->merger->merge([$caps], [], $this->today)->locationName);
        self::assertSame('Nolhaga slott', $this->merger->merge([$venue], [], $this->today)->locationName);
    }

    public function testAddressIsLeftOutWhenItOnlyRepeatsThePlaceName(): void
    {
        $detail = FakeSource::event(1, 'A');

        $event = $this->merger->merge([$detail], [], $this->today);

        self::assertSame('Alingsås', $event->locationName);
        self::assertSame('', $event->address);
    }

    public function testDistinctAddressComparesIgnoringCaseAndSpacing(): void
    {
        self::assertSame('', EventMerger::distinctAddress('Alingsås', ' alingsås '));
        self::assertSame('Nygatan 18, Alingsås', EventMerger::distinctAddress('Palladium', 'Nygatan 18, Alingsås'));
        self::assertSame('', EventMerger::distinctAddress('', ''));
    }

    public function testPositionBecomesCoordinatesAndZeroIsIgnored(): void
    {
        $with = FakeSource::event(1, 'A');
        $with['Position'] = ['lat' => 57.9285732, 'lng' => 12.5327027];
        $zero = FakeSource::event(2, 'B');
        $zero['Position'] = ['lat' => 0, 'lng' => 0];

        $event = $this->merger->merge([$with], [], $this->today);

        self::assertSame('57.9285732', $event->lat);
        self::assertSame('12.5327027', $event->lng);
        self::assertSame('', $this->merger->merge([$zero], [], $this->today)->lat);
    }

    public function testContactStreetBecomesOrganizationAddress(): void
    {
        $detail            = FakeSource::event(1, 'A');
        $detail['Contact'] = [
            'CompanyName' => 'Alingsås Turismenhet',
            'Email'       => 'turistinfo@alingsas.se',
            'Phone'       => '+46 322 616200',
            'Website'     => 'https://www.vastsverige.com/en/alingsas/',
            'Street'      => 'Bryggaregatan 2, 441 30 Alingsås, Sverige',
            'City'        => 'Alingsås',
        ];

        $contact = $this->merger->merge([$detail], [], $this->today)->contact;

        self::assertSame('Alingsås Turismenhet', $contact['name']);
        self::assertSame('Bryggaregatan 2, 441 30 Alingsås, Sverige', $contact['address']);
        self::assertSame('+46 322 616200', $contact['phone']);
    }

    public function testContactFallsBackToZipAndCityWithoutStreet(): void
    {
        $detail            = FakeSource::event(1, 'A');
        $detail['Contact'] = ['CompanyName' => 'Förening', 'Zip' => '44155', 'City' => 'Alingsås'];

        self::assertSame('44155 Alingsås', $this->merger->merge([$detail], [], $this->today)->contact['address']);
    }

    public function testContactFallsBackToEmailAsNameAndIsEmptyWithoutBoth(): void
    {
        $mail            = FakeSource::event(1, 'A');
        $mail['Contact'] = ['Email' => 'info@example.org'];
        $none            = FakeSource::event(2, 'B');
        $none['Contact'] = ['Phone' => '123'];

        self::assertSame('info@example.org', $this->merger->merge([$mail], [], $this->today)->contact['name']);
        self::assertSame([], $this->merger->merge([$none], [], $this->today)->contact);
    }

    public function testRelativeImageAndEventUrlsAreMadeAbsolute(): void
    {
        $detail          = FakeSource::event(1, 'A');
        $detail['Image'] = ['Path' => '/globalassets/a.jpg?w=720', 'Alt' => 'En bild'];

        $event = $this->merger->merge([$detail], [], $this->today);

        self::assertSame('https://www.vastsverige.com/globalassets/a.jpg?w=720', $event->image);
        self::assertSame('En bild', $event->imageAlt);
        self::assertSame('https://www.vastsverige.com/alingsas/evenemang/1/', $event->url);
    }

    public function testProtocolRelativeImageGetsHttps(): void
    {
        $detail          = FakeSource::event(1, 'A');
        $detail['Image'] = ['Path' => '//cdn.example/a.jpg'];

        self::assertSame('https://cdn.example/a.jpg', $this->merger->merge([$detail], [], $this->today)->image);
    }

    public function testImageAltFallsBackToTitle(): void
    {
        $detail          = FakeSource::event(1, 'Min titel');
        $detail['Image'] = ['Path' => 'https://x.example/a.jpg', 'Alt' => ''];

        self::assertSame('Min titel', $this->merger->merge([$detail], [], $this->today)->imageAlt);
    }

    public function testTaglineLeadsTheContentUnlessAlreadyInTheDescription(): void
    {
        $detail                = FakeSource::event(1, 'A');
        $detail['Tagline']     = 'Unik tur';
        $detail['Description'] = '<p>Följ med.</p>';
        $again                 = $detail;
        $again['Description']  = '<p>Det här är en <b>unik tur</b>.</p>';

        $event = $this->merger->merge([$detail], [], $this->today);

        self::assertStringStartsWith('<p class="vastsverige-tagline"><strong>Unik tur</strong></p>', $event->content);
        self::assertStringContainsString('<p>Följ med.</p>', $event->content);
        self::assertStringNotContainsString('vastsverige-tagline', $this->merger->merge([$again], [], $this->today)->content);
    }

    public function testSocialLinksAreAppendedWhenValid(): void
    {
        $detail                  = FakeSource::event(1, 'A');
        $detail['FacebookLink']  = 'https://www.facebook.com/x';
        $detail['InstagramLink'] = 'not a url';

        $content = $this->merger->merge([$detail], [], $this->today)->content;

        self::assertStringContainsString('<a href="https://www.facebook.com/x">Facebook</a>', $content);
        self::assertStringNotContainsString('Instagram', $content);
    }

    public function testHashIsStableForSameInputAndChangesWithContent(): void
    {
        $a = $this->merger->merge([FakeSource::event(1, 'A')], [1 => 'Musik'], $this->today);
        $b = $this->merger->merge([FakeSource::event(1, 'A')], [1 => 'Musik'], $this->today);

        $changed                = FakeSource::event(1, 'A');
        $changed['Description'] = '<p>New text</p>';
        $c                      = $this->merger->merge([$changed], [1 => 'Musik'], $this->today);

        self::assertSame($a->hash(), $b->hash());
        self::assertNotSame($a->hash(), $c->hash());
    }

    public function testMissingIdMeansNoSourceIds(): void
    {
        $detail = FakeSource::event(1, 'A');
        unset($detail['Id']);

        self::assertSame([], $this->merger->merge([$detail], [], $this->today)->sourceIds);
    }
}
