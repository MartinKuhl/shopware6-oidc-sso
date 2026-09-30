<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Security;

use MartinKuhl\Sw6Oidc\Core\Content\AccessControlRule\Sw6OidcAccessControlRuleCollection;
use MartinKuhl\Sw6Oidc\Core\Content\AccessControlRule\Sw6OidcAccessControlRuleDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\AccessControlRule\Sw6OidcAccessControlRuleEntity;
use MartinKuhl\Sw6Oidc\Service\Security\Exception\AccessControlDeniedException;
use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcAccessControlEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(Sw6OidcAccessControlEvaluator::class)]
#[CoversClass(AccessControlDeniedException::class)]
final class Sw6OidcAccessControlEvaluatorTest extends TestCase
{
    /**
     * Flattened the way ClaimsNormalizer::flatten() does it.
     */
    private const CLAIMS = [
        'email' => 'Jane.Doe@Example.com',
        'email_verified' => true,
        'department' => 'Engineering',
        'level' => 3,
        'groups.0' => 'Staff',
        'groups.1' => 'Developers',
        'single.0' => 'only',
        'roles.Admins.orgId' => '42',
        'roles.Support.orgId' => '42',
        'realm_access.roles.0' => 'shop-admin',
        'active' => '1',
    ];

    /**
     * @return iterable<string, array{string, string, ?string, bool}>
     */
    public static function ruleCases(): iterable
    {
        yield 'eq scalar, case-insensitive' => ['department', 'eq', 'engineering', true];
        yield 'eq scalar mismatch' => ['department', 'eq', 'Sales', false];
        yield 'eq int as string' => ['level', 'eq', '3', true];
        yield 'eq bool true vs "true"' => ['email_verified', 'eq', 'true', true];
        yield 'eq bool true vs "1"' => ['email_verified', 'eq', '1', true];
        yield 'eq bool true vs "false"' => ['email_verified', 'eq', 'false', false];
        yield 'eq "1" vs "true"' => ['active', 'eq', 'TRUE', true];
        yield 'eq missing claim' => ['missing', 'eq', 'x', false];
        yield 'eq one-element list' => ['single', 'eq', 'only', true];
        yield 'eq multi-element list never equals' => ['groups', 'eq', 'staff', false];
        yield 'eq with null value' => ['department', 'eq', null, false];
        yield 'neq scalar' => ['department', 'neq', 'Sales', true];
        yield 'neq scalar equal' => ['department', 'neq', 'ENGINEERING', false];
        yield 'neq missing claim passes' => ['missing', 'neq', 'x', true];
        yield 'contains list member' => ['groups', 'contains', 'staff', true];
        yield 'contains list, no partial member match' => ['groups', 'contains', 'Dev', false];
        yield 'contains nested list' => ['realm_access.roles', 'contains', 'Shop-Admin', true];
        yield 'contains object-key members (Zitadel)' => ['roles', 'contains', 'admins', true];
        yield 'contains scalar substring' => ['email', 'contains', '@example.com', true];
        yield 'contains scalar substring mismatch' => ['email', 'contains', '@other.org', false];
        yield 'contains empty value never matches' => ['email', 'contains', '', false];
        yield 'contains missing claim' => ['missing', 'contains', 'x', false];
        yield 'not_contains list member' => ['groups', 'not_contains', 'Staff', false];
        yield 'not_contains absent member' => ['groups', 'not_contains', 'Banned', true];
        yield 'not_contains missing claim passes' => ['missing', 'not_contains', 'x', true];
        yield 'exists scalar' => ['email', 'exists', null, true];
        yield 'exists list parent' => ['groups', 'exists', null, true];
        yield 'exists nested object parent' => ['realm_access', 'exists', null, true];
        yield 'exists missing' => ['missing', 'exists', null, false];
        yield 'exists does not match a mere key prefix' => ['group', 'exists', null, false];
        yield 'not_exists missing' => ['missing', 'not_exists', null, true];
        yield 'not_exists present' => ['groups', 'not_exists', null, false];
        yield 'unknown operator fails closed' => ['email', 'regex', '.*', false];
    }

