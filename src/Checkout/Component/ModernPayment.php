<?php

namespace SilverShop\Checkout\Component;

use SilverShop\Checkout\Checkout;
use SilverShop\Model\Order;
use SilverShop\Payment\GatewayRegistry;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\ValidationException;
use SilverStripe\ORM\ValidationResult;

/**
 * Checkout component for modern (non-Omnipay) payment gateways.
 * Delegates form fields and requirements to the selected ModernPaymentGateway implementation.
 */
class ModernPayment extends CheckoutComponent
{
    public function getFormFields(Order $order): FieldList
    {
        $gateway = $this->resolveGateway($order);
        if (!$gateway) {
            return FieldList::create();
        }

        $gateway->getRequirements($order);
        return $gateway->getFormFields($order);
    }

    public function getRequiredFields(Order $order): array
    {
        return [];
    }

    public function validateData(Order $order, array $data): bool
    {
        $gateway = $this->resolveGateway($order);
        if (!$gateway) {
            $result = ValidationResult::create();
            $result->addError('No modern payment gateway configured');
            throw ValidationException::create($result);
        }

        return true;
    }

    public function getData(Order $order): array
    {
        return [];
    }

    public function setData(Order $order, array $data): Order
    {
        return $order;
    }

    public function providesPaymentData(): bool
    {
        return true;
    }

    private function resolveGateway(Order $order)
    {
        $method = Checkout::get($order)->getSelectedPaymentMethod(false);
        if (!$method) {
            return null;
        }

        $registry = GatewayRegistry::singleton();
        if (!$registry->isModernGateway($method)) {
            return null;
        }

        return $registry->getGateway($method);
    }
}
