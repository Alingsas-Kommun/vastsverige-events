<?php

declare(strict_types=1);

namespace VastsverigeEvents\Import;

use DateTimeImmutable;

/**
 * Merges API details that share a heading into one event.
 */
class EventMerger
{
    /**
     * Bump when the mapping changes so unchanged events are rewritten once.
     */
    public const MAPPING_VERSION = 3;

    private string $origin;

    public function __construct(
        private string $apiUrl = 'https://www.vastsverige.com/api',
        private OccasionBuilder $builder = new OccasionBuilder(),
        private OccasionFolder $folder = new OccasionFolder(),
    ) {
        $parts        = parse_url($apiUrl) ?: [];
        $this->origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'www.vastsverige.com') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Heading as shown to editors: tags removed, whitespace collapsed.
     */
    public static function cleanTitle(string $heading): string
    {
        $plain = html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $plain));
    }

    /**
     * Details with the same key belong to one event.
     */
    public static function groupKey(string $heading): string
    {
        return mb_strtolower(self::cleanTitle($heading));
    }

    /**
     * @param list<array<string, mixed>> $details
     * @param array<int, string>|null    $categories Category labels by id, or null when unavailable.
     */
    public function merge(array $details, ?array $categories, ?DateTimeImmutable $today = null): MergedEvent
    {
        usort($details, static fn (array $a, array $b): int => (int) ($a['Id'] ?? 0) <=> (int) ($b['Id'] ?? 0));

        $sourceIds = [];
        $dates     = [];
        $names     = [];

        foreach ($details as $detail) {
            $id = (int) ($detail['Id'] ?? 0);
            if ($id > 0) {
                $sourceIds[(string) $id] = (string) $id;
            }

            foreach ($this->occasionsOf($detail, count($details) === 1) as $moment) {
                $dates[$moment['start']->format('c') . '|' . $moment['end']->format('c')] = $moment;
            }

            foreach ($detail['Categories']['Event'] ?? [] as $categoryId) {
                $label = $categories[(int) $categoryId] ?? '';
                if ($label !== '') {
                    $names[$label] = $label;
                }
            }
        }

        $first = static function (string $key) use ($details): string {
            foreach ($details as $detail) {
                $value = trim((string) ($detail[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }

            return '';
        };

        $title     = self::cleanTitle($first('Heading'));
        $booking   = $this->firstUrl(array_map(static fn (array $d): mixed => $d['Contact']['BookingLink'] ?? '', $details));
        $folded    = $this->folder->fold(array_values($dates), $today);
        $canonical = $sourceIds === [] ? '0' : (string) min(array_map('intval', $sourceIds));
        $position  = $this->position($details);
        $image     = $this->firstImage($details);
        $place     = $this->place($first('City'));

        return new MergedEvent(
            title: $title,
            content: $this->content($first('Tagline'), $first('Description'), $first('FacebookLink'), $first('InstagramLink')),
            url: $this->publicUrl($first('Url')),
            locationName: $place,
            address: self::distinctAddress($place, $place),
            lat: $position['lat'],
            lng: $position['lng'],
            image: $image['url'],
            imageAlt: $image['alt'] !== '' ? $image['alt'] : $title,
            categories: $categories === null ? null : array_values($names),
            contact: $this->contact($details),
            occasions: $this->withBookingLink($folded['rows'], $booking),
            dropped: $folded['dropped'],
            uid: $this->uid($canonical),
            sourceIds: $this->sortedIds($sourceIds),
        );
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array{start: DateTimeImmutable, end: DateTimeImmutable}>
     */
    private function occasionsOf(array $detail, bool $soleDetail): array
    {
        $dates = is_array($detail['Dates'] ?? null) ? $detail['Dates'] : [];
        $end   = isset($detail['End']) ? (string) $detail['End'] : null;

        return $this->builder->build($dates, $end, $soleDetail);
    }

    /**
     * @param array<string, string> $ids
     * @return list<string>
     */
    private function sortedIds(array $ids): array
    {
        $values = array_values($ids);
        usort($values, static fn (string $a, string $b): int => (int) $a <=> (int) $b);

        return $values;
    }

    /**
     * The address, or an empty string when it only repeats the place name.
     *
     * Event pages print the name and the address on separate lines, so a repeat reads as a typo.
     */
    public static function distinctAddress(string $name, string $address): string
    {
        $normalise = static fn (string $value): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));

        return $normalise($name) === $normalise($address) ? '' : trim($address);
    }

    /**
     * Tagline as lead paragraph, the description, then social links.
     */
    private function content(string $tagline, string $description, string $facebook, string $instagram): string
    {
        $parts = [];

        $plain = html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($tagline !== '' && mb_stripos($plain, $tagline) === false) {
            $parts[] = '<p class="vastsverige-tagline"><strong>' . htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8') . '</strong></p>';
        }

        if ($description !== '') {
            $parts[] = $description;
        }

        $links = [];
        foreach (['Facebook' => $facebook, 'Instagram' => $instagram] as $label => $link) {
            $valid = $this->validUrl($link);
            if ($valid !== '') {
                $links[] = '<a href="' . htmlspecialchars($valid, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
            }
        }
        if ($links !== []) {
            $parts[] = '<p class="vastsverige-links">' . implode(' · ', $links) . '</p>';
        }

        return implode("\n", $parts);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withBookingLink(array $rows, string $booking): array
    {
        return array_map(static function (array $row) use ($booking): array {
            $row['url'] = $booking;
            return $row;
        }, $rows);
    }

    /**
     * @param list<array<string, mixed>> $details
     * @return array{lat: string, lng: string}
     */
    private function position(array $details): array
    {
        foreach ($details as $detail) {
            $lat = $detail['Position']['lat'] ?? null;
            $lng = $detail['Position']['lng'] ?? null;
            if (is_numeric($lat) && is_numeric($lng) && ((float) $lat !== 0.0 || (float) $lng !== 0.0)) {
                return ['lat' => (string) $lat, 'lng' => (string) $lng];
            }
        }

        return ['lat' => '', 'lng' => ''];
    }

    /**
     * @param list<array<string, mixed>> $details
     * @return array{url: string, alt: string}
     */
    private function firstImage(array $details): array
    {
        foreach ($details as $detail) {
            $path = trim((string) ($detail['Image']['Path'] ?? ''));
            if ($path !== '') {
                return ['url' => $this->absoluteUrl($path), 'alt' => trim((string) ($detail['Image']['Alt'] ?? ''))];
            }
        }

        return ['url' => '', 'alt' => ''];
    }

    /**
     * @param list<array<string, mixed>> $details
     * @return array<string, string>
     */
    private function contact(array $details): array
    {
        foreach ($details as $detail) {
            $contact = is_array($detail['Contact'] ?? null) ? $detail['Contact'] : [];
            $name    = trim((string) ($contact['CompanyName'] ?? ''));
            $email   = trim((string) ($contact['Email'] ?? ''));
            if ($name === '' && $email === '') {
                continue;
            }

            return [
                'name'    => $name !== '' ? $name : $email,
                'email'   => $email,
                'phone'   => trim((string) ($contact['Phone'] ?? '')),
                'website' => $this->validUrl((string) ($contact['Website'] ?? '')),
                'address' => $this->streetAddress($contact),
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $contact
     */
    private function streetAddress(array $contact): string
    {
        $street = trim((string) ($contact['Street'] ?? ''));
        if ($street !== '') {
            return $street;
        }

        return trim(trim((string) ($contact['Zip'] ?? '')) . ' ' . trim((string) ($contact['City'] ?? '')));
    }

    /**
     * Collapses whitespace and title-cases names written in capitals.
     */
    private function place(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value !== '' && mb_strlen($value) > 1 && $value === mb_strtoupper($value) && $value !== mb_strtolower($value)) {
            return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
        }

        return $value;
    }

    /**
     * @param list<mixed> $candidates
     */
    private function firstUrl(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $valid = $this->validUrl((string) $candidate);
            if ($valid !== '') {
                return $valid;
            }
        }

        return '';
    }

    private function validUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('#^https?://#i', $value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        return $value;
    }

    private function publicUrl(string $path): string
    {
        return $path === '' ? '' : $this->absoluteUrl($path);
    }

    private function absoluteUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if (str_starts_with($path, '//')) {
            return 'https:' . $path;
        }

        return $this->origin . (str_starts_with($path, '/') ? $path : '/' . $path);
    }

    private function uid(string $canonicalId): string
    {
        return 'vast-sverige-' . substr(md5($this->apiUrl), 0, 8) . '-' . $canonicalId;
    }
}
