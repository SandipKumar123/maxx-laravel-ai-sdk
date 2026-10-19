<?php

declare(strict_types=1);

namespace Maxx\Ai;

use Maxx\Ai\Data\ActionContext;
use Maxx\Ai\Data\ApiResponse;
use Maxx\Ai\Data\AuthorizationRequest;
use Maxx\Ai\Data\CompletionRequest;
use Maxx\Ai\Data\EntitlementRequest;
use Maxx\Ai\Data\RecoveryRequest;
use Maxx\Ai\Data\Validate;
use Maxx\Ai\Http\Transport;

final readonly class GovernanceClient
{
    public function __construct(private Transport $transport)
    {
    }

    public function scopeKeys(ActionContext $context): ApiResponse
    {
        return $this->transport->request('POST', '/v1/actions/scope-keys', $context->toArray());
    }

    public function authorize(AuthorizationRequest $request): ApiResponse
    {
        return $this->transport->request('POST', '/v1/actions/authorize', $request->toArray());
    }

    public function status(string $actionId): ApiResponse
    {
        return $this->transport->request('GET', '/v1/actions/'.rawurlencode(Validate::identifier($actionId)));
    }

    public function recover(string $actionId, RecoveryRequest $request): ApiResponse
    {
        return $this->transport->request('POST', '/v1/actions/'.rawurlencode(Validate::identifier($actionId)).'/recoveries', $request->toArray());
    }

    public function complete(string $actionId, CompletionRequest $request): ApiResponse
    {
        return $this->transport->request('POST', '/v1/actions/'.rawurlencode(Validate::identifier($actionId)).'/completion', $request->toArray());
    }

    public function issueEntitlement(EntitlementRequest $request): ApiResponse
    {
        return $this->transport->request('POST', '/v1/entitlements', $request->toArray());
    }

    public function statement(): ApiResponse
    {
        return $this->transport->request('GET', '/v1/statements');
    }

    public function deliverReceipt(#[\SensitiveParameter] string $signedReceipt): ApiResponse
    {
        if (strlen($signedReceipt) < 10 || strlen($signedReceipt) > 32768) {
            throw new \InvalidArgumentException('Invalid signed receipt length.');
        }

        return $this->transport->request('POST', '/internal/m2/receipts', ['signed_receipt' => $signedReceipt]);
    }

    public function billingEvents(?string $cursor = null, int $limit = 100): ApiResponse
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Billing page limit must be between 1 and 500.');
        }
        $query = ['limit' => $limit];
        if ($cursor !== null) {
            $query['cursor'] = Validate::identifier($cursor);
        }

        return $this->transport->request('GET', '/v1/billing-events', query: $query);
    }

    public function acknowledge(string $eventId, string $invoiceReference): ApiResponse
    {
        return $this->transport->request(
            'POST',
            '/v1/billing-events/'.rawurlencode(Validate::identifier($eventId)).'/ack',
            ['invoice_reference' => Validate::identifier($invoiceReference)],
        );
    }
}
