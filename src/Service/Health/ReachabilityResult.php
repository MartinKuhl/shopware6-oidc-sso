<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Health;

final readonly class ReachabilityResult
{
    public function __construct(
        public bool $ok,
        /** 'jwks' | 'discovery' | null when nothing could be probed */
        public ?string $checked,
        public string $detail,
        public int $durationMs,
    ) {
    }

    /**
     * @return array{ok: bool, checked: string|null, detail: string, durationMs: int}
     */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'checked' => $this->checked, 'detail' => $this->detail, 'durationMs' => $this->durationMs];
    }
}
