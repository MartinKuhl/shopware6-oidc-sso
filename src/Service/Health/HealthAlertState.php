<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Health;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;

/**
 * The cron-owned health-alert state of one provider, and the transition
 * rules. Alerts fire **once per outage**: an outage starts with the first
 * failed probe (`firstFailureAt`) and is alerted when the consecutive failure
 * count reaches the threshold and nothing was sent since it started. Editing
 * the provider mid-outage (e.g. lowering the threshold) never re-fires —
 * admin writes can't touch this state (WriteProtected fields).
 */
final readonly class HealthAlertState
{
    public const STATUS_OK = 'ok';
    public const STATUS_FAIL = 'fail';

    public const NOTIFY_UNHEALTHY = 'unhealthy';
    public const NOTIFY_RECOVERED = 'recovered';

    public function __construct(
        public int $consecutiveFailures = 0,
        public ?string $lastStatus = null,
        public ?\DateTimeImmutable $firstFailureAt = null,
        public ?\DateTimeImmutable $lastNotifiedAt = null,
    ) {
    }

    public static function fromProvider(Sw6OidcProviderEntity $provider): self
    {
        $immutable = static fn (?\DateTimeInterface $date): ?\DateTimeImmutable => $date instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($date) : null;

        return new self(
            $provider->getHealthAlertConsecutiveFailures(),
            $provider->getHealthAlertLastStatus(),
            $immutable($provider->getHealthAlertFirstFailureAt()),
            $immutable($provider->getHealthAlertLastNotifiedAt()),
        );
    }

    /**
     * @return array{self, self::NOTIFY_*|null} the next state and what to notify (if anything)
     */
    public function next(bool $healthy, int $threshold, bool $notifyOnRecovery, \DateTimeImmutable $now): array
    {
        if ($healthy) {
            $notify = $notifyOnRecovery && $this->alertedCurrentOutage() ? self::NOTIFY_RECOVERED : null;

            return [new self(0, self::STATUS_OK, null, $this->lastNotifiedAt), $notify];
        }

        $failures = $this->consecutiveFailures + 1;
        $firstFailureAt = $this->lastStatus === self::STATUS_FAIL && $this->firstFailureAt instanceof \DateTimeImmutable ? $this->firstFailureAt : $now;
        $next = new self($failures, self::STATUS_FAIL, $firstFailureAt, $this->lastNotifiedAt);

        $due = $threshold > 0
            && $failures >= $threshold
            && (!$this->lastNotifiedAt instanceof \DateTimeImmutable || $this->lastNotifiedAt < $firstFailureAt);

        return [$next, $due ? self::NOTIFY_UNHEALTHY : null];
    }

    public function withNotifiedAt(\DateTimeImmutable $at): self
    {
        return new self($this->consecutiveFailures, $this->lastStatus, $this->firstFailureAt, $at);
    }

    private function alertedCurrentOutage(): bool
    {
        return $this->lastStatus === self::STATUS_FAIL
            && $this->firstFailureAt instanceof \DateTimeImmutable
            && $this->lastNotifiedAt instanceof \DateTimeImmutable
            && $this->lastNotifiedAt >= $this->firstFailureAt;
    }
}
