<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Oidc;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Http\OidcHttpClient;
use MartinKuhl\Sw6Oidc\Service\Oidc\PostLogoutState;
use MartinKuhl\Sw6Oidc\Service\Oidc\RpInitiatedLogoutService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * R3-M3: the logout style is a provider setting, not a guess from the URL.
 */
#[CoversClass(RpInitiatedLogoutService::class)]
final class RpInitiatedLogoutServiceTest extends TestCase
{
    public function testStandardLogoutAlwaysSendsTheClientId(): void
    {
        // A Keycloak end-session URL: the old guess sent the Authelia `rd` parameter here.
        $url = $this->service()->buildLogoutUrl($this->provider('https://kc.example/realms/shop/protocol/openid-connect/logout', Sw6OidcProviderDefinition::LOGOUT_STYLE_STANDARD), null, 'https://shop.example/sw6oidc/postlogout', PostLogoutState::TARGET_CUSTOMER);

        self::assertIsString($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('shop-client', $query['client_id'] ?? null);
        self::assertSame('https://shop.example/sw6oidc/postlogout', $query['post_logout_redirect_uri'] ?? null);
        self::assertArrayNotHasKey('rd', $query);
        self::assertArrayNotHasKey('id_token_hint', $query);

        $withHint = $this->service()->buildLogoutUrl($this->provider('https://kc.example/logout', Sw6OidcProviderDefinition::LOGOUT_STYLE_STANDARD), 'id.token', 'https://shop.example/x', PostLogoutState::TARGET_CUSTOMER);
        parse_str((string) parse_url((string) $withHint, PHP_URL_QUERY), $query);
        self::assertSame('id.token', $query['id_token_hint'] ?? null);
    }

    public function testAutheliaPortalLogoutUsesRd(): void
    {
        $url = $this->service()->buildLogoutUrl($this->provider('https://auth.example/logout', Sw6OidcProviderDefinition::LOGOUT_STYLE_AUTHELIA_FORWARD_AUTH), 'id.token', 'https://shop.example/account/login', PostLogoutState::TARGET_CUSTOMER);

        self::assertSame('https://auth.example/logout?rd=' . rawurlencode('https://shop.example/account/login'), $url);
    }

    private function service(): RpInitiatedLogoutService
    {
        return new RpInitiatedLogoutService($this->createStub(OidcHttpClient::class), new NullLogger(), new PostLogoutState('app-secret'));
    }

    private function provider(string $endSessionEndpoint, string $logoutStyle): Sw6OidcProviderEntity
    {
        $provider = new Sw6OidcProviderEntity();
        $provider->setId('a1000000000000000000000000000001');
        $provider->setClientId('shop-client');
        $provider->setEndSessionEndpoint($endSessionEndpoint);
        $provider->setLogoutStyle($logoutStyle);

        return $provider;
    }
}