    #[DataProvider('ruleCases')]
    public function testRuleMatching(string $claimKey, string $operator, ?string $value, bool $expected): void
    {
        self::assertSame($expected, $this->evaluator([])->matches($this->rule($claimKey, $operator, $value), self::CLAIMS));
    }

    public function testNoRulesAllowsEveryone(): void
    {
        $this->evaluator([])->evaluate('provider', self::CLAIMS, Context::createDefaultContext());

        $this->addToAssertionCount(1);
    }

    public function testAllPassingRulesAllowLogin(): void
    {
        $this->evaluator([
            $this->rule('groups', 'contains', 'staff'),
            $this->rule('email_verified', 'eq', 'true'),
        ])->evaluate('provider', self::CLAIMS, Context::createDefaultContext());

        $this->addToAssertionCount(1);
    }

    public function testFirstFailingRuleWinsWithItsMessage(): void
    {
        $evaluator = $this->evaluator([
            $this->rule('groups', 'contains', 'staff', 'never shown'),
            $this->rule('department', 'eq', 'Sales', '<b>Sales</b> only.'),
            $this->rule('missing', 'exists', null, 'second failure, never reached'),
        ]);

        try {
            $evaluator->evaluate('provider', self::CLAIMS, Context::createDefaultContext());
            self::fail('Expected AccessControlDeniedException');
        } catch (AccessControlDeniedException $exception) {
            self::assertSame('department', $exception->claimKey);
            self::assertSame('Sales only.', $exception->getDisplayMessage(), 'tags are stripped');
        }
    }

    public function testDeniedWithoutMessageHasNoDisplayMessage(): void
    {
        $this->expectException(AccessControlDeniedException::class);

        try {
            $this->evaluator([$this->rule('missing', 'exists', null, '   ')])->evaluate('provider', self::CLAIMS, Context::createDefaultContext());
        } catch (AccessControlDeniedException $exception) {
            self::assertNull($exception->getDisplayMessage());

            throw $exception;
        }
    }

    public function testRulesAreLoadedForTheProviderInSortOrder(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('search')->willReturnCallback(function (Criteria $criteria, Context $context): EntitySearchResult {
            self::assertSame('providerId', $criteria->getFilters()[0]->getField());
            self::assertSame('provider-7', $criteria->getFilters()[0]->getValue());
            $sorting = $criteria->getSorting()[0];
            self::assertSame('sortOrder', $sorting->getField());
            self::assertSame(FieldSorting::ASCENDING, $sorting->getDirection());

            return $this->searchResult([], $criteria, $context);
        });

        (new Sw6OidcAccessControlEvaluator($repository, new NullLogger()))->evaluate('provider-7', [], Context::createDefaultContext());
    }

    /**
     * @param list<Sw6OidcAccessControlRuleEntity> $rules
     */
    private function evaluator(array $rules): Sw6OidcAccessControlEvaluator
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('search')->willReturnCallback(fn (Criteria $criteria, Context $context): EntitySearchResult => $this->searchResult($rules, $criteria, $context));

        return new Sw6OidcAccessControlEvaluator($repository, new NullLogger());
    }

    /**
     * @param list<Sw6OidcAccessControlRuleEntity> $rules
     */
    private function searchResult(array $rules, Criteria $criteria, Context $context): EntitySearchResult
    {
        return new EntitySearchResult(
            Sw6OidcAccessControlRuleDefinition::ENTITY_NAME,
            \count($rules),
            new Sw6OidcAccessControlRuleCollection($rules),
            null,
            $criteria,
            $context,
        );
    }

    private function rule(string $claimKey, string $operator, ?string $value, ?string $message = null): Sw6OidcAccessControlRuleEntity
    {
        $rule = new Sw6OidcAccessControlRuleEntity();
        $rule->assign([
            'id' => Uuid::randomHex(),
            'providerId' => 'provider',
            'claimKey' => $claimKey,
            'operator' => $operator,
            'value' => $value,
            'errorMessage' => $message,
        ]);

        return $rule;
    }
}
