<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Controller\Api;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Backs the Passkey Settings "Relying Party ID (domain)" field's Administration
 * component: resolves and returns the actual host this shop is reachable
 * under, so the field can show it as its default instead of only descriptive
 * placeholder text — see Resources/app/administration/src/component/sw6oidc-rp-id-field.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
#[Package('framework')]
class PasskeyDefaultHostController extends AbstractController
{
    public function __construct(private readonly EntityRepository $salesChannelDomainRepository)
    {
    }

    #[Route(
        path: '/api/_action/sw6oidc/passkey/default-rp-host',
        name: 'api.action.sw6oidc.passkey.default-rp-host',
        methods: ['GET'],
    )]
    public function defaultHost(): JsonResponse
    {
        return new JsonResponse(['host' => $this->resolveHost()]);
    }

    private function resolveHost(): string
    {
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->setLimit(1);

        $domain = $this->salesChannelDomainRepository->search($criteria, Context::createDefaultContext())->first();

        if ($domain instanceof SalesChannelDomainEntity) {
            $host = parse_url($domain->getUrl(), PHP_URL_HOST);

            if (\is_string($host) && $host !== '') {
                return $host;
            }
        }

        $appUrl = (string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '');
        $host = parse_url($appUrl, PHP_URL_HOST);

        return \is_string($host) ? $host : '';
    }
}
