<?php

namespace SilverShop\Tests\Payment;

use SilverShop\Payment\PaymentResult;
use SilverStripe\Dev\SapphireTest;

class PaymentResultTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testSuccessResult(): void
    {
        $result = new PaymentResult(success: true, transactionId: 'txn_123');
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('txn_123', $result->getTransactionId());
        $this->assertEquals('', $result->getErrorMessage());
        $this->assertNull($result->getRedirectUrl());
    }

    public function testFailureResult(): void
    {
        $result = new PaymentResult(success: false, errorMessage: 'Card declined');
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('', $result->getTransactionId());
        $this->assertEquals('Card declined', $result->getErrorMessage());
    }

    public function testRedirectResult(): void
    {
        $result = new PaymentResult(
            success: false,
            redirectUrl: 'https://stripe.com/3ds-challenge'
        );
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('https://stripe.com/3ds-challenge', $result->getRedirectUrl());
    }
}
