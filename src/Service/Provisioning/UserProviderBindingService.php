<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning;

use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\ProviderMismatchException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Reads and writes sw6oidc_user_provider — enforces that the IdP which first
 * authenticates (or creates) a Shopware user/customer stays permanently bound to
 * that account. Mirrors the Magento module's UserProviderResource /
 * per-user-IdP-binding enforcement in ProcessUserAction/CheckAttributeMappingAction.
 */
class UserProviderBindingService
{
    public function __construct(private readonly EntityRepository $userProviderRepository)
    {
    }

    public function getBoundProviderId(string $userType, string $userId, Context $context): ?string
    {
        $entity = $this->userProviderRepository
            ->search($this->userCriteria($userType, $userId), $context)
            ->first();

        \assert($entity === null || $entity instanceof Sw6OidcUserProviderEntity);

        return $entity?->getProviderId();
    }

    /**
     * @throws ProviderMismatchException when the account is already bound to a
     *                                    different provider than the one currently authenticating
     */
    public function assertNotBoundToDifferentProvider(string $userType, string $userId, string $providerId, Context $context): void
    {
        $bound = $this->getBoundProviderId($userType, $userId, $context);

        if ($bound !== null && $bound !== $providerId) {
            throw new ProviderMismatchException(sprintf(
                'This %s account was created with a different identity provider.',
                $userType,
            ));
        }
    }

    /**
     * First login wins: claims the binding only if the account has none yet
     * (a pre-existing account created before OIDC was configured).
     */
    public function bindIfUnbound(string $userType, string $userId, string $providerId, Context $context): void
    {
        if ($this->getBoundProviderId($userType, $userId, $context) !== null) {
            return;
        }

        $this->userProviderRepository->create([[
            'id' => Uuid::randomHex(),
            'userType' => $userType,
            'userId' => $userId,
            'providerId' => $providerId,
        ]], $context);
    }

    public function unbind(string $userType, string $userId, Context $context): void
    {
        $ids = $this->userProviderRepository
            ->searchIds($this->userCriteria($userType, $userId), $context)
            ->getIds();

        if ($ids === []) {
            return;
        }

        $this->userProviderRepository->delete(
            array_map(static fn (string $id): array => ['id' => $id], $ids),
            $context,
        );
    }

    private function userCriteria(string $userType, string $userId): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('userType', $userType));
        $criteria->addFilter(new EqualsFilter('userId', $userId));

        return $criteria;
    }
}
