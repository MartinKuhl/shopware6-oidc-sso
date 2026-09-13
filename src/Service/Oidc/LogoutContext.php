<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

final readonly class LogoutContext
{
    public function __construct(
        public string $providerId,
        public ?string $idToken,
    ) {
    }

    /**
     * @return array{providerId: string, idToken: string|null}
     */
    public function toArray(): array
    {
        return ['providerId' => $this->providerId, 'idToken' => $this->idToken];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['providerId'], isset($data['idToken']) ? (string) $data['idToken'] : null);
    }
}
