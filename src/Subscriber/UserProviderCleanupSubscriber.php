<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Subscriber;

use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\UserProviderBindingService;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\System\User\UserEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * sw6oidc_user_provider.user_id has no FK to user/customer (the binding is
 * polymorphic), so nothing cascades when the account itself is deleted -
 * remove the orphaned binding here instead, mirroring the Magento reference
 * module's admin-user/customer delete observers.
 */
class UserProviderCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly UserProviderBindingService $bindingService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserEvents::USER_DELETED_EVENT => 'onUserDeleted',
            CustomerEvents::CUSTOMER_DELETED_EVENT => 'onCustomerDeleted',
        ];
    }

    public function onUserDeleted(EntityDeletedEvent $event): void
    {
        $this->unbindAll(Sw6OidcUserProviderEntity::USER_TYPE_ADMIN, $event);
    }

    public function onCustomerDeleted(EntityDeletedEvent $event): void
    {
        $this->unbindAll(Sw6OidcUserProviderEntity::USER_TYPE_CUSTOMER, $event);
    }

    private function unbindAll(string $userType, EntityDeletedEvent $event): void
    {
        $event->getContext()->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($userType, $event): void {
            foreach ($event->getIds() as $id) {
                if (\is_string($id)) {
                    $this->bindingService->unbind($userType, $id, $context);
                }
            }
        });
    }
}
