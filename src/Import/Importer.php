<?php

declare(strict_types=1);

namespace VastsverigeEvents\Import;

use DateTimeImmutable;
use VastsverigeEvents\Api\Client;
use VastsverigeEvents\ImportReport;
use VastsverigeEvents\Mapping\EventWriter;
use VastsverigeEvents\Options;
use WP_Error;

/**
 * Fetches Västsverige events and writes Event Manager posts.
 */
class Importer
{
    private const PUBLIC_HOST = 'https://www.vastsverige.com';

    public function __construct(
        private Options $options = new Options(),
        private OccasionFolder $occasions = new OccasionFolder(),
        private EventWriter $writer = new EventWriter(),
    ) {
    }

    /**
     * @param int|null    $limit   Maximum merged events to write. Disables trashing.
     * @param string|null $heading Optional case-insensitive title filter.
     */
    public function run(?int $limit = null, ?string $heading = null): ImportReport
    {
        $report = new ImportReport();

        if (! post_type_exists('event')) {
            $report->fail('Post type "event" is not registered. Activate Event Manager on this site.');
            return $report;
        }

        $this->allowOrganizationTerms();
        $client     = new Client($this->options->apiUrl());
        $listed     = $client->listIds($this->options->configId());
        if ($listed instanceof WP_Error) {
            $report->fail('Id list failed: ' . $listed->get_error_message());
            return $report;
        }

        $categories = $client->categories();
        $failedIds  = [];
        $groups     = [];

        foreach ($listed as $id) {
            $detail = $client->detail($id);
            if ($detail instanceof WP_Error) {
                $failedIds[] = (string) $id;
                $report->add(sprintf('Detail %d failed: %s', $id, $detail->get_error_message()));
                continue;
            }
            if ($detail === null) {
                $report->skipped++;
                continue;
            }

            $title = trim(wp_strip_all_tags((string) ($detail['Heading'] ?? '')));
            if ($title === '') {
                $report->skipped++;
                continue;
            }
            if ($heading !== null && $heading !== '' && ! str_contains(mb_strtolower($title), mb_strtolower($heading))) {
                continue;
            }

            $groups[$title][] = $detail;
        }

        $merged = [];
        foreach ($groups as $title => $details) {
            $merged[] = $this->merge($title, $details, $categories);
        }

        usort($merged, static fn (array $a, array $b): int => strcmp($a['uid'], $b['uid']));
        if ($limit !== null && $limit > 0) {
            $merged = array_slice($merged, 0, $limit);
        }

        foreach ($merged as $event) {
            try {
                $action = $this->writer->save($event, $this->options->postStatus());
            } catch (\RuntimeException $exception) {
                $report->fail(sprintf('"%s" was not saved: %s', $event['title'], $exception->getMessage()));
                continue;
            }

            if ($action === 'created') {
                $report->created++;
            } else {
                $report->updated++;
            }
            if ($event['dropped'] > 0) {
                $report->add(sprintf('"%s" dropped %d occasion rows above the limit of %d.', $event['title'], $event['dropped'], OccasionFolder::MAX_ROWS));
            }
        }

        $complete = ($limit === null || $limit === 0)
            && ($heading === null || $heading === '')
            && $failedIds === []
            && $report->ok;
        if ($complete) {
            $report->trashed = $this->trashMissing($listed);
        }

        return $report;
    }

