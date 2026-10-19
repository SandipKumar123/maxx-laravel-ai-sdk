<?php

declare(strict_types=1);

namespace Maxx\Ai\Billing;

interface InvoiceHandler
{
    /**
     * Runs inside the inbox database transaction. Persist local invoice/adjustment records
     * on the same connection and return a stable contract identifier, never call a provider.
     */
    public function handle(BillingEvent $event): string;
}
