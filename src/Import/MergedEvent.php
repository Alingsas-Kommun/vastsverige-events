<?php

declare(strict_types=1);

namespace VastsverigeEvents\Import;

/**
 * One event ready to be written, merged from every API detail that shares a heading.
 */
final class MergedEvent
{
    /**
     * @param list<string>|null                $categories  Null when the category list could not be fetched, so existing terms stay.
     * @param array<string, string>            $contact     Keys: name, email, phone, website, address. Empty when unknown.
     * @param list<array<string, mixed>>       $occasions
     * @param list<string>                     $dropped     Labels of occasions that did not fit the repeater.
     * @param list<string>                     $sourceIds
     */
    public function __construct(
        public readonly string $title,
        public readonly string $content,
        public readonly string $url,
        public readonly string $locationName,
        public readonly string $address,
        public readonly string $lat,
        public readonly string $lng,
        public readonly string $image,
        public readonly string $imageAlt,
        public readonly ?array $categories,
        public readonly array $contact,
        public readonly array $occasions,
        public readonly array $dropped,
        public readonly string $uid,
        public readonly array $sourceIds,
    ) {
    }

    /**
     * Fingerprint of everything written to the post. Equal hashes mean the post is up to date.
     */
    public function hash(): string
    {
        $data = get_object_vars($this);
        unset($data['dropped']);
        $data['mapping'] = EventMerger::MAPPING_VERSION;

        return md5((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
