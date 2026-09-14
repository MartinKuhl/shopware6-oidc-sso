<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

/**
 * Validates a URL before the plugin makes an outbound request to it (discovery
 * documents, JWKS endpoints) — a baseline SSRF guard for the admin API actions
 * that let a privileged-but-limited admin user cause this server to make an
 * arbitrary outbound GET. Blocks loopback/private/link-local hosts regardless
 * of scheme; does not hard-block `http://` (self-hosted/dev IdPs commonly run
 * without TLS), it only flags it as a warning for the caller to surface.
 *
 * Resolves the hostname at validation time via PHP's own resolver; the actual
 * HTTP request resolves again independently, so a DNS-rebinding attacker could
 * still slip a private address past this check between the two resolutions —
 * a known, accepted limitation given the underlying HTTP client takes a URL
 * string rather than a pre-resolved connection.
 */
class DiscoveryUrlValidator
{
    /**
     * @return array{blocked: bool, warnings: string[]}
     */
    public function validate(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'], $parts['scheme'])) {
            return ['blocked' => true, 'warnings' => ['The URL is not valid.']];
        }

        if (!\in_array($parts['scheme'], ['http', 'https'], true)) {
            return ['blocked' => true, 'warnings' => [sprintf('Unsupported URL scheme "%s"; only http/https are allowed.', $parts['scheme'])]];
        }

        $warnings = [];

        if ($parts['scheme'] === 'http') {
            $warnings[] = 'This URL uses plain HTTP; traffic to it is not encrypted in transit.';
        }

        if ($this->resolvesToBlockedHost($parts['host'])) {
            return ['blocked' => true, 'warnings' => ['This URL resolves to a private, loopback, or link-local address and cannot be used.']];
        }

        return ['blocked' => false, 'warnings' => $warnings];
    }

    private function resolvesToBlockedHost(string $host): bool
    {
        $ips = $this->resolveIps($host);

        if ($ips === []) {
            // Could not resolve at all — the request will fail on its own, but
            // an unresolvable host is also exactly what a malformed/spoofed
            // SSRF attempt would look like, so treat it as blocked rather than
            // silently letting it through to the HTTP client.
            return true;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function resolveIps(string $host): array
    {
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $ipv4 = gethostbyname($host);

        if ($ipv4 !== $host) {
            $ips[] = $ipv4;
        }

        $aaaaRecords = @dns_get_record($host, \DNS_AAAA);

        if (\is_array($aaaaRecords)) {
            foreach ($aaaaRecords as $record) {
                if (isset($record['ipv6']) && \is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }
}
