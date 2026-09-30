<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Config;

use Doctrine\DBAL\Connection;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcEncryptor;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupEntity;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Field\AssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StorageAware;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Provider configuration export/import (sw6oidc:config:export / :import).
 *
 * Export format (version 1): {version, providers: [{...provider fields,
 * clientSecret?, defaultAclRole: {id, name}|null, defaultCustomerGroup:
 * {id, name}|null, attributeMappings: [...], roleMappings: [...],
 * accessControlRules: [...]}]}. Access-control rules travel with the
 * provider on purpose: an import that silently dropped them would open the
 * login up on the target installation.
 * Instance-specific bookkeeping (created/updated, last live test) is left out.
 *
 * ACL roles and customer groups are exported as {id, name} because their ids
 * differ between installations; import resolves them by id first, then by a
 * unique name. The client secret is omitted by default — an encrypted
 * envelope only decrypts on an installation with the same APP_SECRET.
 *
 * Import always writes through the DAL repository, so the encrypted-field
 * serializer and Sw6OidcProviderWriteGuardSubscriber (SSRF, lockout guard)
 * apply exactly as for an Admin save.
 */
class OidcConfigTransfer
{
    public const FORMAT_VERSION = 1;

    public const SECRET_OMIT = 'omit';
    public const SECRET_ENCRYPTED = 'encrypted';
    public const SECRET_PLAINTEXT = 'plaintext';

    /** Provider properties never exported/imported as plain fields. */
    private const EXCLUDED_PROPERTIES = [
        'clientSecret',
        'defaultAclRoleId',
        'defaultCustomerGroupId',
        'lastTestStatus',
        'lastTestAt',
        'lastTestClaims',
        // Encrypted like the client secret; usually embeds a token.
        'healthAlertWebhookUrl',
        // Runtime state of the health-alert task, instance-specific.
        'healthAlertConsecutiveFailures',
        'healthAlertLastStatus',
        'healthAlertLastCheckedAt',
        'healthAlertFirstFailureAt',
        'healthAlertLastNotifiedAt',
        'createdAt',
        'updatedAt',
    ];

    public function __construct(
        private readonly EntityRepository $providerRepository,
        private readonly EntityRepository $attributeMappingRepository,
        private readonly EntityRepository $roleMappingRepository,
        private readonly EntityRepository $accessControlRuleRepository,
        private readonly EntityRepository $aclRoleRepository,
        private readonly EntityRepository $customerGroupRepository,
        private readonly Sw6OidcProviderDefinition $providerDefinition,
        private readonly Connection $connection,
        private readonly Sw6OidcEncryptor $encryptor,
    ) {
    }

    /**
     * @return array{version: int, providers: list<array<string, mixed>>}
     */
    public function export(?string $providerId, string $secretMode, Context $context): array
    {
        $criteria = $providerId !== null ? new Criteria([$providerId]) : new Criteria();
        $criteria->addAssociation('attributeMappings');
        $criteria->addAssociation('roleMappings.aclRole');
        $criteria->addAssociation('roleMappings.customerGroup');
        $criteria->addAssociation('accessControlRules');
        $criteria->addAssociation('defaultAclRole');
        $criteria->addAssociation('defaultCustomerGroup');

        $providers = [];

        foreach ($this->providerRepository->search($criteria, $context)->getEntities() as $provider) {
            \assert($provider instanceof Sw6OidcProviderEntity);
            $providers[] = $this->exportProvider($provider, $secretMode);
        }

        return ['version' => self::FORMAT_VERSION, 'providers' => $providers];
    }

