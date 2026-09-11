<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Passkey;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Global (non-provider-scoped) Passkey configuration — enable toggles + RP
 * name/id override, read from the plugin's own config.xml-backed system
 * config. Mirrors Helper/PasskeyConfig.php in the Magento module.
 */
class PasskeyConfig
{
    private const CONFIG_DOMAIN = 'Sw6Oidc.config.';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function isEnabledForAdmin(): bool
    {
        return (bool) $this->systemConfigService->get(self::CONFIG_DOMAIN . 'passkeyEnabledAdmin');
    }

    public function isEnabledForCustomer(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfigService->get(self::CONFIG_DOMAIN . 'passkeyEnabledCustomer', $salesChannelId);
    }

    public function getRpName(string $fallback): string
    {
        $configured = (string) ($this->systemConfigService->get(self::CONFIG_DOMAIN . 'passkeyRpName') ?? '');

        return $configured !== '' ? $configured : $fallback;
    }

    public function getRpId(string $fallbackHost): string
    {
        $configured = (string) ($this->systemConfigService->get(self::CONFIG_DOMAIN . 'passkeyRpId') ?? '');

        return $configured !== '' ? $configured : $fallbackHost;
    }
}
