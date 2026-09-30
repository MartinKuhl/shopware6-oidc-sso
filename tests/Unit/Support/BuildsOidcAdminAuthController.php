<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Support;

use League\OAuth2\Server\AuthorizationServer;
use MartinKuhl\Sw6Oidc\Controller\Api\OidcAdminAuthController;
use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminLoginErrorTicketStore;
use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminLoginNonceService;
use MartinKuhl\Sw6Oidc\Service\Http\OidcHttpClient;
use MartinKuhl\Sw6Oidc\Service\Oidc\AuthorizationRequestBuilder;
use MartinKuhl\Sw6Oidc\Service\Oidc\LogoutContextStore;
use MartinKuhl\Sw6Oidc\Service\Oidc\OidcCallbackProcessor;
use MartinKuhl\Sw6Oidc\Service\Oidc\PostLogoutState;
use MartinKuhl\Sw6Oidc\Service\Oidc\RpInitiatedLogoutService;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyConfig;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyCredentialRepository;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use MartinKuhl\Sw6Oidc\Service\Provisioning\AdminProvisioningService;
use MartinKuhl\Sw6Oidc\Service\Provisioning\IdentityResolver;
use MartinKuhl\Sw6Oidc\Service\Provisioning\UserProviderBindingService;
use MartinKuhl\Sw6Oidc\Service\Security\PasswordLoginPolicy;
use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcRateLimiter;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionActivityRecorder;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\NullLogger;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;

/**
 * Builds an OidcAdminAuthController with working defaults for every
 * dependency; tests override only what they exercise, by constructor
 * parameter name.
 */
trait BuildsOidcAdminAuthController
{
    /**
     * @param array<string, mixed> $overrides constructor parameter name => value
     */
    private function buildAdminAuthController(array $overrides = []): OidcAdminAuthController
    {
        $psr17 = new Psr17Factory();

        $defaults = [
            'providerResolver' => $this->createMock(ProviderResolver::class),
            'requestBuilder' => $this->createMock(AuthorizationRequestBuilder::class),
            'callbackProcessor' => $this->createMock(OidcCallbackProcessor::class),
            'adminProvisioningService' => $this->createMock(AdminProvisioningService::class),
            'loginNonceService' => new AdminLoginNonceService(new InMemoryAtomicCache()),
            'adminAuthorizationServer' => $this->createMock(AuthorizationServer::class),
            'psrHttpFactory' => new PsrHttpFactory($psr17, $psr17, $psr17, $psr17),
            'administrationBaseUrl' => 'https://shop.example/admin',
            'logger' => new NullLogger(),
            'passkeyConfig' => $this->createMock(PasskeyConfig::class),
            'passkeyCredentialRepository' => $this->createMock(PasskeyCredentialRepository::class),
            'bindingService' => $this->createMock(UserProviderBindingService::class),
            'passwordLoginPolicy' => $this->createMock(PasswordLoginPolicy::class),
            'logoutContextStore' => new LogoutContextStore(new InMemoryAtomicCache()),
            'rpInitiatedLogoutService' => new RpInitiatedLogoutService($this->createMock(OidcHttpClient::class), new NullLogger(), new PostLogoutState('app-secret')),
            'loginErrorTicketStore' => new AdminLoginErrorTicketStore(new InMemoryAtomicCache()),
            'sessionRegistry' => SqliteSessionRegistry::create(),
            'rateLimiter' => new Sw6OidcRateLimiter(null, new ArrayAdapter()),
            'activityRecorder' => $this->createMock(Sw6OidcSessionActivityRecorder::class),
            'identityResolver' => $this->createMock(IdentityResolver::class),
        ];

        $unknown = array_diff_key($overrides, $defaults);

        if ($unknown !== []) {
            throw new \LogicException('Unknown constructor parameters: ' . implode(', ', array_keys($unknown)));
        }

        $controller = new OidcAdminAuthController(...array_merge($defaults, $overrides));
        // AbstractController::json() needs a container; an empty one makes it fall back to plain JsonResponse.
        $controller->setContainer(new Container());

        return $controller;
    }
}
