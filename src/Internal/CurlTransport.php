<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Internal;

/**
 * POST /logs with curl, reusing one handle so its connection stays open from one log to the next.
 *
 * @internal
 */
final class CurlTransport implements Transport
{
    private ?\CurlHandle $handle = null;

    /** @var list<string> */
    private readonly array $headers;

    /**
     * @param non-empty-string $url
     */
    public function __construct(private readonly string $url, #[\SensitiveParameter] string $token, string $userAgent)
    {
        $this->headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'User-Agent: ' . $userAgent,
            // curl asks for a 100 Continue before sending a body over 1 KB, a round trip Lumber has no use for
            'Expect:',
        ];
    }

    public function post(string $body, float $timeout): Answer
    {
        $handle = $this->handle ??= curl_init() ?: null;
        if ($handle === null) {
            return new Answer(null, error: 'curl_init() failed');
        }

        $retryAfter = null;
        $milliseconds = max(1, (int) ceil($timeout * 1000));
        curl_setopt_array($handle, [
            \CURLOPT_URL => $this->url,
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => $this->headers,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_TIMEOUT_MS => $milliseconds,
            \CURLOPT_CONNECTTIMEOUT_MS => $milliseconds,
            // Timeouts under a second, without signals
            \CURLOPT_NOSIGNAL => true,
            \CURLOPT_HEADERFUNCTION => static function (\CurlHandle $_, string $line) use (&$retryAfter): int {
                if (str_starts_with($line, 'HTTP/')) {
                    // The start of another response's headers, after a 100 Continue
                    $retryAfter = null;
                } elseif (stripos($line, 'retry-after:') === 0) {
                    $retryAfter = trim(substr($line, \strlen('retry-after:')));
                }

                return \strlen($line);
            },
        ]);

        $response = curl_exec($handle);
        if (!\is_string($response)) {
            $error = curl_error($handle);

            return new Answer(null, error: $error !== '' ? $error : 'curl error ' . curl_errno($handle));
        }
        $status = curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);

        return new Answer($status > 0 ? $status : null, $retryAfter, $response);
    }

    public function reset(): void
    {
        $this->handle = null;
    }
}
