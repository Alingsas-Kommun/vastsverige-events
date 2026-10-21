<?php

declare(strict_types=1);

namespace VastsverigeEvents\Api;

/**
 * HTTP client for the Västsverige event API.
 */
class Client implements EventSource
{
    private const TIMEOUT   = 60;
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 100;
    private const ATTEMPTS  = 2;

    public function __construct(private string $baseUrl)
    {
    }

    /**
     * Pages through all event ids.
     *
     * @throws ApiException On transport failure, an unusable payload, a stalled pagination or the page cap.
     */
    public function listIds(string $configId): IdList
    {
        $seen  = [];
        $total = null;
        $skip  = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = $this->request(
                'POST',
                $this->baseUrl . '/get/' . rawurlencode($configId),
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => wp_json_encode(
                        [
                            'Query'       => '',
                            'Take'        => self::PAGE_SIZE,
                            'Sort'        => 'Date',
                            'Skip'        => $skip,
                            'SearchTypes' => 'event',
                            'IDOnly'      => true,
                            'Filter'      => [],
                        ]
                    ),
                ]
            );

            $parsed = Payload::idPage($body);
            $total  = $parsed['total'] ?? $total;

            $fresh = 0;
            foreach ($parsed['ids'] as $id) {
                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    $fresh++;
                }
            }

            if (count($parsed['ids']) < self::PAGE_SIZE) {
                return new IdList(array_keys($seen), $total);
            }

            if ($fresh === 0) {
                throw new ApiException('Id list pagination did not advance.');
            }

            $skip += self::PAGE_SIZE;
        }

        throw new ApiException(sprintf('Id list exceeded %d pages.', self::MAX_PAGES));
    }

    /**
     * @return array<string, mixed>|null
     * @throws ApiException
     */
    public function detail(int $id): ?array
    {
        return Payload::detail($this->request('GET', $this->baseUrl . '/detail/sv/' . $id));
    }

    /**
     * @return array<int, string>
     * @throws ApiException
     */
    public function categories(): array
    {
        return Payload::categories($this->request('GET', $this->baseUrl . '/categories'));
    }

    /**
     * @param array<string, mixed> $args
     * @throws ApiException
     */
    private function request(string $method, string $url, array $args = []): string
    {
        $args    += ['timeout' => self::TIMEOUT, 'method' => $method];
        $failure  = '';

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $failure = $response->get_error_message();
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code < 400) {
                    return (string) wp_remote_retrieve_body($response);
                }
                $failure = sprintf('HTTP %d', $code);
                if ($code < 500) {
                    break;
                }
            }

            if ($attempt < self::ATTEMPTS) {
                usleep(500000);
            }
        }

        throw new ApiException(sprintf('%s %s failed: %s', $method, $url, $failure));
    }
}
