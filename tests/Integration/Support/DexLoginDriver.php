<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Integration\Support;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Plays the browser's part at Dex: follows the authorize redirect to Dex's
 * local password connector, submits the login form and follows Dex's
 * redirects until it points back at the shop. Returns that callback URL
 * (with `code` and `state`), which the test then requests in the kernel.
 *
 * Needs `skipApprovalScreen: true` (tests/Integration/dex/config.yaml).
 */
final class DexLoginDriver
{
    private const MAX_REDIRECTS = 10;

    private readonly HttpClientInterface $client;

    public function __construct(?HttpClientInterface $client = null)
    {
        $this->client = $client ?? HttpClient::create(['max_redirects' => 0, 'timeout' => 10]);
    }

    public function login(string $authorizeUrl, string $email, string $password, string $shopBaseUrl): string
    {
        $url = $authorizeUrl;
        $cookies = [];

        for ($hop = 0; $hop < self::MAX_REDIRECTS; ++$hop) {
            if (str_starts_with($url, $shopBaseUrl)) {
                return $url;
            }

            $response = $this->client->request('GET', $url, ['headers' => ['Cookie' => $this->cookieHeader($cookies)]]);
            $cookies = $this->collectCookies($response->getHeaders(false), $cookies);
            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400) {
                $url = $this->absolute($url, $response->getHeaders(false)['location'][0] ?? '');

                continue;
            }

            if ($status !== 200) {
                throw new \RuntimeException(sprintf('Dex answered HTTP %d at %s.', $status, $url));
            }

            // The login form: <form method="post" action="/dex/auth/local/login?back=&state=...">
            if (preg_match('/<form[^>]+action="([^"]+)"/i', $response->getContent(false), $match) !== 1) {
                throw new \RuntimeException('No login form found on the Dex page ' . $url);
            }

            $post = $this->client->request('POST', $this->absolute($url, html_entity_decode($match[1])), [
                'headers' => ['Cookie' => $this->cookieHeader($cookies)],
                'body' => ['login' => $email, 'password' => $password],
            ]);
            $cookies = $this->collectCookies($post->getHeaders(false), $cookies);

            if ($post->getStatusCode() < 300 || $post->getStatusCode() >= 400) {
                throw new \RuntimeException(sprintf('Dex rejected the login for %s (HTTP %d).', $email, $post->getStatusCode()));
            }

            $url = $this->absolute($url, $post->getHeaders(false)['location'][0] ?? '');
        }

        throw new \RuntimeException('Too many redirects between Dex and the shop.');
    }

    private function absolute(string $base, string $location): string
    {
        if ($location === '') {
            throw new \RuntimeException('Redirect without a Location header.');
        }

        if (preg_match('#^https?://#', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);

        return sprintf('%s://%s%s%s', $parts['scheme'] ?? 'http', $parts['host'] ?? '', isset($parts['port']) ? ':' . $parts['port'] : '', $location);
    }

    /**
     * @param array<string, list<string>> $headers
     * @param array<string, string> $cookies
     *
     * @return array<string, string>
     */
    private function collectCookies(array $headers, array $cookies): array
    {
        foreach ($headers['set-cookie'] ?? [] as $setCookie) {
            [$pair] = explode(';', $setCookie, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $cookies[trim($name)] = trim($value);
        }

        return $cookies;
    }

    /**
     * @param array<string, string> $cookies
     */
    private function cookieHeader(array $cookies): string
    {
        return implode('; ', array_map(static fn (string $name, string $value): string => $name . '=' . $value, array_keys($cookies), $cookies));
    }
}
