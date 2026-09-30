<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Security;

use Doctrine\DBAL\Connection;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionDestructionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;

/**
 * When SSO-only mode is switched on, sessions that were started with a
 * password would otherwise keep renewing (admin refresh tokens, customer
 * context tokens). This ends the sessions of every account without an SSO
 * binding — they can only have logged in with a password. Bound accounts
 * keep their sessions: they may well be SSO sessions.
 */
class PasswordSessionRevoker
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LockoutGuard $lockoutGuard,
        private readonly Sw6OidcSessionDestructionService $destructionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function revokeUnboundAdminSessions(): void
    {
        $userIds = $this->lockoutGuard->unboundActiveAdminIds();

        foreach ($userIds as $userId) {
            $this->destructionService->destroyAdminSessions($userId);
        }

        $this->logger->warning('sw6oidc: Administration password login disabled; ended the sessions of admins without SSO binding.', [
            'count' => \count($userIds),
        ]);
    }

    public function revokeUnboundCustomerSessions(): void
    {
        // Same effect as SalesChannelContextPersister::revokeAllCustomerTokens(), in one statement.
        $count = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE `sales_channel_api_context`
                SET `payload` = :payload, `customer_id` = NULL, `updated_at` = :now
                WHERE `customer_id` IS NOT NULL
                  AND `customer_id` NOT IN (
                      SELECT `user_id` FROM `sw6oidc_user_provider` WHERE `user_type` = 'customer'
                  )
            SQL,
            [
                'payload' => json_encode(['customerId' => null, 'billingAddressId' => null, 'shippingAddressId' => null], JSON_THROW_ON_ERROR),
                'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
        );

        $this->logger->warning('sw6oidc: customer password login disabled; ended the sessions of customers without SSO binding.', [
            'count' => $count,
        ]);
    }
}
