<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Http;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the HTTP client every outbound IdP call goes through (OidcHttpClient,
 * JwtVerifier). Wrapped in NoPrivateNetworkHttpClient, which checks the
 * *resolved* IP on every connection and every redirect — closing the DNS
 * rebinding / open-redirect gaps a save-time URL check (SsrfUrlValidator)
 * cannot. SW6OIDC_ALLOW_INSECURE_IDP_URLS=1 disables the wrapper for local
 * development IdPs on private addresses.
 */
final class Sw6OidcHttpClientFactory
{
    public static function create(HttpClientInterface $inner, bool $allowInsecure): HttpClientInterface
    {
        return $allowInsecure ? $inner : new NoPrivateNetworkHttpClient($inner);
    }
}