    /**
     * @param array<mixed> $data
     */
    public function import(array $data, bool $overwrite, bool $skipUnresolved, bool $dryRun, Context $context): ImportResult
    {
        if (($data['version'] ?? null) !== self::FORMAT_VERSION || !\is_array($data['providers'] ?? null)) {
            throw new \InvalidArgumentException(sprintf('Unsupported export file: expected {"version": %d, "providers": [...]}.', self::FORMAT_VERSION));
        }

        $result = new ImportResult();

        if ($dryRun) {
            $this->connection->beginTransaction();
        }

        try {
            foreach ($data['providers'] as $index => $provider) {
                $label = \is_array($provider) ? (string) ($provider['appName'] ?? $provider['id'] ?? '#' . $index) : '#' . $index;

                try {
                    if (!\is_array($provider)) {
                        throw new \InvalidArgumentException('Provider entry is not an object.');
                    }

                    $this->importProvider($provider, $overwrite, $skipUnresolved, $label, $result, $context);
                } catch (\Throwable $exception) {
                    $result->failed[$label] = $exception->getMessage();
                }
            }
        } finally {
            if ($dryRun) {
                $this->connection->rollBack();
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportProvider(Sw6OidcProviderEntity $provider, string $secretMode): array
    {
        $out = [];

        foreach ($this->transferableProperties() as $property) {
            $out[$property] = $provider->get($property);
        }

        $secret = match ($secretMode) {
            self::SECRET_OMIT => null,
            self::SECRET_PLAINTEXT => $provider->getClientSecret(),
            self::SECRET_ENCRYPTED => $this->storedSecretEnvelope($provider->getId()),
            default => throw new \InvalidArgumentException(sprintf('Unknown secret mode "%s".', $secretMode)),
        };

        if ($secret !== null) {
            $out['clientSecret'] = $secret;
        }

        $out['defaultAclRole'] = $provider->getDefaultAclRole() instanceof AclRoleEntity
            ? ['id' => $provider->getDefaultAclRole()->getId(), 'name' => $provider->getDefaultAclRole()->getName()]
            : null;
        $out['defaultCustomerGroup'] = $provider->getDefaultCustomerGroup() instanceof CustomerGroupEntity
            ? ['id' => $provider->getDefaultCustomerGroup()->getId(), 'name' => $provider->getDefaultCustomerGroup()->getTranslation('name')]
            : null;

        $out['attributeMappings'] = [];

        foreach ($provider->getAttributeMappings() ?? [] as $mapping) {
            $out['attributeMappings'][] = [
                'attributeType' => $mapping->getAttributeType(),
                'attributeName' => $mapping->getAttributeName(),
                'transformFunction' => $mapping->getTransformFunction(),
                'transformParams' => $mapping->getTransformParams(),
            ];
        }

        $out['roleMappings'] = [];

        foreach ($provider->getRoleMappings() ?? [] as $mapping) {
            $out['roleMappings'][] = [
                'mappingType' => $mapping->getMappingType(),
                'oidcGroup' => $mapping->getOidcGroup(),
                'sortOrder' => $mapping->getSortOrder(),
                'aclRole' => $mapping->getAclRole() instanceof AclRoleEntity ? ['id' => $mapping->getAclRole()->getId(), 'name' => $mapping->getAclRole()->getName()] : null,
                'customerGroup' => $mapping->getCustomerGroup() instanceof CustomerGroupEntity
                    ? ['id' => $mapping->getCustomerGroup()->getId(), 'name' => $mapping->getCustomerGroup()->getTranslation('name')]
                    : null,
            ];
        }

        $out['accessControlRules'] = [];

        foreach ($provider->getAccessControlRules() ?? [] as $rule) {
            $out['accessControlRules'][] = [
                'claimKey' => $rule->getClaimKey(),
                'operator' => $rule->getOperator(),
                'value' => $rule->getValue(),
                'errorMessage' => $rule->getErrorMessage(),
                'sortOrder' => $rule->getSortOrder(),
            ];
        }

        return $out;
    }

    /**
     * The stored column value as-is (already an envelope), or a legacy
     * plaintext row encrypted on the fly — never the plaintext.
     */
    private function storedSecretEnvelope(string $providerId): ?string
    {
        $stored = $this->connection->fetchOne(
            'SELECT `client_secret` FROM `sw6oidc_provider` WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes($providerId)],
        );

        if (!\is_string($stored) || $stored === '') {
            return null;
        }

        return $this->encryptor->isEncrypted($stored) ? $stored : $this->encryptor->encrypt($stored);
    }

    /**
     * @param array<string, mixed> $provider
     */
    private function importProvider(array $provider, bool $overwrite, bool $skipUnresolved, string $label, ImportResult $result, Context $context): void
    {
        $id = $provider['id'] ?? null;

        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new \InvalidArgumentException('Provider entry has no valid "id".');
        }

        $exists = $this->providerRepository->searchIds(new Criteria([$id]), $context)->getTotal() > 0;

        if ($exists && !$overwrite) {
            $result->skipped[] = $label;

            return;
        }

        $payload = ['id' => $id];

        foreach ($this->transferableProperties() as $property) {
            if (\array_key_exists($property, $provider)) {
                $payload[$property] = $provider[$property];
            }
        }

        $secret = $provider['clientSecret'] ?? null;

        if (\is_string($secret) && $secret !== '') {
            $payload['clientSecret'] = $secret;
        } elseif (!$exists && !($provider['publicClient'] ?? false)) {
            throw new \InvalidArgumentException('New confidential provider without "clientSecret": export it with --keep-encrypted (same APP_SECRET) or --plaintext.');
        }

        $payload['defaultAclRoleId'] = $this->resolveReference($provider['defaultAclRole'] ?? null, 'acl_role', $skipUnresolved, $result, $label, $context);
        $payload['defaultCustomerGroupId'] = $this->resolveReference($provider['defaultCustomerGroup'] ?? null, 'customer_group', $skipUnresolved, $result, $label, $context);

        $payload['attributeMappings'] = [];

        foreach ($provider['attributeMappings'] ?? [] as $mapping) {
            if (!\is_array($mapping)) {
                continue;
            }

            $payload['attributeMappings'][] = [
                'id' => Uuid::randomHex(),
                'attributeType' => $mapping['attributeType'] ?? null,
                'attributeName' => $mapping['attributeName'] ?? null,
                'transformFunction' => $mapping['transformFunction'] ?? null,
                'transformParams' => $mapping['transformParams'] ?? null,
            ];
        }

        $payload['roleMappings'] = [];

        foreach ($provider['roleMappings'] ?? [] as $mapping) {
            if (!\is_array($mapping)) {
                continue;
            }

            $aclRoleId = $this->resolveReference($mapping['aclRole'] ?? null, 'acl_role', $skipUnresolved, $result, $label, $context);
            $customerGroupId = $this->resolveReference($mapping['customerGroup'] ?? null, 'customer_group', $skipUnresolved, $result, $label, $context);

            // A dropped (unresolved) reference makes an admin_role/customer_group row meaningless.
            if (($mapping['aclRole'] ?? null) !== null && $aclRoleId === null || (($mapping['customerGroup'] ?? null) !== null && $customerGroupId === null)) {
                continue;
            }

            $payload['roleMappings'][] = [
                'id' => Uuid::randomHex(),
                'mappingType' => $mapping['mappingType'] ?? null,
                'oidcGroup' => $mapping['oidcGroup'] ?? null,
                'sortOrder' => $mapping['sortOrder'] ?? 0,
                'aclRoleId' => $aclRoleId,
                'customerGroupId' => $customerGroupId,
            ];
        }

        $payload['accessControlRules'] = [];

        foreach ($provider['accessControlRules'] ?? [] as $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            $payload['accessControlRules'][] = [
                'id' => Uuid::randomHex(),
                'claimKey' => $rule['claimKey'] ?? null,
                'operator' => $rule['operator'] ?? null,
                'value' => $rule['value'] ?? null,
                'errorMessage' => $rule['errorMessage'] ?? null,
                'sortOrder' => $rule['sortOrder'] ?? 0,
            ];
        }

        // One transaction per provider: a rejected upsert (SSRF, lockout
        // guard, validation) must not leave its mappings deleted.
        $this->connection->transactional(function () use ($exists, $id, $payload, $context): void {
            if ($exists) {
                $this->deleteChildren($this->attributeMappingRepository, $id, $context);
                $this->deleteChildren($this->roleMappingRepository, $id, $context);
                $this->deleteChildren($this->accessControlRuleRepository, $id, $context);
            }

            $this->providerRepository->upsert([$payload], $context);
        });

        if ($exists) {
            $result->updated[] = $label;
        } else {
            $result->created[] = $label;
        }
    }

    /**
     * @param mixed $reference {id, name} from the export
     */
    private function resolveReference(mixed $reference, string $entity, bool $skipUnresolved, ImportResult $result, string $label, Context $context): ?string
    {
        if (!\is_array($reference)) {
            return null;
        }

        $repository = $entity === 'acl_role' ? $this->aclRoleRepository : $this->customerGroupRepository;
        $id = $reference['id'] ?? null;

        if (\is_string($id) && Uuid::isValid($id) && $repository->searchIds(new Criteria([$id]), $context)->getTotal() > 0) {
            return $id;
        }

        $name = $reference['name'] ?? null;

        if (\is_string($name) && $name !== '') {
            $criteria = (new Criteria())->addFilter(new EqualsFilter('name', $name))->setLimit(2);
            $ids = $repository->searchIds($criteria, $context)->getIds();

            if (\count($ids) === 1) {
                $first = current($ids);

                if (\is_string($first)) {
                    return $first;
                }
            }
        }

        $message = sprintf('%s "%s" could not be resolved on this installation (no matching id, and no unique name match).', $entity, \is_string($name) ? $name : (string) $id);

        if (!$skipUnresolved) {
            throw new \InvalidArgumentException($message . ' Use --skip-unresolved to drop such references.');
        }

        $result->warnings[$label][] = $message . ' Dropped.';

        return null;
    }

    private function deleteChildren(EntityRepository $repository, string $providerId, Context $context): void
    {
        $ids = $repository->searchIds((new Criteria())->addFilter(new EqualsFilter('providerId', $providerId)), $context)->getIds();

        if ($ids !== []) {
            $repository->delete(array_map(static fn ($id): array => ['id' => $id], $ids), $context);
        }
    }

    /**
     * @return list<string> plain provider properties included in export/import
     */
    private function transferableProperties(): array
    {
        $properties = [];

        foreach ($this->providerDefinition->getFields() as $field) {
            if ($field instanceof AssociationField || !$field instanceof StorageAware) {
                continue;
            }

            if (!\in_array($field->getPropertyName(), self::EXCLUDED_PROPERTIES, true)) {
                $properties[] = $field->getPropertyName();
            }
        }

        return $properties;
    }
}
