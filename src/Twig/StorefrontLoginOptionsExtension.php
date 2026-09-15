<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Twig;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyConfig;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyCredentialRepository;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Translation\TranslatorInterface;
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
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sw6oidc_storefront_sso_providers', $this->getSsoProviders(...)),
            new TwigFunction('sw6oidc_storefront_passkey_available', $this->isPasskeyAvailable(...)),
        ];
    }

    /**
     * One button per visible provider, ordered by sortOrder — labeled with
     * the provider's own displayName so "Login with SSO" doesn't get shown
     * for every configured IdP regardless of which one it actually is; only
     * ever falls back to the generic translated string if a provider has no
     * displayName set.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function getSsoProviders(SalesChannelContext $context): array
    {
        return array_map(
            fn (Sw6OidcProviderEntity $provider): array => [
                'id' => $provider->getId(),
                'label' => $provider->getDisplayName() !== null && $provider->getDisplayName() !== ''
                    ? $provider->getDisplayName()
                    : $this->translator->trans('sw6oidc.login.button'),
            ],
            $this->providerResolver->getVisibleProviders('customer', $context->getContext()),
        );
    }

    public function isPasskeyAvailable(SalesChannelContext $context): bool
    {
        return $this->passkeyConfig->isEnabledForCustomer($context->getSalesChannelId())
            && $this->passkeyCredentialRepository->existsForUserType('customer', $context->getContext());
    }
}
