<?php

declare(strict_types=1);

namespace VastsverigeEvents\Mapping;

/**
 * Downloads an event image into the media library.
 */
class MediaSideloader
{
    /**
     * Sideload an image and return the attachment id.
     */
    public function sideload(string $url, int $postId, string $title): int
    {
        $existing = $this->existingAttachment($url);
        if ($existing > 0) {
            return $existing;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $clean = strtok($url, '?') ?: $url;
        $tmp   = download_url($clean);
        if (is_wp_error($tmp)) {
            $tmp = download_url($url);
        }
        if (is_wp_error($tmp)) {
            return 0;
        }

        $path = (string) (wp_parse_url($clean, PHP_URL_PATH) ?: 'event.jpg');
        $name = sanitize_file_name(basename($path));
        if ($name === '') {
            $name = 'event.jpg';
        }

        $attachmentId = media_handle_sideload(
            [
                'name'     => $name,
                'tmp_name' => $tmp,
            ],
            $postId,
            $title
        );

        if (is_wp_error($attachmentId)) {
            @unlink($tmp);
            return 0;
        }

        update_post_meta((int) $attachmentId, '_wp_attachment_image_alt', $title);
        update_post_meta((int) $attachmentId, '_vastsverige_source_url', $url);

        return (int) $attachmentId;
    }

    private function existingAttachment(string $url): int
    {
        $posts = get_posts(
            [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_vastsverige_source_url',
                'meta_value'     => $url,
            ]
        );

        return isset($posts[0]) ? (int) $posts[0] : 0;
    }
}
