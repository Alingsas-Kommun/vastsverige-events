<?php

declare(strict_types=1);

namespace VastsverigeEvents\Mapping;

/**
 * Downloads an event image into the media library.
 */
class MediaSideloader
{
    /**
     * Sideloads an image, or reuses the attachment made for the same source url.
     *
     * @return int Attachment id, or 0 when the download failed.
     */
    public function sideload(string $url, int $postId, string $alt): int
    {
        $existing = $this->existingAttachment($url);
        if ($existing > 0) {
            update_post_meta($existing, '_wp_attachment_image_alt', $alt);
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

        $name = sanitize_file_name(self::fileName((string) wp_parse_url($clean, PHP_URL_PATH)));

        $attachmentId = media_handle_sideload(
            [
                'name'     => $name,
                'tmp_name' => $tmp,
            ],
            $postId,
            $alt
        );

        if (is_wp_error($attachmentId)) {
            @unlink($tmp);
            return 0;
        }

        update_post_meta((int) $attachmentId, '_wp_attachment_image_alt', $alt);
        update_post_meta((int) $attachmentId, '_vastsverige_source_url', $url);

        return (int) $attachmentId;
    }

    /**
     * File name for the media library. JPEG variants WordPress does not accept (such as .jfif) become .jpg.
     */
    public static function fileName(string $urlPath): string
    {
        $name = basename($urlPath);
        if ($name === '' || $name === '.' || $name === '/') {
            return 'event.jpg';
        }

        return (string) preg_replace('/\.(jfif|jfif-tbnl|pjpeg|pjp)$/i', '.jpg', $name);
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
