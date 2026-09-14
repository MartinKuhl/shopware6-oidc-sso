<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Oidc;

use MartinKuhl\Sw6Oidc\Service\Oidc\DiscoveryUrlValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiscoveryUrlValidator::class)]
final class DiscoveryUrlValidatorTest extends TestCase
{
    public function testRejectsAMalformedUrl(): void
    {
        $result = (new DiscoveryUrlValidator())->validate('not a url');

        self::assertTrue($result['blocked']);
    }

    public function testRejectsAnUnsupportedScheme(): void
    {
        $result = (new DiscoveryUrlValidator())->validate('ftp://example.com/.well-known/openid-configuration');

        self::assertTrue($result['blocked']);
    }

    public function testRejectsALoopbackAddress(): void
    {
        $result = (new DiscoveryUrlValidator())->validate('http://127.0.0.1/.well-known/openid-configuration');

        self::assertTrue($result['blocked']);
    }

    public function testRejectsAPrivateAddress(): void
    {
        $result = (new DiscoveryUrlValidator())->validate('https://10.0.0.5/.well-known/openid-configuration');

        self::assertTrue($result['blocked']);
    }

    public function testRejectsALinkLocalAddress(): void
    {
        $result = (new DiscoveryUrlValidator())->validate('https://169.254.1.1/.well-known/openid-configuration');

        self::assertTrue($result['blocked']);
    }

    public function testAllowsAPublicHttpsAddressWithoutWarnings(): void
    {
        $result = (new DiscoveryUrlValidator())->validate('https://8.8.8.8/.well-known/openid-configuration');

        self::assertFalse($result['blocked']);
        self::assertSame([], $result['warnings']);
    }

    public function testAllowsAPublicHttpAddressButWarns(): void
    {
        $result = (new DiscoveryUrlValidator())->validate('http://8.8.8.8/.well-known/openid-configuration');

        self::assertFalse($result['blocked']);
        self::assertNotSame([], $result['warnings']);
    }
}
