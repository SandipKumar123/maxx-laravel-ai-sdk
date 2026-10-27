# Laravel AI SDK user manual

Updated: 27 October 2026. Audience: Laravel host developers and operators.

**Local/testing only.** This package implements provisional `m1-dummy-v1` and `m2-dummy-v1`
contracts, not approved production contracts. It is a library, not a standalone server or
login portal. Do not serve its source directory through Apache.

## 1. Prerequisites and installation

- PHP 8.2 or newer with JSON, OpenSSL and mbstring; Composer; Laravel 12.
- A registered backend customer account, application and deployment.
- Explicit local/test M1/M2 configuration; actions, policies, prices, budgets and funding.
- Host tenant/user permissions checked locally before API calls.

Audit the host's actual Composer lockfile/runtime first. Laravel 11 support is not qualified:
secure Composer resolution was blocked by advisories, and the owner selected Laravel 12.
Do not ignore platform requirements or disable advisory blocking.

For sibling local checkouts, add this repository entry to the **host application's**
Composer configuration:

```json
{
  "repositories": [
    { "type": "path", "url": "../maxx-laravel-ai-sdk", "options": { "symlink": false } }
  ]
}
```

Run from the Laravel host:

```powershell
composer require maxx/laravel-ai-sdk:"dev-main"
php artisan vendor:publish --tag=maxx-ai-config
```

`dev-main` is a development installation, not an approved immutable release. Pin a reviewed
release when available. Installation does not run migrations, register routes, fund accounts,
or start workers. Publicly serve only the host's public directory.

## 2. Configure a deployment credential

A platform administrator uses Universal AI **Client Access** to register the scope and issue
a least-privilege credential. Store its one-time value only in the host server environment.
It is not a platform operator login token or provider API key.

```dotenv
APP_ENV=local
MAXX_AI_ENABLED=true
MAXX_AI_ALLOW_PROVISIONAL=true
MAXX_AI_BASE_URL=http://127.0.0.1:8000
MAXX_AI_AUTH_MODE=platform
MAXX_AI_ACCESS_TOKEN=<one-time-platform-issued-secret>
MAXX_AI_ACCOUNT_ID=<registered-account>
MAXX_AI_APPLICATION_ID=<registered-application>
MAXX_AI_DEPLOYMENT_ID=<registered-deployment>
```

Run `php artisan config:clear` after authorized configuration changes. Never relabel a
production host as local to bypass the package guard. Account/application values also scope
billing verification. Credentials expire; rotation immediately revokes the old credential.
No secret belongs in browser code, logs, source control or support chat.

Credential activation alone is not generation readiness. Onboarding does not configure policies,
prices, budgets, funding, human approval or invoice handling. Billing streams are application/
invoice-owner scoped, not isolated by regional deployment.

