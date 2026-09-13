<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Passkey;

use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(PasskeyConfig::class)]
final class PasskeyConfigTest extends TestCase
{
    public function testIsEnabledForAdminReflectsTheStoredValue(): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')
            ->with('Sw6Oidc.config.passkeyEnabledAdmin')
            ->willReturn(true);

        $config = new PasskeyConfig($systemConfigService);

        self::assertTrue($config->isEnabledForAdmin());
    }

    public function testIsEnabledForAdminIsFalseWhenNothingIsConfigured(): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')->willReturn(null);

        $config = new PasskeyConfig($systemConfigService);

        self::assertFalse($config->isEnabledForAdmin());
    }

    public function testIsEnabledForCustomerPassesTheSalesChannelIdThrough(): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')
            ->with('Sw6Oidc.config.passkeyEnabledCustomer', 'sales-channel-1')
            ->willReturn(true);

        $config = new PasskeyConfig($systemConfigService);

        self::assertTrue($config->isEnabledForCustomer('sales-channel-1'));
    }

    public function testGetRpNameReturnsTheConfiguredValueWhenPresent(): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')->willReturn('Configured Shop Name');

        $config = new PasskeyConfig($systemConfigService);

        self::assertSame('Configured Shop Name', $config->getRpName('Fallback Name'));
    }

    public function testGetRpNameFallsBackWhenNothingIsConfigured(): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')->willReturn(null);

        $config = new PasskeyConfig($systemConfigService);

        self::assertSame('Fallback Name', $config->getRpName('Fallback Name'));
    }

    public function testGetRpIdFallsBackToTheGivenHostWhenNothingIsConfigured(): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')->willReturn('');

        $config = new PasskeyConfig($systemConfigService);

        self::assertSame('shop.example', $config->getRpId('shop.example'));
    }

    public function testGetRpIdUsesTheConfiguredOverrideWhenPresent(): void
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')->willReturn('override.example');

        $config = new PasskeyConfig($systemConfigService);

        self::assertSame('override.example', $config->getRpId('shop.example'));
    }
}
