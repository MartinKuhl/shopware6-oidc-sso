<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Storefront\Service;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Event\CustomerBeforeLoginEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Checkout\Customer\CustomerException;
use Shopware\Core\Checkout\Customer\Exception\BadCredentialsException;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractLoginRoute;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
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

    public function login(RequestDataBag $data, SalesChannelContext $context): ContextTokenResponse
    {
        $email = (string) $data->get('email');

        $this->eventDispatcher->dispatch(new CustomerBeforeLoginEvent($context, $email));

        $customer = $this->getCustomerByEmail($email, $context);

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

    private function getCustomerByEmail(string $email, SalesChannelContext $context): CustomerEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('email', $email));
        $criteria->addFilter(new EqualsFilter('guest', false));

        foreach ($this->customerRepository->search($criteria, $context->getContext())->getEntities() as $customer) {
            \assert($customer instanceof CustomerEntity);

            $boundSalesChannelId = $customer->getBoundSalesChannelId();

            if ($boundSalesChannelId === null || $boundSalesChannelId === $context->getSalesChannelId()) {
                return $customer;
            }
        }

        throw new BadCredentialsException();
    }
}