Machine JWT mode remains available for explicitly provisioned services. It requires a protected
RSA private-key file, issuer, subject, kid and matching backend trust. Do not reuse backend signing
keys. See [identity and keys](README.md#identity-and-keys) for the full configuration.

## 3. Governed execution

1. Authenticate and authorize the host user for the current tenant and requested business data.
2. Build `ActionContext` from trusted tenant/actor and module/service/action values.
3. Persist an idempotency key and payload before calling `GovernanceClient::authorize`.
4. The authorized executor uses `ExecutionClient::execute` with the exact M2-issued token,
   scoped identifiers and limits. A machine/admin bearer is not M1 execution authorization.
5. Use status/receipt lookup and trusted settlement delivery; do not infer settlement from UI success.
6. Validate the business result and report completion with evidence. A human-review action requires
   a separately trusted human identity; ordinary deployment credentials cannot attest adoption.

The SDK does not automatically retry any operation. Timeouts may follow a committed mutation;
retain IDs and original keys, query status and reconcile. Never repeat unknown provider work
with a new key. Financial quantities remain decimal strings, not floats.

DIS delegation/C08 is not implemented. HRMS policy adoption and CRM drafting/review belong in host
adapters, not the shared package. See [client usage](README.md#client-usage) for typed examples.

## 4. Billing inbox and legal invoicing

Laravel owns legal invoices, tax, payment and statutory adjustments. The SDK verifies, deduplicates
and acknowledges events. **Acknowledgment is not payment.**

1. Configure `maxx-ai.billing.public_key_paths` as a trusted kid-to-absolute-public-key-path map.
   Retain historical verification keys; never copy backend private keys.
2. Select/back up the intended host database. Publish the optional inbox migration:

   ```powershell
   php artisan vendor:publish --tag=maxx-ai-migrations
   php artisan migrate
   ```

   Review all pending host migrations first; `migrate` is not limited to this package's migration.
3. Implement and bind [InvoiceHandler](src/Billing/InvoiceHandler.php). Resolve the verified
   account/event to the correct customer/tenant/jurisdiction and retain a unique event linkage.
4. Write invoice/adjustment evidence on the **same database connection/transaction as the inbox**,
   returning a stable invoice reference. Do not make external calls within the transaction.
   External invoicing needs a host-owned durable outbox and reconciliation.
5. Schedule/supervise bounded polling with overlap prevention:

   ```powershell
   php artisan maxx-ai:billing-pull --limit=100 --max-pages=10
   ```

Local invoice evidence commits before acknowledgment. An acknowledgment outage leaves evidence
intact, so replay retries acknowledgment without invoicing twice. Conflicting immutable events fail.

### Optional webhook

The SDK registers no route automatically. A host API route can explicitly use:

```php
Route::post('/integrations/maxx/billing', \Maxx\Ai\Laravel\BillingWebhookController::class);
```

Use operator-configured HTTPS, ingress rate/size limits, and API middleware outside browser
session/CSRF. Both signed event and signed delivery are required. Do not log request bodies/tokens.
Webhook and polling share the durable deduplication path. See
[durable billing integration](README.md#durable-billing-integration).

## 5. Verification

From the SDK repository:

```powershell
composer install
composer validate --strict
composer lint
composer test
composer audit --locked
php examples\fake-portals.php
php tools\sync-contracts.php "C:\xampp\htdocs\maxx-universal-ai-platform" --check
```

Parity checking is read-only and fails on upstream changes/missing schemas or invalid provenance.
Do not refresh snapshots without reviewing producer/consumer compatibility.

The [SDK workflow](.github/workflows/tests.yml) covers PHP 8.2/8.3/8.4 with Laravel 12
locked/latest/lowest dependencies. Private backend checkout needs a read-only
`CROSS_REPO_READ_TOKEN` Actions secret with access to the producer; never commit the token.

Dispatch the platform's **Pinned Laravel SDK qualification** at the intended platform candidate,
providing this SDK repository and its full immutable SHA. It verifies real HTTP/PHP/PostgreSQL and
rejects skipped tests. Passing fakes do not qualify an HRMS/CRM host or externally reachable webhook.

## 6. Troubleshooting

| Symptom | Action |
| --- | --- |
| Disabled/environment rejected | Check explicit local/testing enablement; never bypass the production guard. |
| 401 | Check expired/revoked credential, auth mode and matching registration without logging secrets. |
| 403 | Check permitted operation, customer/tenant scope, policy and trusted human identity. |
| Unknown outcome/timeout | Preserve key and IDs; use status and evidence-based reconciliation, not blind retry. |
| Billing signature/scope failure | Check trusted issuer, account/application, kid and key rotation; never bypass verification. |
| InvoiceHandler missing | Bind a host-owned implementation; there is deliberately no default legal invoicer. |
| CI checkout repository not found | Verify repository name and cross-repository read secret permissions, then rerun. |
| Laravel 11 installation conflict | Use a separately approved Laravel 12 host upgrade; do not loosen constraints blindly. |

Use correlation/request IDs for support. Never attach credentials, private keys or business content.

## 7. Rollback and acceptance

Disable the host AI entry point and restore a compatible package version while retaining inbox,
documents, reviewed results and financial evidence. Do not delete rows to bypass conflicts.
Contracts, actual host integration tests, CI evidence, release pinning and owner sign-off remain
required before acceptance. This manual does not grant production approval.

The Universal AI dashboard footer provides bundled browser versions of both user guides,
opening in new tabs. These static guides contain no credentials and can be printed to PDF.
