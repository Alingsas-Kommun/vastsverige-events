<?php

declare(strict_types=1);

namespace VastsverigeEvents\Mapping;

use VastsverigeEvents\Import\MergedEvent;

/**
 * Stores merged events as Event Manager posts.
 */
class EventWriter implements EventRepository
{
    public const META_UID     = '_vastsverige_uid';
    public const META_SOURCE  = '_vastsverige_source_id';
    public const META_HASH    = '_vastsverige_hash';
    public const META_TRASHED = '_vastsverige_trashed';

    private const LIVE_STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    public function __construct(
        private OrganizerWriter $organizers = new OrganizerWriter(),
        private MediaSideloader $media = new MediaSideloader(),
        private PostSelector $selector = new PostSelector(),
    ) {
    }

    public function isAvailable(): bool
    {
        return post_type_exists('event') && function_exists('update_field');
    }

    /**
     * @throws \RuntimeException
     */
    public function save(MergedEvent $event, string $newPostStatus): SaveResult
    {
        if ($event->sourceIds === []) {
            throw new \RuntimeException('The event has no source id, so it cannot be matched on later runs.');
        }

        $choice = $this->selector->choose($this->candidates($event->sourceIds));

        if ($choice->action === PostChoice::SKIP) {
            return new SaveResult(SaveResult::SKIPPED, ['trashed by an editor, left alone']);
        }

        foreach ($choice->duplicates as $duplicateId) {
            $this->releaseDuplicate($duplicateId, $event->sourceIds);
        }

        $postId   = $choice->postId;
        $status   = $newPostStatus;
        $restored = false;

        if ($choice->action === PostChoice::RESTORE) {
            wp_untrash_post($postId);
            delete_post_meta($postId, self::META_TRASHED);
            delete_post_meta($postId, self::META_HASH);
            $restored = true;
        } elseif ($choice->action === PostChoice::UPDATE) {
            $current = get_post_status($postId);
            $status  = $current !== false ? $current : $newPostStatus;

            if ((string) get_post_meta($postId, self::META_HASH, true) === $event->hash()) {
                $this->writeSourceIds($postId, $event->sourceIds);
                return new SaveResult(SaveResult::UNCHANGED);
            }
        }

        $postarr = [
            'post_type'    => 'event',
            'post_title'   => $event->title,
            'post_content' => wp_kses_post($event->content),
            'post_status'  => $status,
        ];
        if ($postId > 0) {
            $postarr['ID'] = $postId;
        }

        $saved = wp_insert_post($postarr, true);
        if (is_wp_error($saved)) {
            throw new \RuntimeException($saved->get_error_message());
        }

        $postId   = (int) $saved;
        $warnings = [];

        $this->writeMeta($postId, $event);
        $this->writeFields($postId, $event);
        $this->writeCategories($postId, $event->categories);
        $this->writeOrganizer($postId, $event->contact, $warnings);
        $this->writeImage($postId, $event, $warnings);

        if ($warnings === []) {
            update_post_meta($postId, self::META_HASH, $event->hash());
        }

        $action = ($choice->action === PostChoice::CREATE) ? SaveResult::CREATED : SaveResult::UPDATED;
        if ($restored) {
            $warnings[] = 'restored from trash';
        }

        return new SaveResult($action, $warnings);
    }

    /**
     * @return array<int, list<string>>
     */
    public function managedPosts(): array
    {
        $ids = get_posts(
            [
                'post_type'      => 'event',
                'post_status'    => self::LIVE_STATUSES,
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_key'       => self::META_UID,
            ]
        );

        $managed = [];
        foreach ($ids as $postId) {
            $managed[(int) $postId] = array_map('strval', get_post_meta((int) $postId, self::META_SOURCE, false));
        }

        return $managed;
    }

    public function trash(int $postId): void
    {
        update_post_meta($postId, self::META_TRASHED, '1');
        wp_trash_post($postId);
    }

