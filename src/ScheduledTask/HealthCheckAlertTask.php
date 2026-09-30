<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Every 5 minutes: probe providers with alerting configured and send due
 * webhook alerts (ProviderHealthMonitor). Registered in scheduled_task
 * automatically on plugin install/update (core's PluginLifecycleSubscriber).
 */
class HealthCheckAlertTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'sw6oidc.health_check_alert';
    }

    public static function getDefaultInterval(): int
    {
        return 300;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
