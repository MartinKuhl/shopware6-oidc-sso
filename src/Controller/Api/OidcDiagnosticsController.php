<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Controller\Api;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Health\ProviderConfigInspector;
use MartinKuhl\Sw6Oidc\Service\Health\ProviderReachabilityChecker;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * On-demand diagnostics for one provider (the provider detail page's
 * diagnostics panel): configuration problems, a live reachability probe, and
 * the scheduled health-alert state.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class OidcDiagnosticsController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $providerRepository,
        private readonly ProviderConfigInspector $configInspector,
        private readonly ProviderReachabilityChecker $reachabilityChecker,
    ) {
    }

    #[Route(
        path: '/api/_action/sw6oidc/provider/{providerId}/diagnostics',
        name: 'api.action.sw6oidc.provider.diagnostics',
        defaults: ['_acl' => ['sw6oidc_provider:read']],
        methods: ['POST'],
    )]
    public function diagnostics(string $providerId, Context $context): JsonResponse
    {
        $provider = $this->providerRepository->search(new Criteria([$providerId]), $context)->first();

        if (!$provider instanceof Sw6OidcProviderEntity) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $format = static fn (?\DateTimeInterface $date): ?string => $date?->format(\DATE_ATOM);

        return new JsonResponse([
            'configProblems' => $this->configInspector->problems($provider),
            'reachability' => $this->reachabilityChecker->check($provider)->toArray(),
            'alerting' => [
                'enabled' => $provider->getHealthAlertFailureThreshold() > 0 && (string) $provider->getHealthAlertWebhookUrl() !== '',
                // The URL itself is write-only; the form only needs to know whether one is stored.
                'webhookConfigured' => (string) $provider->getHealthAlertWebhookUrl() !== '',
                'threshold' => $provider->getHealthAlertFailureThreshold(),
                'lastStatus' => $provider->getHealthAlertLastStatus(),
                'lastCheckedAt' => $format($provider->getHealthAlertLastCheckedAt()),
                'consecutiveFailures' => $provider->getHealthAlertConsecutiveFailures(),
                'firstFailureAt' => $format($provider->getHealthAlertFirstFailureAt()),
                'lastNotifiedAt' => $format($provider->getHealthAlertLastNotifiedAt()),
            ],
        ]);
    }
}