    /**
     * @param list<string> $sourceIds
     * @return list<array{id: int, trashed: bool, byImport: bool}>
     */
    private function candidates(array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $ids = get_posts(
            [
                'post_type'      => 'event',
                'post_status'    => array_merge(self::LIVE_STATUSES, ['trash']),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'meta_query'     => [
                    [
                        'key'     => self::META_SOURCE,
                        'value'   => $sourceIds,
                        'compare' => 'IN',
                    ],
                ],
            ]
        );

        return array_map(
            static fn ($id): array => [
                'id'       => (int) $id,
                'trashed'  => get_post_status((int) $id) === 'trash',
                'byImport' => (string) get_post_meta((int) $id, self::META_TRASHED, true) === '1',
            ],
            $ids
        );
    }

    /**
     * Hands source ids over to the post that now owns them, and trashes the post when none are left.
     *
     * @param list<string> $claimed
     */
    private function releaseDuplicate(int $postId, array $claimed): void
    {
        $remaining = array_values(array_diff(array_map('strval', get_post_meta($postId, self::META_SOURCE, false)), $claimed));

        if ($remaining === []) {
            $this->trash($postId);
            return;
        }

        $this->writeSourceIds($postId, $remaining);
        delete_post_meta($postId, self::META_HASH);
    }

    /**
     * @param list<string> $sourceIds
     */
    private function writeSourceIds(int $postId, array $sourceIds): void
    {
        $stored = array_map('strval', get_post_meta($postId, self::META_SOURCE, false));
        if ($stored === $sourceIds) {
            return;
        }

        delete_post_meta($postId, self::META_SOURCE);
        foreach ($sourceIds as $sourceId) {
            add_post_meta($postId, self::META_SOURCE, $sourceId);
        }
    }

    private function writeMeta(int $postId, MergedEvent $event): void
    {
        if ((string) get_post_meta($postId, self::META_UID, true) === '') {
            update_post_meta($postId, self::META_UID, $event->uid);
        }

        $this->writeSourceIds($postId, $event->sourceIds);
    }

    private function writeFields(int $postId, MergedEvent $event): void
    {
        update_field('occasions', $event->occasions, $postId);
        update_field('attendancemode', 'offline', $postId);
        update_field('url', $event->url, $postId);
        update_field('locationName', $event->locationName, $postId);

        $map = [];
        if ($event->address !== '') {
            $map['address'] = $event->address;
        }
        if ($event->lat !== '' && $event->lng !== '') {
            $map['lat']  = $event->lat;
            $map['lng']  = $event->lng;
            $map['zoom'] = 14;
        }
        update_field('locationAddress', $map === [] ? '' : $map, $postId);
    }

    /**
     * @param list<string>|null $names Null leaves the current terms untouched.
     */
    private function writeCategories(int $postId, ?array $names): void
    {
        if ($names === null) {
            return;
        }

        $termIds = [];
        foreach ($names as $name) {
            $termId = $this->categoryTerm(trim($name));
            if ($termId > 0) {
                $termIds[] = $termId;
            }
        }

        wp_set_object_terms($postId, $termIds, 'category', false);
        update_field('category', $termIds, $postId);
    }

    private function categoryTerm(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        $existing = term_exists($name, 'category');
        if (is_array($existing)) {
            return (int) $existing['term_id'];
        }

        $created = wp_insert_term($name, 'category');
        if (is_wp_error($created)) {
            return $created->get_error_code() === 'term_exists' ? (int) $created->get_error_data() : 0;
        }

        return (int) $created['term_id'];
    }

    /**
     * @param array<string, string> $contact
     * @param list<string>          $warnings
     */
    private function writeOrganizer(int $postId, array $contact, array &$warnings): void
    {
        if (! $this->organizers->assign($postId, $contact)) {
            $warnings[] = 'organization could not be saved';
        }
    }

    /**
     * @param list<string> $warnings
     */
    private function writeImage(int $postId, MergedEvent $event, array &$warnings): void
    {
        if ($event->image === '') {
            update_field('images', [], $postId);
            return;
        }

        $attachmentId = $this->media->sideload($event->image, $postId, $event->imageAlt);
        if ($attachmentId === 0) {
            $warnings[] = 'image could not be downloaded: ' . $event->image;
            return;
        }

        update_field('images', [$attachmentId], $postId);
    }
}
