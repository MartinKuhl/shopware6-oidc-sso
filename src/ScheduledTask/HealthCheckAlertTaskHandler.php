<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\ScheduledTask;

use MartinKuhl\Sw6Oidc\Service\Health\NodeHeartbeat;
use MartinKuhl\Sw6Oidc\Service\Health\ProviderHealthMonitor;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: HealthCheckAlertTask::class)]
class HealthCheckAlertTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ProviderHealthMonitor $monitor,
        private readonly ?NodeHeartbeat $nodeHeartbeat = null,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $this->nodeHeartbeat?->record();
        $this->monitor->run();
    }
}
