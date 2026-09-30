<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Jwt;

use MartinKuhl\Sw6Oidc\Service\Jwt\Exception\InvalidJwtException;
use MartinKuhl\Sw6Oidc\Service\Jwt\JwtVerifier;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\JwtTestSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(JwtVerifier::class)]
final class JwtVerifierLogoutTokenTest extends TestCase
{
    private const ISSUER = 'https://idp.example';
    private const AUDIENCE = 'client-1';

    private JwtTestSigner $signer;

    private JwtVerifier $verifier;

    protected function setUp(): void
    {
        $this->signer = new JwtTestSigner();
        $signer = $this->signer;
        $this->verifier = new JwtVerifier(
            new MockHttpClient(static fn (): MockResponse => new MockResponse($signer->jwksJson())),
            new ArrayAdapter(),
            new NullLogger(),
        );
    }

    public function testValidLogoutTokenReturnsClaims(): void
    {
        $claims = $this->verify(self::claims());

        self::assertSame('sid-1', $claims['sid']);
    }

    public function testSubAloneOrSidAloneIsEnough(): void
    {
        self::assertSame('user-1', $this->verify(self::claims(['sid' => null]))['sub']);
        self::assertSame('sid-1', $this->verify(self::claims(['sub' => null]))['sid']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'no events' => [self::claims(['events' => null]), 'events'];
        yield 'events without the logout member' => [self::claims(['events' => ['http://schemas.openid.net/event/other' => []]]), 'events'];
        yield 'logout member not an object' => [self::claims(['events' => [JwtVerifier::BACKCHANNEL_LOGOUT_EVENT => 'yes']]), 'events'];
        yield 'nonce present (id_token substitution)' => [self::claims(['nonce' => 'n']), 'nonce'];
        yield 'neither sub nor sid' => [self::claims(['sub' => null, 'sid' => null]), 'neither'];
        yield 'no iat' => [self::claims(['iat' => null]), 'iat'];
        yield 'expired' => [self::claims(['exp' => time() - 5]), 'expired'];
        yield 'wrong audience' => [self::claims(['aud' => 'other-client']), 'audience'];
        yield 'wrong issuer' => [self::claims(['iss' => 'https://evil.example']), 'issuer'];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('invalidTokens')]
    public function testInvalidLogoutTokensAreRejected(array $claims, string $messagePart): void
    {
        $this->expectException(InvalidJwtException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($messagePart, '/') . '/i');

        $this->verify($claims);
    }

    public function testTokenSignedByAnotherKeyIsRejected(): void
    {
        $this->expectException(InvalidJwtException::class);

        $this->verifier->verifyLogoutToken((new JwtTestSigner('other'))->sign(self::claims()), 'https://idp.example/jwks', self::ISSUER, self::AUDIENCE, 60, 5);
    }

    public function testDecodeUnverifiedReadsWithoutChecking(): void
    {
        $token = (new JwtTestSigner('other'))->sign(self::claims(['iss' => 'https://anything.example']));

        self::assertSame('https://anything.example', $this->verifier->decodeUnverified($token)['iss'] ?? null);
        self::assertNull($this->verifier->decodeUnverified('not-a-jwt'));
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return array<string, mixed>
     */
    private function verify(array $claims): array
    {
        return $this->verifier->verifyLogoutToken($this->signer->sign($claims), 'https://idp.example/jwks', self::ISSUER, self::AUDIENCE, 60, 5);
    }

    /**
     * @param array<string, mixed> $overrides null removes the claim
     *
     * @return array<string, mixed>
     */
    private static function claims(array $overrides = []): array
    {
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'iat' => time(),
            'exp' => time() + 120,
            'jti' => 'jti-' . bin2hex(random_bytes(4)),
            'sub' => 'user-1',
            'sid' => 'sid-1',
            'events' => [JwtVerifier::BACKCHANNEL_LOGOUT_EVENT => new \stdClass()],
        ], $overrides);

        return array_filter($claims, static fn (mixed $value): bool => $value !== null);
    }
}
