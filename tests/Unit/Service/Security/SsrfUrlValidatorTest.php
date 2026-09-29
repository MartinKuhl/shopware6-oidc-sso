<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Security;

use MartinKuhl\Sw6Oidc\Service\Security\SsrfUrlValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SsrfUrlValidator::class)]
final class SsrfUrlValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPublicAddresses(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'rfc1918 10/8' => ['10.0.0.5'];
        yield 'rfc1918 172.16/12' => ['172.20.1.1'];
        yield 'rfc1918 192.168/16' => ['192.168.1.10'];
        yield 'link-local / cloud metadata' => ['169.254.169.254'];
        yield 'cgnat 100.64/10' => ['100.64.0.1'];
        yield 'this-network 0/8' => ['0.0.0.0'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'ipv6 loopback' => ['::1'];
        yield 'ipv6 ula' => ['fd00::1'];
        yield 'ipv4-mapped ipv6' => ['::ffff:10.0.0.1'];
    }

    public function testRejectsAMalformedUrl(): void
    {
        self::assertTrue($this->validator()->validate('not a url')['blocked']);
    }

    public function testRejectsAnUnsupportedScheme(): void
    {
        self::assertTrue($this->validator()->validate('ftp://idp.example/.well-known/openid-configuration')['blocked']);
    }

    public function testRejectsPlainHttpByDefault(): void
    {
        self::assertTrue($this->validator()->validate('http://idp.example/.well-known/openid-configuration')['blocked']);
    }

    #[DataProvider('nonPublicAddresses')]
    public function testRejectsNonPublicAddresses(string $ip): void
    {
        self::assertTrue($this->validator(ips: [$ip])->validate('https://idp.example/')['blocked']);
    }

    public function testRejectsIfAnyResolvedAddressIsPrivate(): void
    {
        self::assertTrue($this->validator(ips: ['8.8.8.8', '10.0.0.1'])->validate('https://idp.example/')['blocked']);
    }

    public function testRejectsAnIpLiteralHost(): void
    {
        self::assertTrue((new SsrfUrlValidator())->validate('https://169.254.169.254/latest/meta-data')['blocked']);
    }

    public function testRejectsAnUnresolvableHost(): void
    {
        self::assertTrue($this->validator(ips: [])->validate('https://idp.example/')['blocked']);
    }

    public function testRejectsUnresolvableHostEvenInInsecureMode(): void
    {
        self::assertTrue($this->validator(insecure: true, ips: [])->validate('https://idp.example/')['blocked']);
    }

    public function testAllowsAPublicHttpsUrlWithoutWarnings(): void
    {
        self::assertSame(['blocked' => false, 'warnings' => []], $this->validator()->validate('https://idp.example/.well-known/openid-configuration'));
    }

    public function testInsecureModeAllowsHttpAndPrivateHostsWithWarnings(): void
    {
        $result = $this->validator(insecure: true, ips: ['172.18.0.5'])->validate('http://authelia:9091/.well-known/openid-configuration');

        self::assertFalse($result['blocked']);
        self::assertCount(2, $result['warnings']);
    }

    /**
     * @param list<string> $ips
     */
    private function validator(bool $insecure = false, array $ips = ['93.184.215.14']): SsrfUrlValidator
    {
        return new SsrfUrlValidator($insecure, static fn (string $host): array => $ips);
    }
}
