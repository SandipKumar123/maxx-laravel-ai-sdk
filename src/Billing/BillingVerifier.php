<?php

declare(strict_types=1);

namespace Maxx\Ai\Billing;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Maxx\Ai\Data\Validate;
use Maxx\Ai\Exceptions\VerificationException;

final class BillingVerifier
{
    private array $keys = [];

    public function __construct(
        array $publicKeys,
        private readonly string $accountId,
        private readonly string $invoiceOwnerId,
        private readonly string $issuer = 'maxx-m2',
    ) {
        Validate::identifier($accountId);
        Validate::identifier($invoiceOwnerId);
        if ($publicKeys === [] || $issuer === '') {
            throw new \InvalidArgumentException('Explicit billing trust configuration is required.');
        }
        foreach ($publicKeys as $kid => $pem) {
            if (!is_string($kid) || $kid === '' || !is_string($pem)) {
                throw new \InvalidArgumentException('Billing public key ring must map key IDs to PEM strings.');
            }
            $public = openssl_pkey_get_public($pem);
            $details = $public === false ? false : openssl_pkey_get_details($public);
            if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
                throw new \InvalidArgumentException('Billing verification requires RSA public keys of at least 2048 bits.');
            }
            $this->keys[$kid] = new Key($public, 'RS256');
        }
    }

    public function event(#[\SensitiveParameter] string $signedEvent): BillingEvent
    {
        try {
            $claims = $this->decode($signedEvent);
            foreach (['event_id', 'account_id', 'invoice_owner_id', 'logical_action_id'] as $field) {
                if (!isset($claims[$field]) || !is_string($claims[$field])) {
                    throw new VerificationException();
                }
                Validate::identifier($claims[$field]);
            }
            if ($claims['account_id'] !== $this->accountId || $claims['invoice_owner_id'] !== $this->invoiceOwnerId
                || !isset($claims['currency']) || !is_string($claims['currency'])
                || !preg_match('/\A[A-Z]{3}\z/', $claims['currency'])) {
                throw new VerificationException();
            }
            $type = $claims['event_type'] ?? null;
            if (!in_array($type, ['charge_finalized', 'charge_adjustment'], true)) {
                throw new VerificationException();
            }
            $amount = $claims[$type === 'charge_finalized' ? 'amount' : 'amount_delta'] ?? null;
            if (!is_string($amount)) {
                throw new VerificationException();
            }
            if ($type === 'charge_adjustment') {
                Validate::identifier($claims['adjustment_id'] ?? '');
                Validate::hash($claims['evidence_hash'] ?? '');
                Validate::storedDecimal($claims['original_charge'] ?? '');
                $reason = $claims['reason'] ?? null;
                if (($reason !== 'approved_credit' && $reason !== 'approved_debit')
                    || ($reason === 'approved_credit' && !str_starts_with($amount, '-'))
                    || ($reason === 'approved_debit' && str_starts_with($amount, '-'))
                    || !preg_match('/[1-9]/', $amount)) {
                    throw new VerificationException();
                }
                Validate::decimal(str_starts_with($amount, '-') ? substr($amount, 1) : $amount);
            } else {
                $amount = Validate::storedDecimal($amount);
                Validate::storedDecimal($claims['quantity'] ?? '');
                if (($claims['unit'] ?? null) !== 'credits' || !is_array($claims['details'] ?? null)) {
                    throw new VerificationException();
                }
                Validate::identifier($claims['tenant_id'] ?? '');
                Validate::identifier($claims['deployment_id'] ?? '');
            }

            return new BillingEvent(
                $claims['event_id'], $type, $claims['account_id'], $claims['invoice_owner_id'],
                $claims['logical_action_id'], $claims['currency'], $amount,
                hash('sha256', $signedEvent), $claims,
            );
        } catch (\InvalidArgumentException|\TypeError) {
            throw new VerificationException();
        }
    }

    public function webhook(
        #[\SensitiveParameter] string $signedEvent,
        #[\SensitiveParameter] string $delivery,
    ): BillingEvent {
        $event = $this->event($signedEvent);
        $claims = $this->decode($delivery);
        $canonicalString = json_encode($signedEvent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!isset($claims['exp']) || !is_int($claims['exp']) || $claims['exp'] <= time()
            || $claims['exp'] <= $claims['iat'] || $claims['exp'] - $claims['iat'] > 300
            || !isset($claims['delivery_id']) || !is_string($claims['delivery_id'])
            || !preg_match('/\A[A-Za-z0-9_.:-]{1,255}\z/', $claims['delivery_id'])
            || ($claims['event_id'] ?? null) !== $event->eventId
            || !is_string($claims['payload_hash'] ?? null)
            || !hash_equals(hash('sha256', $canonicalString), $claims['payload_hash'])) {
            throw new VerificationException();
        }

        return $event;
    }

    private function decode(#[\SensitiveParameter] string $token): array
    {
        if (strlen($token) > 32768 || strlen($token) < 10) {
            throw new VerificationException();
        }
        try {
            $claims = (array) JWT::decode($token, $this->keys);
        } catch (\UnexpectedValueException|\DomainException|\InvalidArgumentException) {
            throw new VerificationException();
        }
        if (($claims['iss'] ?? null) !== $this->issuer || ($claims['aud'] ?? null) !== $this->invoiceOwnerId
            || ($claims['contract_version'] ?? null) !== 'm2-dummy-v1'
            || !isset($claims['iat']) || !is_int($claims['iat']) || $claims['iat'] < 0
            || $claims['iat'] > time() + 30) {
            throw new VerificationException();
        }

        // Keep nested claims as arrays while preserving JSON decimal strings.
        return json_decode(json_encode($claims, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
    }
}
