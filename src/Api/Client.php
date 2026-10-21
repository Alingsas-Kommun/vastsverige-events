<?php

declare(strict_types=1);

namespace VastsverigeEvents\Api;

use WP_Error;

/**
 * HTTP client for the Västsverige event API.
 */
class Client
{
    private const TIMEOUT = 60;

    public function __construct(private string $baseUrl)
    {
    }

    /**
     * Page through event ids.
     *
     * @return list<int>|WP_Error
     */
    public function listIds(string $configId): array|WP_Error
    {
        $ids  = [];
        $skip = 0;
        $take = 100;

        for ($page = 0; $page < 100; $page++) {
            $response = wp_remote_post(
                $this->baseUrl . '/get/' . rawurlencode($configId),
                [
                    'timeout' => self::TIMEOUT,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => wp_json_encode(
                        [
                            'Query'       => '',
                            'Take'        => $take,
                            'Sort'        => 'Date',
                            'Skip'        => $skip,
                            'SearchTypes' => 'event',
                            'IDOnly'      => true,
                            'Filter'      => [],
                        ]
                    ),
                ]
            );

            if (is_wp_error($response)) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code >= 400) {
                return new WP_Error('vastsverige_list', sprintf('Id list request failed with HTTP %d.', $code));
            }

            $payload = json_decode((string) wp_remote_retrieve_body($response), true);
            $batch   = is_array($payload['IDs'] ?? null) ? $payload['IDs'] : [];
            foreach ($batch as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }

            if (count($batch) < $take) {
                break;
            }

            $skip += $take;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, mixed>|null|WP_Error Null when the detail body is empty.
     */
    public function detail(int $id): array|null|WP_Error
    {
        $response = wp_remote_get(
            $this->baseUrl . '/detail/sv/' . $id,
            ['timeout' => self::TIMEOUT]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            return new WP_Error('vastsverige_detail', sprintf('Detail %d failed with HTTP %d.', $id, $code));
        }

        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        return is_array($payload) && $payload !== [] ? $payload : null;
    }

    /**
     * Category group 73, id to label.
     *
     * @return array<int, string>
     */
    public function categories(): array
    {
        $response = wp_remote_get($this->baseUrl . '/categories', ['timeout' => self::TIMEOUT]);
        if (is_wp_error($response)) {
            return [];
        }

        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($payload)) {
            return [];
        }

        $map = [];
        foreach ($payload as $group) {
            if (! is_array($group) || (int) ($group['id'] ?? 0) !== 73) {
                continue;
            }
            foreach ($group['categories'] ?? [] as $category) {
                if (! is_array($category) || ! isset($category['id'], $category['text'])) {
                    continue;
                }
                $map[(int) $category['id']] = (string) $category['text'];
            }
            break;
        }

        return $map;
    }
}
