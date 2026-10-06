<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Passkey;

use Doctrine\DBAL\Connection;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyConfig;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyRelyingPartyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasskeyRelyingPartyResolver::class)]
final class PasskeyRelyingPartyResolverTest extends TestCase
{
    public function testAdministrationUsesTheAppUrlNotTheRequestHost(): void
    {
        $rp = $this->resolver('https://Shop.Example:8443/some/path')->forAdministration();

        self::assertSame('shop.example', $rp->id);
        self::assertSame(['https://shop.example:8443'], $rp->origins);
    }

    public function testConfiguredRpIdKeepsOnlyOriginsItCovers(): void
    {
        $config = $this->createStub(PasskeyConfig::class);
        $config->method('getAdminRpId')->willReturn('example.com');
        $config->method('getRpName')->willReturn('Shop');

        $rp = (new PasskeyRelyingPartyResolver($config, $this->createStub(Connection::class), 'https://admin.example.com'))->forAdministration();

        self::assertSame('example.com', $rp->id);
        self::assertSame(['https://admin.example.com'], $rp->origins);

        $mismatch = (new PasskeyRelyingPartyResolver($config, $this->createStub(Connection::class), 'https://other.test'))->forAdministration();
        self::assertSame([], $mismatch->origins, 'an origin outside the RP ID can never be used');
    }

    public function testOriginNormalisation(): void
    {
        self::assertSame('https://shop.example', PasskeyRelyingPartyResolver::origin('https://SHOP.example/path?x'));
        self::assertNull(PasskeyRelyingPartyResolver::origin('javascript:alert(1)'));
        self::assertNull(PasskeyRelyingPartyResolver::origin('/relative'));
    }

    private function resolver(string $appUrl): PasskeyRelyingPartyResolver
    {
        $config = $this->createStub(PasskeyConfig::class);
        $config->method('getAdminRpId')->willReturnArgument(0);
        $config->method('getRpName')->willReturnArgument(0);

        return new PasskeyRelyingPartyResolver($config, $this->createStub(Connection::class), $appUrl);
    }
}
