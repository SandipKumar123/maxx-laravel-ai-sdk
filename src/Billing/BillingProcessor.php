<?php

declare(strict_types=1);

namespace Maxx\Ai\Billing;

use Illuminate\Database\ConnectionInterface;
use Maxx\Ai\Data\Validate;
use Maxx\Ai\Exceptions\SdkException;
use Maxx\Ai\GovernanceClient;

final readonly class BillingProcessor
{
    public function __construct(
        private ConnectionInterface $database,
        private BillingVerifier $verifier,
        private InvoiceHandler $handler,
        private GovernanceClient $client,
    ) {
    }

    public function process(#[\SensitiveParameter] string $signedEvent, ?string $expectedEventId = null): string
    {
        $event = $this->verifier->event($signedEvent);
        if ($expectedEventId !== null && $expectedEventId !== $event->eventId) {
            throw new SdkException('Billing envelope event ID does not match its signed payload.');
        }

        return $this->persistAndAcknowledge($event);
    }

    public function processWebhook(
        #[\SensitiveParameter] string $signedEvent,
        #[\SensitiveParameter] string $delivery,
    ): string {
        return $this->persistAndAcknowledge($this->verifier->webhook($signedEvent, $delivery));
    }

    private function persistAndAcknowledge(BillingEvent $event): string
    {
        if ($this->database->transactionLevel() !== 0) {
            throw new SdkException('Process billing outside an existing transaction so acknowledgment follows a durable commit.');
        }
        $reference = $this->database->transaction(function () use ($event): string {
            $now = date('Y-m-d H:i:s');
            $this->database->table('maxx_ai_billing_inbox')->insertOrIgnore([
                'event_id' => $event->eventId,
                'payload_hash' => $event->payloadHash,
                'account_id' => $event->accountId,
                'invoice_owner_id' => $event->invoiceOwnerId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $row = $this->database->table('maxx_ai_billing_inbox')
                ->where('event_id', $event->eventId)->lockForUpdate()->first();
            if ($row === null || !hash_equals($row->payload_hash, $event->payloadHash)
                || $row->account_id !== $event->accountId || $row->invoice_owner_id !== $event->invoiceOwnerId) {
                throw new SdkException('Conflicting immutable billing event.');
            }
            if ($row->invoice_reference !== null) {
                return $row->invoice_reference;
            }
            $reference = Validate::identifier($this->handler->handle($event));
            $this->database->table('maxx_ai_billing_inbox')->where('event_id', $event->eventId)->update([
                'invoice_reference' => $reference, 'updated_at' => $now,
            ]);

            return $reference;
        }, 1);

        // Commit invoice evidence before remote acknowledgment. A failed ack is safely replayable.
        $ack = $this->client->acknowledge($event->eventId, $reference);
        if (($ack->data['acknowledged'] ?? null) !== true) {
            throw new SdkException('Maxx API did not confirm the billing acknowledgment.');
        }
        $this->database->table('maxx_ai_billing_inbox')->where('event_id', $event->eventId)->update([
            'acknowledged_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $reference;
    }
}
