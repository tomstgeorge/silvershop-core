<?php

namespace SilverShop\Payment;

class PaymentResult
{
    public function __construct(
        private bool $success,
        private string $transactionId = '',
        private string $errorMessage = '',
        private ?string $redirectUrl = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }
}
