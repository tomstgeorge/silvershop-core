# Modern Payment Gateway Implementation Plan

## Goal

Add support for client-side payment flows (Stripe Elements, PayPal Smart Buttons, Apple/Google Pay) to SilverShop core without breaking existing Omnipay-based stores.

## Guiding Principles

- **Zero breaking changes** — existing Omnipay gateways continue to work unmodified.
- **Minimal core disruption** — new abstractions and a conditional branch, not a rewrite.
- **Future-proof** — enables standalone modules (`silvershop-stripe-elements`, `silvershop-paypal-smart-buttons`) without further core changes.

---

## Phase 1: Gateway Registry Abstraction

### Problem

`GatewayInfo::getSupportedGateways()` is called directly in 10+ files. This hard-couples the checkout to Omnipay's gateway discovery.

### Solution

Introduce `SilverShop\Payment\GatewayRegistry` — a single source of truth for available gateways.

### Files to Create

- `src/Payment/GatewayRegistry.php`

### Behaviour

```php
namespace SilverShop\Payment;

class GatewayRegistry
{
    use Injectable, Configurable;

    // Modern gateways registered via YAML or PHP
    private static array $modern_gateways = [];

    public function getAvailableGateways(): array
    {
        // Merge Omnipay gateways (backward compat) with modern gateways
        $omnipay = GatewayInfo::getSupportedGateways();
        $modern = $this->getModernGateways();
        return array_merge($omnipay, $modern);
    }

    public function getModernGateways(): array; // keyed by identifier => display name
    public function getGateway(string $identifier): ?ModernPaymentGateway;
    public function isModernGateway(string $identifier): bool;
    public function isOffsite(string $identifier): bool; // delegates to GatewayInfo for legacy
    public function isManual(string $identifier): bool;  // delegates to GatewayInfo for legacy
}
```

### YAML Registration Example

```yaml
SilverShop\Payment\GatewayRegistry:
  modern_gateways:
    stripe_elements: 'Credit Card (Stripe)'
    paypal_smart: 'PayPal'
```

### Migration Path for Existing Code

Replace direct `GatewayInfo::getSupportedGateways()` calls with `GatewayRegistry::singleton()->getAvailableGateways()` in:

| File | Call Count |
|------|-----------|
| `src/Checkout/Component/Payment.php` | 3 |
| `src/Checkout/Checkout.php` | 2 |
| `src/Checkout/CheckoutFieldFactory.php` | 2 |
| `src/Checkout/Step/PaymentMethod.php` | 1 |
| `src/Checkout/SinglePageCheckoutComponentConfig.php` | 1 |
| `src/Checkout/OrderProcessor.php` | 1 |
| `src/Forms/OrderActionsForm.php` | 2 |
| `src/Forms/PaymentForm.php` | 2 |
| `src/Forms/OrderActionsFormValidator.php` | 1 |

---

## Phase 2: Modern Payment Gateway Interface

### Problem

Omnipay expects server-side form submission. Modern gateways need to inject custom UI (iframes, JS SDKs) and handle payment confirmation client-side before the form submits.

### Solution

Introduce `SilverShop\Payment\ModernPaymentGateway` interface.

### Files to Create

- `src/Payment/ModernPaymentGateway.php`
- `src/Payment/PaymentResult.php`

### Interface

```php
namespace SilverShop\Payment;

use SilverShop\Model\Order;
use SilverStripe\Forms\FieldList;
use SilverStripe\View\Requirements;

interface ModernPaymentGateway
{
    /**
     * Unique identifier matching the YAML key (e.g. 'stripe_elements').
     */
    public function getIdentifier(): string;

    /**
     * Human-readable name for display in checkout.
     */
    public function getDisplayName(): string;

    /**
     * Form fields to render in the checkout (e.g. a container div for Stripe Elements).
     */
    public function getFormFields(Order $order): FieldList;

    /**
     * Called during page render — inject JS/CSS requirements.
     * Implementations should call Requirements::javascript() etc.
     */
    public function getRequirements(Order $order): void;

    /**
     * Server-side processing after client-side payment confirmation.
     * Receives the submitted form data (which includes the payment intent/token
     * from the client-side JS) and the order.
     *
     * Must return a PaymentResult indicating success/failure.
     */
    public function processPayment(array $data, Order $order): PaymentResult;
}
```

### PaymentResult Value Object

```php
namespace SilverShop\Payment;

class PaymentResult
{
    public function __construct(
        private bool $success,
        private string $transactionId = '',
        private string $errorMessage = '',
        private ?string $redirectUrl = null,
    ) {}

    public function isSuccess(): bool;
    public function getTransactionId(): string;
    public function getErrorMessage(): string;
    public function getRedirectUrl(): ?string; // For 3DS or similar redirects
}
```

