<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Daily retention cleanup of sw6oidc_session_activity (IP addresses and user
 * agents are personal data). Registered in scheduled_task automatically on
 * plugin install/update (core's PluginLifecycleSubscriber).
 */
class SessionActivityCleanupTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'sw6oidc.session_activity_cleanup';
    }

    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
