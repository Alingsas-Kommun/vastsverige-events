<?php

declare(strict_types=1);

namespace VastsverigeEvents\Mapping;

/**
 * Writes one merged event as an Event Manager post.
 */
class EventWriter
{
    public function __construct(
        private OrganizerWriter $organizers = new OrganizerWriter(),
        private MediaSideloader $media = new MediaSideloader(),
    ) {
    }

    /**
     * @param array{
     *   title: string,
     *   content: string,
     *   url: string,
     *   city: string,
     *   lat: string,
     *   lng: string,
     *   image: string,
     *   categories: list<string>,
     *   contact: array<string, mixed>,
     *   booking: string,
     *   occasions: list<array<string, mixed>>,
     *   uid: string,
     *   sourceIds: list<string>
     * } $event
     * @return 'created'|'updated'
     */
    public function save(array $event, string $newPostStatus): string
    {
        $postId = $this->findPost($event['sourceIds']);
        $status = $newPostStatus;
        $action = 'created';

        if ($postId > 0) {
            $current = get_post_status($postId);
            $status  = ($current !== false && $current !== 'trash') ? $current : $newPostStatus;
            $action  = 'updated';
        }

        $postarr = [
            'post_type'    => 'event',
            'post_title'   => $event['title'],
            'post_content' => $event['content'],
            'post_status'  => $status,
        ];
        if ($postId > 0) {
            $postarr['ID'] = $postId;
        }

        $saved = wp_insert_post($postarr, true);
        if (is_wp_error($saved)) {
            throw new \RuntimeException($saved->get_error_message());
        }

        $postId = (int) $saved;
        $this->writeMeta($postId, $event);
        $this->writeFields($postId, $event);
        $this->writeCategories($postId, $event['categories']);
        $this->organizers->assign($postId, $event['contact']);
        $this->writeImage($postId, $event['image'], $event['title']);

        return $action;
    }

    /**
     * @param list<string> $sourceIds
     */
    public function findPost(array $sourceIds): int
    {
        if ($sourceIds === []) {
            return 0;
        }

        $posts = get_posts(
            [
                'post_type'      => 'event',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'     => '_vastsverige_source_id',
                        'value'   => $sourceIds,
                        'compare' => 'IN',
                    ],
                ],
            ]
        );

        return isset($posts[0]) ? (int) $posts[0] : 0;
    }

    /**
     * @param array{uid: string, sourceIds: list<string>} $event
     */
    private function writeMeta(int $postId, array $event): void
    {
        $uid = (string) get_post_meta($postId, '_vastsverige_uid', true);
        if ($uid === '') {
            update_post_meta($postId, '_vastsverige_uid', $event['uid']);
        }

        delete_post_meta($postId, '_vastsverige_source_id');
        foreach ($event['sourceIds'] as $sourceId) {
            add_post_meta($postId, '_vastsverige_source_id', $sourceId);
        }
    }

    /**
     * @param array{url: string, city: string, lat: string, lng: string, occasions: list<array<string, mixed>>, booking: string} $event
     */
    private function writeFields(int $postId, array $event): void
    {
        if (! function_exists('update_field')) {
            throw new \RuntimeException('ACF update_field() is not available.');
        }

        $rows = $event['occasions'];
        if ($event['booking'] !== '') {
            foreach ($rows as $index => $row) {
                $rows[$index]['url'] = $event['booking'];
            }
        }

        update_field('occasions', $rows, $postId);
        update_field('attendancemode', 'offline', $postId);
        update_field('url', $event['url'], $postId);

        if ($event['city'] !== '') {
            update_field('locationName', $event['city'], $postId);
        }

        $map = ['address' => $event['city']];
        if ($event['lat'] !== '' && $event['lng'] !== '') {
            $map['lat']  = $event['lat'];
            $map['lng']  = $event['lng'];
            $map['zoom'] = 14;
        }
        update_field('locationAddress', $map, $postId);
    }

    /**
     * @param list<string> $names
     */
    private function writeCategories(int $postId, array $names): void
    {
        $termIds = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $existing = term_exists($name, 'category');
            if (is_array($existing)) {
                $termIds[] = (int) $existing['term_id'];
                continue;
            }
            $created = wp_insert_term($name, 'category');
            if (is_wp_error($created)) {
                if ($created->get_error_code() === 'term_exists') {
                    $termIds[] = (int) $created->get_error_data();
                }
                continue;
            }
            $termIds[] = (int) $created['term_id'];
        }

        if ($termIds === []) {
            return;
        }

        wp_set_object_terms($postId, $termIds, 'category', false);
        if (function_exists('update_field')) {
            update_field('category', $termIds, $postId);
        }
    }

    private function writeImage(int $postId, string $url, string $title): void
    {
        if ($url === '') {
            return;
        }

        $attachmentId = $this->media->sideload($url, $postId, $title);
        if ($attachmentId === 0 || ! function_exists('update_field')) {
            return;
        }

        update_field('images', [$attachmentId], $postId);
    }
}