    /**
     * @param list<array<string, mixed>> $details
     * @param array<int, string>         $categories
     * @return array<string, mixed>
     */
    private function merge(string $title, array $details, array $categories): array
    {
        $sourceIds = [];
        $dates     = [];
        $content   = '';
        $url       = '';
        $city      = '';
        $lat       = '';
        $lng       = '';
        $image     = '';
        $booking   = '';
        $contact   = [];
        $names     = [];
        $eventEnd  = null;

        foreach ($details as $detail) {
            if ($eventEnd === null && ! empty($detail['End'])) {
                $eventEnd = $this->time((string) $detail['End']);
            }
        }

        foreach ($details as $detail) {
            $sourceIds[] = (string) (int) ($detail['Id'] ?? 0);
            if ($content === '' && ! empty($detail['Description'])) {
                $content = (string) $detail['Description'];
            }
            if ($url === '' && ! empty($detail['Url'])) {
                $url = $this->publicUrl((string) $detail['Url']);
            }
            if ($city === '') {
                $city = trim((string) ($detail['City'] ?? ''));
            }
            if ($lat === '' && isset($detail['Position']['lat'], $detail['Position']['lng'])) {
                $lat = (string) $detail['Position']['lat'];
                $lng = (string) $detail['Position']['lng'];
            }
            if ($image === '' && ! empty($detail['Image']['Path'])) {
                $image = $this->absoluteUrl((string) $detail['Image']['Path']);
            }
            if ($booking === '' && ! empty($detail['Contact']['BookingLink'])) {
                $booking = (string) $detail['Contact']['BookingLink'];
            }
            if ($contact === [] && is_array($detail['Contact'] ?? null)) {
                $contact = $detail['Contact'];
            }
            foreach ($detail['Categories']['Event'] ?? [] as $categoryId) {
                $label = $categories[(int) $categoryId] ?? '';
                if ($label !== '') {
                    $names[$label] = $label;
                }
            }
            foreach ($detail['Dates'] ?? [] as $date) {
                if (! is_array($date) || empty($date['Date'])) {
                    continue;
                }
                $start = $this->time((string) $date['Date']);
                if ($start === null) {
                    continue;
                }
                $end = $this->dateEnd($date, $start, $eventEnd);
                $dates[] = ['start' => $start, 'end' => $end];
            }
        }

        $sourceIds = array_values(array_filter(array_unique($sourceIds), static fn (string $id): bool => $id !== '0'));
        sort($sourceIds, SORT_NATURAL);
        $canonical = $sourceIds[0] ?? '0';
        $folded    = $this->occasions->fold($dates);

        return [
            'title'      => $title,
            'content'    => $content,
            'url'        => $url,
            'city'       => $city,
            'lat'        => $lat,
            'lng'        => $lng,
            'image'      => $image,
            'categories' => array_values($names),
            'contact'    => $contact,
            'booking'    => $booking,
            'occasions'  => $folded['rows'],
            'dropped'    => $folded['dropped'],
            'uid'        => $this->uid($canonical),
            'sourceIds'  => $sourceIds,
        ];
    }

    /**
     * @param array<string, mixed> $date
     */
    private function dateEnd(array $date, DateTimeImmutable $start, ?DateTimeImmutable $eventEnd): DateTimeImmutable
    {
        foreach (['End', 'end', 'EndDate'] as $key) {
            if (! empty($date[$key])) {
                $parsed = $this->time((string) $date[$key]);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        if ($eventEnd !== null && $eventEnd->format('Y-m-d') === $start->format('Y-m-d')) {
            return $eventEnd;
        }

        return $start;
    }

    private function time(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function publicUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return self::PUBLIC_HOST . (str_starts_with($path, '/') ? $path : '/' . $path);
    }

    private function absoluteUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $parts  = wp_parse_url($this->options->apiUrl());
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'www.vastsverige.com');
        if (str_starts_with($path, '//')) {
            return 'https:' . $path;
        }

        return $origin . (str_starts_with($path, '/') ? $path : '/' . $path);
    }

    private function uid(string $canonicalId): string
    {
        $short = substr(md5($this->options->apiUrl()), 0, 8);
        return 'vast-sverige-' . $short . '-' . $canonicalId;
    }

    /**
     * @param list<int> $listedIds
     */
    private function trashMissing(array $listedIds): int
    {
        $keep = array_fill_keys(array_map('strval', $listedIds), true);
        $posts = get_posts(
            [
                'post_type'      => 'event',
                'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_key'       => '_vastsverige_uid',
            ]
        );

        $trashed = 0;
        foreach ($posts as $postId) {
            $sourceIds = get_post_meta((int) $postId, '_vastsverige_source_id', false);
            $present   = false;
            foreach ($sourceIds as $sourceId) {
                if (isset($keep[(string) $sourceId])) {
                    $present = true;
                    break;
                }
            }
            if ($present) {
                continue;
            }
            wp_trash_post((int) $postId);
            $trashed++;
        }

        return $trashed;
    }

    /**
     * Organization terms use capabilities the administrator role does not store directly.
     */
    private function allowOrganizationTerms(): void
    {
        add_filter(
            'user_has_cap',
            static function (array $allcaps, array $caps, array $args, \WP_User $user): array {
                $isAdmin = in_array('administrator', $user->roles, true) || is_super_admin($user->ID);
                if (! $isAdmin) {
                    return $allcaps;
                }
                foreach (['manage_organization', 'edit_organization', 'assign_organization', 'delete_organization'] as $cap) {
                    $allcaps[$cap] = true;
                }
                return $allcaps;
            },
            10,
            4
        );
    }
}
