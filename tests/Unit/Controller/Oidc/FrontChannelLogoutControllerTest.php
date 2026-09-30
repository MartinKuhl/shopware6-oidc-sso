<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Controller\Oidc;

use MartinKuhl\Sw6Oidc\Controller\Oidc\FrontChannelLogoutController;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Oidc\LogoutContextStore;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcRateLimiter;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcIdpLogoutHandler;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSession;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionActivityRecorder;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionDestructionService;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionRegistry;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\InMemoryAtomicCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(FrontChannelLogoutController::class)]
final class FrontChannelLogoutControllerTest extends TestCase
{
    private const ISSUER = 'https://idp.example';

    private Sw6OidcSessionRegistry $registry;

    private Sw6OidcRateLimiter $rateLimiter;

    /** @var list<Sw6OidcSession> */
    private array $destroyed = [];

    protected function setUp(): void
    {
        $this->registry = new Sw6OidcSessionRegistry(new ArrayAdapter(), new NullLogger());
        $this->rateLimiter = new Sw6OidcRateLimiter(null, new ArrayAdapter());
    }

    public function testKnownSidEndsTheSession(): void
    {
        $this->registry->register('p1', 'user-1', 'sid-1', 'customer', 'c1', 'ctx-1', 'sc');

        $this->assertPixel($this->get(['iss' => self::ISSUER, 'sid' => 'sid-1']));
        self::assertCount(1, $this->destroyed);
        self::assertSame([], $this->registry->resolveBySid('p1', 'sid-1'));
    }

    public function testUnknownSidStillReturnsThePixel(): void
    {
        $this->assertPixel($this->get(['iss' => self::ISSUER, 'sid' => 'unknown']));
        self::assertSame([], $this->destroyed);
    }

    public function testUnknownIssuerOrMissingParametersDoNothing(): void
    {
        $this->registry->register('p1', 'user-1', 'sid-1', 'customer', 'c1', 'ctx-1', 'sc');

        $this->assertPixel($this->get(['iss' => 'https://other.example', 'sid' => 'sid-1']));
        $this->assertPixel($this->get(['sid' => 'sid-1']));
        $this->assertPixel($this->get(['iss' => self::ISSUER]));
        self::assertSame([], $this->destroyed);
    }

    public function testRateLimitedAddressGetsThePixelButNothingIsProcessed(): void
    {
        $controller = $this->controller();

        for ($i = 0; $i < 10; ++$i) {
            $controller->logout($this->request(['iss' => self::ISSUER, 'sid' => 'guess-' . $i]));
        }

        $this->registry->register('p1', 'user-1', 'sid-1', 'customer', 'c1', 'ctx-1', 'sc');

        $this->assertPixel($controller->logout($this->request(['iss' => self::ISSUER, 'sid' => 'sid-1'])));
        self::assertSame([], $this->destroyed);
    }

    private function assertPixel(Response $response): void
    {
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/gif', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('GIF89a', (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('frame-ancestors *', $response->headers->get('Content-Security-Policy'));
    }

    /**
     * @param array<string, string> $query
     */
    private function get(array $query): Response
    {
        return $this->controller()->logout($this->request($query));
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query): Request
    {
        return Request::create('https://shop.example/sw6oidc/frontchannel-logout', 'GET', $query, [], [], ['REMOTE_ADDR' => '203.0.113.9']);
    }

    private function controller(): FrontChannelLogoutController
    {
        $provider = new Sw6OidcProviderEntity();
        $provider->setId('p1');

        $resolver = $this->createStub(ProviderResolver::class);
        $resolver->method('findByIssuer')->willReturnCallback(static fn (string $issuer): array => $issuer === self::ISSUER ? [$provider] : []);

        $destruction = $this->createStub(Sw6OidcSessionDestructionService::class);
        $destruction->method('destroy')->willReturnCallback(function (Sw6OidcSession $session): void {
            $this->destroyed[] = $session;
        });

        return new FrontChannelLogoutController(
            $resolver,
            new Sw6OidcIdpLogoutHandler($this->registry, $destruction, new LogoutContextStore(new InMemoryAtomicCache()), new NullLogger(), $this->createStub(Sw6OidcSessionActivityRecorder::class)),
            $this->rateLimiter,
            new NullLogger(),
        );
    }
}