---

## Phase 3: Checkout Component for Modern Gateways

### Problem

`OnsitePayment` component is tightly coupled to Omnipay's `GatewayFieldsFactory` for card fields. Modern gateways provide their own UI.

### Solution

Create `src/Checkout/Component/ModernPayment.php` — a checkout component that delegates to the selected `ModernPaymentGateway`.

### Behaviour

```php
namespace SilverShop\Checkout\Component;

class ModernPayment extends CheckoutComponent
{
    public function getFormFields(Order $order): FieldList
    {
        $gateway = $this->resolveGateway($order);
        if (!$gateway) return FieldList::create();

        $gateway->getRequirements($order);
        return $gateway->getFormFields($order);
    }

    public function providesPaymentData(): bool
    {
        return true;
    }

    // validateData, getData, setData delegate appropriately
}
```

### Integration with CheckoutComponentConfig

`SinglePageCheckoutComponentConfig` and the stepped checkout will conditionally add `ModernPayment` instead of `OnsitePayment` when the selected gateway is modern:

```php
// In SinglePageCheckoutComponentConfig or equivalent logic:
$registry = GatewayRegistry::singleton();
$selectedGateway = Checkout::get($order)->getSelectedPaymentMethod(false);

if ($registry->isModernGateway($selectedGateway)) {
    $config->addComponent(ModernPayment::create());
} else {
    $config->addComponent(OnsitePayment::create());
}
```

---

## Phase 4: Payment Submission Fork

### Problem

`PaymentForm::submitpayment()` feeds everything into Omnipay's `ServiceFactory`. Modern gateways bypass this entirely.

### Solution

Add a conditional branch in `PaymentForm::submitpayment()` and `OrderProcessor`.

### Changes to `src/Forms/PaymentForm.php`

In `submitpayment()`, after order calculation and before Omnipay processing:

```php
$registry = GatewayRegistry::singleton();

if ($registry->isModernGateway($gateway)) {
    $modernGateway = $registry->getGateway($gateway);
    $result = $modernGateway->processPayment($data, $order);

    if ($result->getRedirectUrl()) {
        return $this->controller->redirect($result->getRedirectUrl());
    }

    if ($result->isSuccess()) {
        // Create Payment record for OrderProcessor compatibility (Phase 5)
        $this->orderProcessor->recordModernPayment($gateway, $result, $order);
        return $this->controller->redirect($this->getSuccessLink());
    }

    $form->sessionMessage($result->getErrorMessage(), 'bad');
    return $this->controller->redirectBack();
}

// ... existing Omnipay flow unchanged ...
```

### Changes to `src/Forms/OrderActionsForm.php`

Same pattern in `dopayment()` — check if modern gateway, delegate to `processPayment()`.

---

## Phase 5: Payment Record Compatibility

### Problem

`OrderProcessor::completePayment()`, `PaymentExtension`, and the admin UI all expect `\SilverStripe\Omnipay\Model\Payment` records.

### Solution

When a modern gateway succeeds, we create a Payment record manually with status `Captured` and attach the transaction reference. This satisfies all downstream code without invoking Omnipay's HTTP layer.

### New Method on `OrderProcessor`

```php
public function recordModernPayment(string $gateway, PaymentResult $result, Order $order): void
{
    $payment = \SilverStripe\Omnipay\Model\Payment::create()->init(
        $gateway,
        $order->TotalOutstanding(true),
        ShopConfigExtension::config()->base_currency
    );
    $payment->Status = 'Captured';
    $payment->TransactionReference = $result->getTransactionId();
    $order->Payments()->add($payment);
    $payment->write();

    // Trigger the same completion flow as Omnipay
    $this->completePayment();
}
```

This ensures:
- Order status transitions to `Paid`
- Confirmation emails are sent
- `onPayment` extension hooks fire
- Admin UI shows the payment record

---

## Phase 6: Webhook Support (Optional but Recommended)

### Problem

Modern gateways (especially Stripe) rely on webhooks for payment confirmation in async flows (3D Secure, delayed capture).

### Solution

Provide a generic webhook controller that routes to the appropriate gateway.

### Files to Create

- `src/Payment/WebhookController.php`

### Behaviour

```php
namespace SilverShop\Payment;

class WebhookController extends Controller
{
    private static $url_segment = 'shop-webhook';

    public function index(HTTPRequest $request): HTTPResponse
    {
        $gatewayId = $request->param('Gateway');
        $registry = GatewayRegistry::singleton();
        $gateway = $registry->getGateway($gatewayId);

        if (!$gateway instanceof WebhookCapableGateway) {
            return $this->httpError(404);
        }

        return $gateway->handleWebhook($request);
    }
}
```

