<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Integration;

use MartinKuhl\Sw6Oidc\Tests\Integration\Support\DexLoginDriver;
use MartinKuhl\Sw6Oidc\Tests\Integration\Support\Sw6OidcIntegrationTestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Full Administration OIDC login against a real Dex, including the nonce
 * hand-off into a genuine Shopware admin access token, which is then used
 * against an authenticated plugin endpoint.
 */
final class AdminOidcLoginTest extends Sw6OidcIntegrationTestCase
{
    private const EMAIL = 'admin@example.com';

    protected function setUp(): void
    {
        $this->requireDex();
    }

    public function testAdminIsCreatedAndReceivesAWorkingAccessToken(): void
    {
        $roleId = Uuid::randomHex();
        static::getContainer()->get('acl_role.repository')->create([['id' => $roleId, 'name' => 'OIDC integration ' . $roleId, 'privileges' => ['product.viewer']]], Context::createDefaultContext());

        $providerId = $this->createDexProvider([
            'loginType' => 'admin',
            'autoCreateAdmin' => true,
            'defaultAclRoleId' => $roleId,
        ]);
        $browser = $this->browser();

        $browser->request('GET', $this->shopUrl() . '/api/sw6oidc/admin/login?providerId=' . $providerId);
        $callbackUrl = (new DexLoginDriver())->login((string) $browser->getResponse()->headers->get('Location'), self::EMAIL, self::PASSWORD, $this->shopUrl());

        $browser->request('GET', $callbackUrl);
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringContainsString('#/login?sw6oidc_nonce=', $location, 'redirected back into the SPA with a nonce');
        parse_str(substr($location, (int) strpos($location, '?') + 1), $query);

        $browser->request('POST', $this->shopUrl() . '/api/sw6oidc/admin/token', [
            'grant_type' => 'sw6oidc_admin',
            'client_id' => 'administration',
            'sw6oidc_nonce' => $query['sw6oidc_nonce'] ?? '',
        ]);
        $token = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertIsArray($token);
        self::assertIsString($token['access_token'] ?? null, (string) $browser->getResponse()->getContent());

        $userId = static::getContainer()->get('Doctrine\DBAL\Connection')->fetchOne('SELECT LOWER(HEX(id)) FROM user WHERE email = :email', ['email' => self::EMAIL]);
        self::assertIsString($userId, 'the admin was JIT-created');

        // The token works against an authenticated endpoint (Dex has no end_session_endpoint -> null).
        $browser->request('POST', $this->shopUrl() . '/api/sw6oidc/admin/logout', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token['access_token']]);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame(['logoutUrl' => null], json_decode((string) $browser->getResponse()->getContent(), true));
    }
}
