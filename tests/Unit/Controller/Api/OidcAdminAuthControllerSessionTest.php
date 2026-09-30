<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Controller\Api;

use League\OAuth2\Server\AuthorizationServer;
use MartinKuhl\Sw6Oidc\Controller\Api\OidcAdminAuthController;
use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminLoginErrorTicketStore;
use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminLoginNonceService;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionRegistry;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\BuildsOidcAdminAuthController;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\InMemoryAtomicCache;
use Nyholm\Psr7\Response as PsrResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(OidcAdminAuthController::class)]
final class OidcAdminAuthControllerSessionTest extends TestCase
{
    use BuildsOidcAdminAuthController;

    private const USER_ID = '0190a1b2c3d4e5f60718293a4b5c6d7e';

    public function testTokenExchangeRegistersTheOidcSessionUnderTheMintedJti(): void
    {
        $registry = new Sw6OidcSessionRegistry(new ArrayAdapter(), new NullLogger());
        $nonces = new AdminLoginNonceService(new InMemoryAtomicCache());
        $nonce = $nonces->createNonce(self::USER_ID, 'provider-1', 'sub-1', 'sid-1', 'id.token');

        $response = $this->exchange($nonces, $registry, $nonce, $this->jwt(['jti' => 'jti-123', 'sub' => self::USER_ID]));

        self::assertSame(200, $response->getStatusCode());
        $sessions = $registry->resolveBySid('provider-1', 'sid-1');
        self::assertCount(1, $sessions);
        self::assertSame('jti-123', $sessions[0]->sessionKey);
        self::assertSame('admin', $sessions[0]->userType);
        self::assertSame(self::USER_ID, $sessions[0]->userId);
        self::assertSame('id.token', $sessions[0]->idToken);
    }

    public function testNonOidcNonceRegistersNothing(): void
    {
        $registry = new Sw6OidcSessionRegistry(new ArrayAdapter(), new NullLogger());
        $nonces = new AdminLoginNonceService(new InMemoryAtomicCache());

        $this->exchange($nonces, $registry, $nonces->createNonce(self::USER_ID), $this->jwt(['jti' => 'jti-1']));

        self::assertSame([], $registry->resolveByUser('admin', self::USER_ID));
    }

    public function testUnknownNonceIsRejected(): void
    {
        $registry = new Sw6OidcSessionRegistry(new ArrayAdapter(), new NullLogger());

        $response = $this->exchange(new AdminLoginNonceService(new InMemoryAtomicCache()), $registry, 'unknown', 'x');

        self::assertSame(400, $response->getStatusCode());
    }

    public function testLoginErrorTicketIsRedeemedOnce(): void
    {
        $tickets = new AdminLoginErrorTicketStore(new InMemoryAtomicCache());
        $ticket = $tickets->create('Staff only.');
        $controller = $this->buildAdminAuthController(['loginErrorTicketStore' => $tickets]);

        self::assertSame('{"message":"Staff only."}', $controller->loginError($ticket)->getContent());
        self::assertSame('{"message":null}', $controller->loginError($ticket)->getContent());
    }

    private function exchange(AdminLoginNonceService $nonces, Sw6OidcSessionRegistry $registry, string $nonce, string $accessToken): \Symfony\Component\HttpFoundation\Response
    {
        $server = $this->createMock(AuthorizationServer::class);
        $server->method('respondToAccessTokenRequest')->willReturn(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'token_type' => 'Bearer',
            'expires_in' => 600,
            'access_token' => $accessToken,
            'refresh_token' => 'refresh',
        ], JSON_THROW_ON_ERROR)));

        $controller = $this->buildAdminAuthController([
            'loginNonceService' => $nonces,
            'adminAuthorizationServer' => $server,
            'sessionRegistry' => $registry,
        ]);

        return $controller->exchangeNonce(Request::create('https://shop.example/api/sw6oidc/admin/token', 'POST', ['sw6oidc_nonce' => $nonce]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jwt(array $payload): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encode(['alg' => 'none']) . '.' . $encode($payload) . '.sig';
    }
}