### Additional Interface

```php
interface WebhookCapableGateway extends ModernPaymentGateway
{
    public function handleWebhook(HTTPRequest $request): HTTPResponse;
}
```

---

## Implementation Order

| Step | Description | Risk | Effort |
|------|-------------|------|--------|
| 1 | Create `GatewayRegistry` class | Low | Small |
| 2 | Create `ModernPaymentGateway` interface + `PaymentResult` | Low | Small |
| 3 | Create `ModernPayment` checkout component | Low | Medium |
| 4 | Add `recordModernPayment()` to `OrderProcessor` | Low | Small |
| 5 | Add conditional branch in `PaymentForm::submitpayment()` | Medium | Small |
| 6 | Add conditional branch in `OrderActionsForm::dopayment()` | Medium | Small |
| 7 | Replace `GatewayInfo::getSupportedGateways()` calls with registry | Medium | Medium |
| 8 | Update `Checkout::setPaymentMethod()` / `getSelectedPaymentMethod()` to use registry | Medium | Small |
| 9 | Create `WebhookController` + `WebhookCapableGateway` interface | Low | Small |
| 10 | Write tests (unit + integration with a mock modern gateway) | Low | Medium |
| 11 | Documentation + YAML config examples | Low | Small |

---

## Testing Strategy

### Unit Tests

- `GatewayRegistryTest` — verifies merging of Omnipay + modern gateways, lookup by identifier.
- `PaymentResultTest` — value object behaviour.
- `ModernPaymentComponentTest` — form fields and requirements injection.

### Integration Tests

- Create a `MockModernGateway` implementing `ModernPaymentGateway` that always succeeds.
- Test full checkout flow: cart → checkout → modern payment submission → order marked Paid.
- Test failure path: gateway returns error → user sees message, order stays Unpaid.
- Test webhook path: async confirmation → order transitions correctly.

### Backward Compatibility Tests

- Existing `ShopPaymentTest` must continue to pass unchanged (Omnipay Dummy gateway).
- Existing `OrderProcessorTest` must continue to pass unchanged.

---

## File Summary (New Files)

```
src/Payment/
├── GatewayRegistry.php
├── ModernPaymentGateway.php
├── PaymentResult.php
├── WebhookController.php
└── WebhookCapableGateway.php

src/Checkout/Component/
└── ModernPayment.php

tests/php/Payment/
├── GatewayRegistryTest.php
├── MockModernGateway.php
└── ModernPaymentFlowTest.php
```

## Files Modified

```
src/Forms/PaymentForm.php              — add modern gateway branch in submitpayment()
src/Forms/OrderActionsForm.php         — add modern gateway branch in dopayment()
src/Checkout/OrderProcessor.php        — add recordModernPayment() method
src/Checkout/Checkout.php              — use GatewayRegistry for method validation
src/Checkout/Component/Payment.php     — use GatewayRegistry for gateway list
src/Checkout/CheckoutFieldFactory.php  — use GatewayRegistry for payment method fields
src/Checkout/Step/PaymentMethod.php    — use GatewayRegistry
src/Checkout/SinglePageCheckoutComponentConfig.php — conditional modern component
```

---

## Example: How a Stripe Elements Module Would Integrate

A separate `silvershop/stripe-elements` package would:

1. Require `silvershop/core` (this PR).
2. Implement `ModernPaymentGateway` (and optionally `WebhookCapableGateway`).
3. Register via YAML:

```yaml
SilverShop\Payment\GatewayRegistry:
  modern_gateways:
    stripe_elements: 'Credit Card (Stripe)'

SilverStripe\Core\Injector\Injector:
  SilverShop\Payment\ModernPaymentGateway.stripe_elements:
    class: MyVendor\StripeElements\StripeElementsGateway
    properties:
      PublishableKey: '`STRIPE_PUBLISHABLE_KEY`'
      SecretKey: '`STRIPE_SECRET_KEY`'
      WebhookSecret: '`STRIPE_WEBHOOK_SECRET`'
```

4. The gateway class handles:
   - `getFormFields()` → returns a `<div id="stripe-card-element">` + hidden field for PaymentIntent ID.
   - `getRequirements()` → injects `https://js.stripe.com/v3/` and a custom JS file that mounts Elements and intercepts form submit.
   - `processPayment()` → uses Stripe PHP SDK to confirm the PaymentIntent server-side, returns `PaymentResult`.
   - `handleWebhook()` → verifies signature, handles `payment_intent.succeeded` for async flows.

No core changes needed beyond this PR.
