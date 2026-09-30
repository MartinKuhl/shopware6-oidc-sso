<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\ScheduledTask;

use Doctrine\DBAL\Connection;
use MartinKuhl\Sw6Oidc\ScheduledTask\SessionActivityCleanupTask;
use MartinKuhl\Sw6Oidc\ScheduledTask\SessionActivityCleanupTaskHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

#[CoversClass(SessionActivityCleanupTaskHandler::class)]
#[CoversClass(SessionActivityCleanupTask::class)]
final class SessionActivityCleanupTaskHandlerTest extends TestCase
{
    public function testDeletesRowsOlderThanTheRetention(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->with(
            self::stringContains('DELETE FROM `sw6oidc_session_activity`'),
            self::callback(static fn (array $params): bool => abs(strtotime($params['before']) - strtotime('-30 days')) < 5),
        );

        (new SessionActivityCleanupTaskHandler($this->createMock(EntityRepository::class), new NullLogger(), $connection, 30))->run();
    }

    public function testZeroRetentionKeepsEverything(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        (new SessionActivityCleanupTaskHandler($this->createMock(EntityRepository::class), new NullLogger(), $connection, 0))->run();
    }

    public function testTaskRunsDaily(): void
    {
        self::assertSame('sw6oidc.session_activity_cleanup', SessionActivityCleanupTask::getTaskName());
        self::assertSame(86400, SessionActivityCleanupTask::getDefaultInterval());
    }
}
