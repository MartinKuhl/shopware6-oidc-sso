<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Twig;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyConfig;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyCredentialRepository;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use MartinKuhl\Sw6Oidc\Service\Provisioning\UserProviderBindingService;
use MartinKuhl\Sw6Oidc\Service\Security\PasswordLoginPolicy;
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
        private readonly PasswordLoginPolicy $passwordLoginPolicy,
        private readonly UserProviderBindingService $bindingService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sw6oidc_storefront_sso_providers', $this->getSsoProviders(...)),
            new TwigFunction('sw6oidc_storefront_passkey_available', $this->isPasskeyAvailable(...)),
            new TwigFunction('sw6oidc_storefront_password_login_disabled', $this->isPasswordLoginDisabled(...)),
            new TwigFunction('sw6oidc_storefront_account_sso', $this->getAccountSso(...)),
        ];
    }

    /**
     * One button per visible provider, ordered by sortOrder — labeled
     * "Login with {displayName}" so "Login with SSO" doesn't get shown for
     * every configured IdP regardless of which one it actually is; only
     * ever falls back to the plain generic string if a provider has no
     * displayName set (there's nothing to interpolate into the pattern).
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function getSsoProviders(SalesChannelContext $context): array
    {
        return array_map(
            fn (Sw6OidcProviderEntity $provider): array => [
                'id' => $provider->getId(),
                'label' => $provider->getDisplayName() !== null && $provider->getDisplayName() !== ''
                    ? $this->translator->trans('sw6oidc.login.buttonWithProvider', ['%name%' => $provider->getDisplayName()])
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

    public function isPasswordLoginDisabled(SalesChannelContext $context): bool
    {
        return $this->passwordLoginPolicy->isPasswordLoginDisabled('customer', $context->getContext());
    }

    /**
     * The account page's "Single sign-on" card: whether the logged-in
     * customer is connected to a provider, else which providers they can
     * connect explicitly ("Connect SSO").
     *
     * @return array{bound: bool, boundLabel: string|null, providers: list<array{id: string, label: string}>}
     */
    public function getAccountSso(SalesChannelContext $context): array
    {
        $customer = $context->getCustomer();

        if (!$customer instanceof \Shopware\Core\Checkout\Customer\CustomerEntity || $customer->getGuest()) {
            return ['bound' => false, 'boundLabel' => null, 'providers' => []];
        }

        $providers = $this->providerResolver->getVisibleProviders('customer', $context->getContext());
        $boundProviderId = $this->bindingService->getBoundProviderId('customer', $customer->getId(), $context->getContext());

        if ($boundProviderId !== null) {
            $label = null;

            foreach ($providers as $provider) {
                if ($provider->getId() === $boundProviderId) {
                    $label = $provider->getDisplayName() ?: $provider->getAppName();
                }
            }

            return ['bound' => true, 'boundLabel' => $label ?? $this->translator->trans('sw6oidc.login.button'), 'providers' => []];
        }

        return [
            'bound' => false,
            'boundLabel' => null,
            'providers' => array_values(array_map(
                static fn (Sw6OidcProviderEntity $provider): array => [
                    'id' => $provider->getId(),
                    'label' => $provider->getDisplayName() ?: $provider->getAppName(),
                ],
                $providers,
            )),
        ];
    }
}
