<?php

declare(strict_types=1);

namespace Maxx\Ai\Laravel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maxx\Ai\Billing\BillingProcessor;
use Maxx\Ai\Exceptions\VerificationException;

final class BillingWebhookController
{
    public function __invoke(Request $request, BillingProcessor $processor): JsonResponse
    {
        if (strlen($request->getContent()) > 70000) {
            return new JsonResponse(['code' => 'PAYLOAD_TOO_LARGE'], 413);
        }
        $event = $request->input('signed_event');
        $delivery = $request->input('delivery');
        if (!is_string($event) || !is_string($delivery)) {
            return new JsonResponse(['code' => 'INVALID_DELIVERY'], 422);
        }
        try {
            $processor->processWebhook($event, $delivery);
        } catch (VerificationException) {
            return new JsonResponse(['code' => 'UNTRUSTED_DELIVERY'], 401);
        }

        return new JsonResponse(['processed' => true]);
    }
}
