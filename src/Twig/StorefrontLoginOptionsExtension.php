<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Twig;

use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyConfig;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyCredentialRepository;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Lets the Storefront login template decide whether to show its "Login with
 * SSO"/"Login with Passkey" buttons at all - unlike the Administration
 * (email-scoped) case, the Storefront login page is anonymous with no
 * customer identity yet, so this can only ever answer "is SSO/Passkey login
 * configured/enabled for this sales channel AND (for passkey) has at least
 * one customer actually registered one", never anything about whether the
 * not-yet-identified visitor specifically has a passkey registered. Mirrors
 * OidcAdminAuthController::loginOptions()'s same reasoning on the
 * Administration side.
 */
class StorefrontLoginOptionsExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly PasskeyConfig $passkeyConfig,
        private readonly PasskeyCredentialRepository $passkeyCredentialRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sw6oidc_storefront_sso_available', $this->isSsoAvailable(...)),
            new TwigFunction('sw6oidc_storefront_passkey_available', $this->isPasskeyAvailable(...)),
        ];
    }

    public function isSsoAvailable(SalesChannelContext $context): bool
    {
        return $this->providerResolver->getActiveProviders('customer', $context->getContext()) !== [];
    }

    public function isPasskeyAvailable(SalesChannelContext $context): bool
    {
        return $this->passkeyConfig->isEnabledForCustomer($context->getSalesChannelId())
            && $this->passkeyCredentialRepository->existsForUserType('customer', $context->getContext());
    }
}
