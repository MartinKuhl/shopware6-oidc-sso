<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Session;

/**
 * One OIDC login as the session registry remembers it: which IdP subject /
 * IdP session (`sid`) it belongs to, and which local session it created.
 *
 * `sessionKey` is the sales-channel context token (customer) or the jti of
 * the first access token minted for the login (admin).
 */
final readonly class Sw6OidcSession
{
    public const USER_TYPE_CUSTOMER = 'customer';
    public const USER_TYPE_ADMIN = 'admin';

    public function __construct(
        public string $id,
        public string $providerId,
        public string $sub,
        public ?string $sid,
        /** 'customer' | 'admin' */
        public string $userType,
        public string $userId,
        public string $sessionKey,
        public ?string $salesChannelId = null,
        public ?string $idToken = null,
        public int $createdAt = 0,
    ) {
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'providerId' => $this->providerId,
            'sub' => $this->sub,
            'sid' => $this->sid,
            'userType' => $this->userType,
            'userId' => $this->userId,
            'sessionKey' => $this->sessionKey,
            'salesChannelId' => $this->salesChannelId,
            'idToken' => $this->idToken,
            'createdAt' => $this->createdAt,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['id', 'providerId', 'sub', 'userType', 'userId', 'sessionKey'] as $required) {
            if (!\is_string($data[$required] ?? null) || $data[$required] === '') {
                return null;
            }
        }

        return new self(
            $data['id'],
            $data['providerId'],
            $data['sub'],
            \is_string($data['sid'] ?? null) ? $data['sid'] : null,
            $data['userType'],
            $data['userId'],
            $data['sessionKey'],
            \is_string($data['salesChannelId'] ?? null) ? $data['salesChannelId'] : null,
            \is_string($data['idToken'] ?? null) ? $data['idToken'] : null,
            \is_int($data['createdAt'] ?? null) ? $data['createdAt'] : 0,
        );
    }
}
