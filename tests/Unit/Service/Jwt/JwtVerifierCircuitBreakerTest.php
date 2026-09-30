<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Jwt;

use MartinKuhl\Sw6Oidc\Service\Jwt\Exception\InvalidJwtException;
use MartinKuhl\Sw6Oidc\Service\Jwt\JwtVerifier;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\JwtTestSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(JwtVerifier::class)]
final class JwtVerifierCircuitBreakerTest extends TestCase
{
    private const JWKS = 'https://idp.example/jwks';

    public function testFailedFetchTripsBreakerAndSkipsFurtherRequests(): void
    {
        $signer = new JwtTestSigner();
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('down', ['http_code' => 503]);
        });
        $verifier = new JwtVerifier($client, new ArrayAdapter(), new NullLogger());

        foreach ([1, 2] as $attempt) {
            try {
                $this->verify($verifier, $signer->sign($this->claims()));
                self::fail('Expected InvalidJwtException on attempt ' . $attempt);
            } catch (InvalidJwtException) {
            }
        }

        self::assertSame(1, $calls);
    }

    public function testEmptyKeySetTripsBreaker(): void
    {
        $signer = new JwtTestSigner();
        $cache = new ArrayAdapter();
        $verifier = new JwtVerifier(new MockHttpClient([new MockResponse('{"keys":[]}')]), $cache, new NullLogger());

        try {
            $this->verify($verifier, $signer->sign($this->claims()));
            self::fail('Expected InvalidJwtException');
        } catch (InvalidJwtException) {
        }

        self::assertTrue($cache->getItem('sw6oidc_jwks_fail_' . hash('sha256', self::JWKS))->isHit());
        self::assertFalse($cache->getItem('sw6oidc_jwks_' . hash('sha256', self::JWKS))->isHit());
    }

    public function testRotatedKeyIsPickedUpWithOneRefetch(): void
    {
        $old = new JwtTestSigner('old');
        $new = new JwtTestSigner('new');
        $client = new MockHttpClient([new MockResponse($old->jwksJson()), new MockResponse($new->jwksJson())]);
        $verifier = new JwtVerifier($client, new ArrayAdapter(), new NullLogger());

        $this->verify($verifier, $old->sign($this->claims()));
        $claims = $this->verify($verifier, $new->sign($this->claims()));

        self::assertSame('user-1', $claims['sub']);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testForcedRefetchIsRateLimited(): void
    {
        $signer = new JwtTestSigner();
        $attacker = new JwtTestSigner('attacker');
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse($signer->jwksJson()));
        $verifier = new JwtVerifier($client, new ArrayAdapter(), new NullLogger());

        foreach ([1, 2, 3] as $attempt) {
            try {
                $this->verify($verifier, $attacker->sign($this->claims()));
                self::fail('Expected InvalidJwtException on attempt ' . $attempt);
            } catch (InvalidJwtException) {
            }
        }

        // initial fetch + one forced refetch, then the cooldown applies
        self::assertSame(2, $client->getRequestsCount());
    }

    /**
     * @return array<string, mixed>
     */
    private function verify(JwtVerifier $verifier, string $jwt): array
    {
        return $verifier->verify($jwt, self::JWKS, 'https://idp.example', 'client', 'nonce-1', 3600, 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(): array
    {
        return ['iss' => 'https://idp.example', 'aud' => 'client', 'sub' => 'user-1', 'exp' => time() + 300, 'nonce' => 'nonce-1'];
    }
}
