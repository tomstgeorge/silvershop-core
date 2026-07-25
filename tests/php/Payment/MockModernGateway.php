<?php

namespace SilverShop\Tests\Payment;

use SilverShop\Model\Order;
use SilverShop\Payment\ModernPaymentGateway;
use SilverShop\Payment\PaymentResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;

class MockModernGateway implements ModernPaymentGateway
{
    private bool $shouldSucceed = true;
    private string $errorMessage = 'Payment failed';

    public function setShouldSucceed(bool $succeed): void
    {
        $this->shouldSucceed = $succeed;
    }

    public function setErrorMessage(string $message): void
    {
        $this->errorMessage = $message;
    }

    public function getIdentifier(): string
    {
        return 'mock_modern';
    }

    public function getDisplayName(): string
    {
        return 'Mock Modern Gateway';
    }

    public function getFormFields(Order $order): FieldList
    {
        return FieldList::create(
            LiteralField::create('mock_element', '<div id="mock-payment-element"></div>'),
            HiddenField::create('payment_token', 'Payment Token')
        );
    }

    public function getRequirements(Order $order): void
    {
        // No-op in tests
    }

    public function processPayment(array $data, Order $order): PaymentResult
    {
        if ($this->shouldSucceed) {
            return new PaymentResult(
                success: true,
                transactionId: 'mock_txn_' . $order->ID . '_' . time()
            );
        }

        return new PaymentResult(
            success: false,
            errorMessage: $this->errorMessage
        );
    }
}
