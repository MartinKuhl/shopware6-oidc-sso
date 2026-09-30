<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\ScheduledTask;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes activity rows whose login is older than
 * SW6OIDC_SESSION_ACTIVITY_RETENTION_DAYS (default 90; 0 = keep forever).
 */
#[AsMessageHandler(handles: SessionActivityCleanupTask::class)]
class SessionActivityCleanupTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly Connection $connection,
        private readonly int $retentionDays,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        $this->connection->executeStatement(
            'DELETE FROM `sw6oidc_session_activity` WHERE `logged_in_at` < :before',
            ['before' => (new \DateTimeImmutable(sprintf('-%d days', $this->retentionDays)))->format(Defaults::STORAGE_DATE_TIME_FORMAT)],
        );
    }
}
