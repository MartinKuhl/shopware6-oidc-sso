<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\ProviderMismatchException;
use MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\SubjectAlreadyLinkedException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Reads and writes sw6oidc_user_provider: which IdP subject (provider +
 * `iss`/`sub`) owns which Shopware user/customer. An account has at most one
 * binding, a subject at most one account per user type.
 */
class UserProviderBindingService
{
    public function __construct(private readonly EntityRepository $userProviderRepository)
    {
    }

    public function getBinding(string $userType, string $userId, Context $context): ?Sw6OidcUserProviderEntity
    {
        $entity = $this->userProviderRepository
            ->search($this->userCriteria($userType, $userId), $context)
            ->first();

        \assert($entity === null || $entity instanceof Sw6OidcUserProviderEntity);

        return $entity;
    }

    public function getBoundProviderId(string $userType, string $userId, Context $context): ?string
    {
        return $this->getBinding($userType, $userId, $context)?->getProviderId();
    }

    /**
     * The account this IdP subject is bound to, if any.
     */
    public function findUserIdBySubject(string $userType, string $providerId, string $subject, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('userType', $userType));
        $criteria->addFilter(new EqualsFilter('providerId', $providerId));
        $criteria->addFilter(new EqualsFilter('sub', $subject));
        $criteria->setLimit(1);

        $entity = $this->userProviderRepository->search($criteria, $context)->first();
        \assert($entity === null || $entity instanceof Sw6OidcUserProviderEntity);

        return $entity?->getUserId();
    }

    /**
     * @throws ProviderMismatchException when the account is already bound to a
     *                                   different provider than the one currently authenticating
     */
    public function assertNotBoundToDifferentProvider(string $userType, string $userId, string $providerId, Context $context): void
    {
        $bound = $this->getBoundProviderId($userType, $userId, $context);

        if ($bound !== null && $bound !== $providerId) {
            throw new ProviderMismatchException(sprintf(
                'This %s account is bound to a different identity provider.',
                $userType,
            ));
        }
    }

    /**
     * Binds an unbound account to an IdP subject. A concurrent first login
     * for the same account or subject loses the unique-key race; it then
     * re-reads and accepts the binding only if it is the identical one.
     *
     * @throws ProviderMismatchException     when the account got bound to another provider/subject
     * @throws SubjectAlreadyLinkedException when the subject is already bound to another account
     */
    public function bind(string $userType, string $userId, ExternalIdentity $identity, Context $context): void
    {
        $existingUserId = $this->findUserIdBySubject($userType, $identity->providerId, $identity->subject, $context);

        if ($existingUserId !== null && $existingUserId !== $userId) {
            throw new SubjectAlreadyLinkedException(sprintf('This identity is already linked to another %s account.', $userType));
        }

        if ($existingUserId === $userId) {
            return;
        }

        try {
            $this->asSystem($context, fn (Context $systemContext): EntityWrittenContainerEvent => $this->userProviderRepository->create([[
                'id' => Uuid::randomHex(),
                'userType' => $userType,
                'userId' => $userId,
                'providerId' => $identity->providerId,
                'issuer' => $identity->issuer,
                'sub' => $identity->subject,
            ]], $systemContext));
        } catch (UniqueConstraintViolationException $exception) {
            $binding = $this->getBinding($userType, $userId, $context);

            if (
                !$binding instanceof \MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity
                || $binding->getProviderId() !== $identity->providerId
                || $binding->getSub() !== $identity->subject
            ) {
                throw new ProviderMismatchException(sprintf('This %s account is bound to a different identity.', $userType), 0, $exception);
            }
        }
    }

    /**
     * Upgrades a legacy (pre-subject) binding to the subject that just
     * authenticated through its provider.
     */
    public function backfillSubject(Sw6OidcUserProviderEntity $binding, ExternalIdentity $identity, Context $context): void
    {
        $this->asSystem($context, fn (Context $systemContext): EntityWrittenContainerEvent => $this->userProviderRepository->update([[
            'id' => $binding->getId(),
            'issuer' => $identity->issuer,
            'sub' => $identity->subject,
        ]], $systemContext));
    }

    public function unbind(string $userType, string $userId, Context $context): void
    {
        $ids = $this->userProviderRepository
            ->searchIds($this->userCriteria($userType, $userId), $context)
            ->getIds();

        if ($ids === []) {
            return;
        }

        $this->asSystem($context, fn (Context $systemContext): EntityWrittenContainerEvent => $this->userProviderRepository->delete(
            array_map(static fn (string $id): array => ['id' => $id], $ids),
            $systemContext,
        ));
    }

    /**
     * Bindings are writable in system scope only (R3-H3); this service is
     * their one writer, whatever context its caller holds.
     *
     * @param callable(Context): mixed $write
     */
    private function asSystem(Context $context, callable $write): void
    {
        $context->scope(Context::SYSTEM_SCOPE, $write);
    }

    private function userCriteria(string $userType, string $userId): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('userType', $userType));
        $criteria->addFilter(new EqualsFilter('userId', $userId));

        return $criteria;
    }
}
