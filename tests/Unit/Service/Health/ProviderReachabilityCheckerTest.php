<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Health;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Health\ProviderReachabilityChecker;
use MartinKuhl\Sw6Oidc\Service\Health\ReachabilityResult;
use MartinKuhl\Sw6Oidc\Service\Security\SsrfUrlValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ProviderReachabilityChecker::class)]
#[CoversClass(ReachabilityResult::class)]
final class ProviderReachabilityCheckerTest extends TestCase
{
    public function testJwksWithKeysIsHealthy(): void
    {
        $result = $this->check(new MockResponse('{"keys":[{"kty":"RSA"}]}'), jwks: 'https://idp.example/jwks');

        self::assertTrue($result->ok);
        self::assertSame('jwks', $result->checked);
    }

    public function testEmptyKeySetIsUnhealthy(): void
    {
        self::assertFalse($this->check(new MockResponse('{"keys":[]}'), jwks: 'https://idp.example/jwks')->ok);
    }

    public function testHttpErrorAndNonJsonAndTransportFailureAreUnhealthy(): void
    {
        self::assertStringContainsString('HTTP 502', $this->check(new MockResponse('bad gateway', ['http_code' => 502]), jwks: 'https://idp.example/jwks')->detail);
        self::assertStringContainsString('not a JSON', $this->check(new MockResponse('<html>'), jwks: 'https://idp.example/jwks')->detail);
        self::assertStringContainsString('Request failed', $this->check(static fn () => throw new TransportException('timeout'), jwks: 'https://idp.example/jwks')->detail);
    }

    public function testFallsBackToTheDiscoveryDocument(): void
    {
        $result = $this->check(new MockResponse('{"issuer":"https://idp.example"}'), wellKnown: 'https://idp.example/.well-known/openid-configuration');

        self::assertTrue($result->ok);
        self::assertSame('discovery', $result->checked);
        self::assertFalse($this->check(new MockResponse('{}'), wellKnown: 'https://idp.example/.well-known/openid-configuration')->ok);
    }

    public function testNothingToProbe(): void
    {
        $result = $this->check(new MockResponse('{}'));

        self::assertFalse($result->ok);
        self::assertNull($result->checked);
    }

    public function testUrlIsSsrfRevalidatedBeforeTheFetch(): void
    {
        $client = new MockHttpClient(static fn () => throw new \LogicException('must not be called'));
        $checker = new ProviderReachabilityChecker($client, new SsrfUrlValidator(false, static fn (): array => ['10.0.0.5']));

        $result = $checker->check($this->provider('https://idp.example/jwks', null));

        self::assertFalse($result->ok);
        self::assertStringStartsWith('URL blocked', $result->detail);
        self::assertSame(0, $client->getRequestsCount());
    }

    private function check(MockResponse|\Closure $response, ?string $jwks = null, ?string $wellKnown = null): ReachabilityResult
    {
        $client = new MockHttpClient($response);

        return (new ProviderReachabilityChecker($client, new SsrfUrlValidator(false, static fn (): array => ['93.184.215.14'])))
            ->check($this->provider($jwks, $wellKnown));
    }

    private function provider(?string $jwks, ?string $wellKnown): Sw6OidcProviderEntity
    {
        $provider = new Sw6OidcProviderEntity();
        $provider->assign(['id' => 'p1', 'jwksEndpoint' => $jwks, 'wellKnownConfigUrl' => $wellKnown, 'httpTimeout' => 5]);

        return $provider;
    }
}
