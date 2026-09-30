<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Storefront\Service;

use MartinKuhl\Sw6Oidc\Service\Provisioning\CustomerSalesChannelBinding;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Event\CustomerBeforeLoginEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Checkout\Customer\CustomerException;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractLoginRoute;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\CartRestorer;
use Shopware\Core\System\SalesChannel\ContextTokenResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * A standalone, passwordless login route for already-OIDC-verified customers —
 * NOT a decorator of Shopware's core `AbstractLoginRoute` service (that alias
 * must keep doing real password checks for everyone else). Registered and
 * injected under its own concrete class name, exactly like the reference
 * plugin's own equivalent — trusts the caller because CustomerProvisioningService
 * has already resolved/created the customer for this exact request via a
 * verified OIDC id_token before this is ever called.
 */
class OidcCustomerLoginRoute extends AbstractLoginRoute
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityRepository $customerRepository,
        private readonly CartRestorer $restorer,
    ) {
    }

    public function getDecorated(): AbstractLoginRoute
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * AbstractLoginRoute contract: expects the already-verified `customerId`
     * in the data bag. There is deliberately no lookup by email — several
     * customers may share one (sales-channel-bound duplicates), and the
     * caller has already resolved exactly which one authenticated.
     */
    public function login(RequestDataBag $data, SalesChannelContext $context): ContextTokenResponse
    {
        $customerId = $data->get('customerId');

        if (!\is_string($customerId) || $customerId === '') {
            throw CustomerException::badCredentials();
        }

        return $this->loginByCustomerId($customerId, $context);
    }

    public function loginByCustomerId(string $customerId, SalesChannelContext $context): ContextTokenResponse
    {
        $customer = $this->customerRepository->search(new Criteria([$customerId]), $context->getContext())->first();

        if (
            !$customer instanceof CustomerEntity
            || $customer->getGuest()
            || !CustomerSalesChannelBinding::allows($customer, $context->getSalesChannelId())
        ) {
            throw CustomerException::badCredentials();
        }

        $this->eventDispatcher->dispatch(new CustomerBeforeLoginEvent($context, $customer->getEmail()));

        if (!$customer->getActive()) {
            throw CustomerException::inactive($customer->getId());
        }

        $restoredContext = $this->restorer->restore($customer->getId(), $context);
        $newToken = $restoredContext->getToken();

        $this->customerRepository->update([[
            'id' => $customer->getId(),
            'lastLogin' => new \DateTimeImmutable(),
        ]], $restoredContext->getContext());

        $this->eventDispatcher->dispatch(new CustomerLoginEvent($restoredContext, $customer, $newToken));

        return new ContextTokenResponse($newToken);
    }
}
