<?php

declare(strict_types=1);

namespace VastsverigeEvents\Mapping;

/**
 * Creates or reuses an organization term without creating a WordPress user.
 */
class OrganizerWriter
{
    /**
     * Assigns the organization described by the contact, or clears it when the contact is empty.
     *
     * @param array<string, string> $contact Keys: name, email, phone, website, address.
     * @return bool False when the term could not be stored.
     */
    public function assign(int $postId, array $contact): bool
    {
        $name = trim($contact['name'] ?? '');
        if ($name === '') {
            wp_set_object_terms($postId, [], 'organization', false);
            update_field('organization', [], $postId);
            return true;
        }

        $termId = $this->termId($name);
        if ($termId === 0) {
            return false;
        }

        $this->updateTerm($termId, 'email', $contact['email'] ?? '');
        $this->updateTerm($termId, 'telephone', $contact['phone'] ?? '');
        $this->updateTerm($termId, 'url', $contact['website'] ?? '');
        $this->updateTerm($termId, 'address', $contact['address'] ?? '');

        wp_set_object_terms($postId, [$termId], 'organization', false);
        update_field('organization', [$termId], $postId);

        return true;
    }

    private function termId(string $name): int
    {
        $existing = term_exists($name, 'organization');
        if (is_array($existing)) {
            return (int) $existing['term_id'];
        }

        $created = wp_insert_term($name, 'organization');
        if (is_wp_error($created)) {
            return $created->get_error_code() === 'term_exists' ? (int) $created->get_error_data() : 0;
        }

        return (int) ($created['term_id'] ?? 0);
    }

    private function updateTerm(int $termId, string $field, string $value): void
    {
        if ($value === '') {
            return;
        }

        update_field($field, $value, 'organization_' . $termId);
    }
}
