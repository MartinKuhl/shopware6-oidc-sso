<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Security;

use MartinKuhl\Sw6Oidc\Service\Security\Exception\InvalidStateException;
use MartinKuhl\Sw6Oidc\Service\Security\OidcSecurityHelper;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\InMemoryAtomicCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OidcSecurityHelper::class)]
final class OidcSecurityHelperTest extends TestCase
{
    private const BASE64URL_PATTERN = '/^[A-Za-z0-9_-]+$/';

    private InMemoryAtomicCache $cache;

    private OidcSecurityHelper $helper;

    protected function setUp(): void
    {
        $this->cache = new InMemoryAtomicCache();
        $this->helper = new OidcSecurityHelper($this->cache);
    }

    public function testBeginGeneratesUrlSafeStateNonceAndChallenge(): void
    {
        $result = $this->helper->beginAuthorizationRequest('provider-1', 'customer', '/account', 'S256');

        self::assertMatchesRegularExpression(self::BASE64URL_PATTERN, $result['state']);
        self::assertMatchesRegularExpression(self::BASE64URL_PATTERN, $result['nonce']);
        self::assertMatchesRegularExpression(self::BASE64URL_PATTERN, $result['codeChallenge']);
        // 32 random bytes -> 43 base64url chars without padding.
        self::assertSame(43, \strlen($result['state']));
        self::assertSame(43, \strlen($result['nonce']));
        self::assertNotSame($result['state'], $result['nonce']);
    }

    public function testEachRequestGetsFreshRandomValues(): void
    {
        $first = $this->helper->beginAuthorizationRequest('provider-1', 'customer', '/', 'S256');
        $second = $this->helper->beginAuthorizationRequest('provider-1', 'customer', '/', 'S256');

        self::assertNotSame($first['state'], $second['state']);
        self::assertNotSame($first['nonce'], $second['nonce']);
        self::assertNotSame($first['codeChallenge'], $second['codeChallenge']);
        self::assertCount(2, $this->cache->items);
    }

    public function testFlowIsCachedUnderStateKey(): void
    {
        $result = $this->helper->beginAuthorizationRequest('provider-1', 'admin', '/admin', 'S256');

        self::assertArrayHasKey('sw6oidc_flow_' . $result['state'], $this->cache->items);
    }

    public function testS256ChallengeIsBase64UrlSha256OfVerifier(): void
    {
        $result = $this->helper->beginAuthorizationRequest('provider-1', 'customer', '/', 'S256');
        $flow = $this->helper->consumeAuthorizationFlow($result['state']);

        $expected = rtrim(strtr(base64_encode(hash('sha256', $flow->codeVerifier, true)), '+/', '-_'), '=');

        self::assertSame($expected, $result['codeChallenge']);
        self::assertNotSame($flow->codeVerifier, $result['codeChallenge']);
        // 64 random bytes -> 86 base64url chars; RFC 7636 requires 43..128.
        self::assertSame(86, \strlen($flow->codeVerifier));
        self::assertMatchesRegularExpression(self::BASE64URL_PATTERN, $flow->codeVerifier);
    }

    public function testPlainChallengeEqualsVerifier(): void
    {
        $result = $this->helper->beginAuthorizationRequest('provider-1', 'customer', '/', 'plain');
        $flow = $this->helper->consumeAuthorizationFlow($result['state']);

        self::assertSame($flow->codeVerifier, $result['codeChallenge']);
        self::assertSame('plain', $flow->codeChallengeMethod);
    }

    public function testDeriveCodeChallengeMatchesRfc7636Vector(): void
    {
        // RFC 7636 Appendix B.
        self::assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            $this->helper->deriveCodeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'S256'),
        );
        self::assertSame('verifier', $this->helper->deriveCodeChallenge('verifier', 'plain'));
    }

    public function testConsumeReturnsStoredContext(): void
    {
        $result = $this->helper->beginAuthorizationRequest('provider-1', 'admin', '/admin#/dashboard', 'S256');
        $flow = $this->helper->consumeAuthorizationFlow($result['state']);

        self::assertSame('provider-1', $flow->providerId);
        self::assertSame('admin', $flow->loginType);
        self::assertSame('/admin#/dashboard', $flow->relayState);
        self::assertSame('S256', $flow->codeChallengeMethod);
        self::assertSame($result['nonce'], $flow->nonce);
    }

    public function testConsumeIsSingleUse(): void
    {
        $result = $this->helper->beginAuthorizationRequest('provider-1', 'customer', '/', 'S256');
        $this->helper->consumeAuthorizationFlow($result['state']);

        self::assertSame([], $this->cache->items);

        $this->expectException(InvalidStateException::class);
        $this->helper->consumeAuthorizationFlow($result['state']);
    }

    public function testConsumeRejectsNullState(): void
    {
        $this->expectException(InvalidStateException::class);
        $this->helper->consumeAuthorizationFlow(null);
    }

    public function testConsumeRejectsEmptyState(): void
    {
        $this->expectException(InvalidStateException::class);
        $this->helper->consumeAuthorizationFlow('');
    }

    public function testConsumeRejectsUnknownState(): void
    {
        $this->helper->beginAuthorizationRequest('provider-1', 'customer', '/', 'S256');

        $this->expectException(InvalidStateException::class);
        $this->helper->consumeAuthorizationFlow('not-a-real-state');
    }

    public function testConsumeRejectsExpiredState(): void
    {
        $result = $this->helper->beginAuthorizationRequest('provider-1', 'customer', '/', 'S256');
        // Simulate TTL expiry: the cache entry is gone.
        unset($this->cache->items['sw6oidc_flow_' . $result['state']]);

        $this->expectException(InvalidStateException::class);
        $this->helper->consumeAuthorizationFlow($result['state']);
    }

    public function testConsumeRejectsCorruptedStoredFlow(): void
    {
        $this->cache->items['sw6oidc_flow_broken'] = '{not json';

        $this->expectException(InvalidStateException::class);
        $this->helper->consumeAuthorizationFlow('broken');
    }
}
