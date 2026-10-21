<?php

declare(strict_types=1);

namespace VastsverigeEvents\Mapping;

/**
 * Creates or reuses an organization term without creating a WordPress user.
 */
class OrganizerWriter
{
    /**
     * @param array<string, mixed> $contact
     */
    public function assign(int $postId, array $contact): void
    {
        $name = trim((string) ($contact['CompanyName'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($contact['Email'] ?? ''));
        }
        if ($name === '') {
            return;
        }

        $termId = $this->termId($name);
        if ($termId === 0) {
            return;
        }

        $this->updateTerm($termId, 'email', (string) ($contact['Email'] ?? ''));
        $this->updateTerm($termId, 'telephone', (string) ($contact['Phone'] ?? ''));
        $this->updateTerm($termId, 'url', (string) ($contact['Website'] ?? ''));
        $this->updateTerm($termId, 'address', trim((string) ($contact['City'] ?? '')));

        wp_set_object_terms($postId, [$termId], 'organization', false);
        if (function_exists('update_field')) {
            update_field('organization', [$termId], $postId);
        }
    }

    private function termId(string $name): int
    {
        $existing = term_exists($name, 'organization');
        if (is_array($existing)) {
            return (int) $existing['term_id'];
        }

        $created = wp_insert_term($name, 'organization');
        if (is_wp_error($created)) {
            if ($created->get_error_code() === 'term_exists') {
                return (int) $created->get_error_data();
            }
            return 0;
        }

        return (int) ($created['term_id'] ?? 0);
    }

    private function updateTerm(int $termId, string $field, string $value): void
    {
        if ($value === '' || ! function_exists('update_field')) {
            return;
        }

        update_field($field, $value, 'organization_' . $termId);
    }
}
