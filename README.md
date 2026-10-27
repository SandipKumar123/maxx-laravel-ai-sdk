# Maxx Laravel AI SDK

Standalone Composer package: **`maxx/laravel-ai-sdk`**.

For step-by-step host installation, access, billing, troubleshooting and rollback, see the
[user manual](USER-MANUAL.md). The Universal AI dashboard footer also provides a bundled
browser-readable SDK guide that opens in a new tab.

This is a library, not a web application or standalone server. Do not expose this directory
through Apache. A deny-all `.htaccess` is included for XAMPP; ensure the server honors it or
exclude the directory from the document root.

**Status: provisional local/test SDK, not an approved production release.**
Implements the reusable SDK portion of worksheet task **UAI-WF-03-A** against
`m1-dummy-v1` / `m2-dummy-v1`. Production and staging environments are intentionally
rejected: only `local` and `testing` with explicit opt-in are supported. Frozen C01-C08
contracts and human acceptance are still required.

## Requirements and scope

- PHP 8.2+ with OpenSSL, JSON and mbstring; Laravel 12.
- Host applications must confirm their actual lockfile/runtime before installation; Laravel
  10/11 support is not claimed. Laravel 11 qualification attempted on 27 October 2026 failed
  Composer resolution because matching framework releases were blocked by security advisories.
  Advisory blocking was not disabled. The owner selected retaining Laravel 12 support; Laravel 11
  hosts require an approved upgrade before integration, not relaxed package constraints.
- Backend machine identity and registered account/application/deployment, matching approved
  local/test key configuration and enabled M1/M2 runtimes.
- The SDK does not implement DIS, replicate users/tenant tables, provision credits automatically,
  adopt HRMS policies, calculate tax or create legal invoices.
- HRMS/CRM business adapters (03-B/03-C) remain in their own host repositories.

## Install in an existing Laravel application

This repository is separate from the Python backend. Until a private VCS release is published,
add a Composer path repository to the **host application's** `composer.json`:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../maxx-laravel-ai-sdk",
      "options": {"symlink": false}
    }
  ]
}
```

Then run from that Laravel application's directory:

```powershell
composer require maxx/laravel-ai-sdk:"dev-main"
php artisan vendor:publish --tag=maxx-ai-config
```

Laravel auto-discovers the service provider. No routes, migrations, accounts, funding, workers
or HTTP calls run automatically. Pin an approved immutable release in consuming applications
when a release exists; `dev-main` is only a local development installation.

Copy the names from [the environment example](examples/.env.example) into your host environment.
Set `APP_ENV=local` or `testing`; never change a production application to `local` to bypass the gate.
The package is disabled by default.

### Identity and keys

#### Platform-issued customer credential

With a backend including migration `0006_m2_client_credentials`, a platform administrator can
register the customer account/application/deployment under **Client Access**, then issue a
scoped credential. Save the secret once in the Laravel server environment:

```dotenv
MAXX_AI_AUTH_MODE=platform
MAXX_AI_ACCESS_TOKEN=<one-time-secret>
MAXX_AI_ACCOUNT_ID=client-a
MAXX_AI_APPLICATION_ID=client-a-hrms
MAXX_AI_DEPLOYMENT_ID=client-a-india
```

Keep the SDK enabled only in an approved local/testing environment with provisional contracts
explicitly allowed. Re-publish/merge the updated SDK config in an existing installation, then run
`php artisan config:clear` after changing environment settings. This auth mode requires a version
of this package containing `PlatformTokenProvider`; older published versions do not support it.

Clients do not need a machine private signing key in this mode. Identity and permissions are
resolved by the backend from the stored credential hash, not from client-supplied account IDs.
The account/application config remains required for billing-signature verification; configure
the trusted backend billing public key ring separately.

Use separate customer accounts, and a credential per deployment or service. Secrets expire in
1-90 days; rotation immediately revokes the old secret and displays a replacement once.
Revocation rejects new authentication requests; it does not cancel already-authorized M1 work.
Never place secrets in browser code, Git, chat or logs. A lost issuance response must be reconciled
by refreshing the credential list and revoking inaccessible credentials before issuing another.

Allowed permissions are `actions:execute`, `completion:report`, `billing:read`, and `billing:ack`.
These credentials cannot issue funding, deliver internal receipts or attest human approval.
Accepted completion of a human-review action still needs a separate trusted human identity.
Onboarding does not configure actions, policies, pricing, budgets, funding or host invoice handlers.
Billing streams are application/invoice-owner scoped, not isolated by regional deployment.

#### Existing machine JWT mode

`MAXX_AI_AUTH_MODE=machine` remains the default for compatibility.
Configure the machine issuer, subject, account, application, deployment and `kid` to match
the backend registration. `MAXX_AI_MACHINE_PRIVATE_KEY_PATH` must point to a protected local
RSA private-key file (2048 bits minimum) **outside the web root**. The corresponding public key
must be explicitly trusted by the backend. Do not copy or reuse backend billing/receipt private
keys as machine keys. There are no bundled keys, passwords or automatic demo-key discovery.

Adjust `machine.permissions` in the published config to least privilege. Funding and receipt
delivery require separate backend-designated subjects and permissions; permission strings alone
do not grant authority. Machine tokens expire after 300 seconds (maximum 900).

The built-in signer represents a provisioned service, not an end user. For human review, bind a
trusted request-scoped `TokenProvider` carrying the backend-approved `human_actor_ref` and permission;
never turn an arbitrary browser actor reference into a signed human-review identity. Token issuance,
MaxxSATA SSO and user permission decisions remain with the host/identity owners.

## Client usage

```php
use Maxx\Ai\Data\ActionContext;
use Maxx\Ai\Data\AuthorizationRequest;
use Maxx\Ai\GovernanceClient;

// Perform the host application's tenant/user permission checks first.
$client = app(GovernanceClient::class);
$request = new AuthorizationRequest(
    new ActionContext('tenant-1', 'actor-1', 'hrms', 'policy', 'interpret'),
    'persisted-request-id', 'dummy-resource', 'dummy-text', 'local', 'dis-service',
);
$authorization = $client->authorize($request);
$actionId = $authorization->string('logical_action_id');
$status = $client->status($actionId);
```

All result objects expose `data` and a generated `correlationId`; `string($key)` requires an
actual string and fails explicitly on malformed responses. Financial values stay decimal strings.
The HTTP client sets `X-Correlation-ID`; backend persistence/echo of that header is not guaranteed.

| Client | Methods |
| --- | --- |
| `GovernanceClient` | `scopeKeys`, `authorize`, `status`, `recover`, `complete`, `issueEntitlement`, `statement`, `deliverReceipt`, `billingEvents`, `acknowledge` |
| `ExecutionClient` | `execute`, `status`, `receipt` |

Completion takes a typed `CompletionRequest`, including event ID, outcome, evidence hash,
jurisdiction, validator version and optional human/adopted-policy references. The SDK never
marks a business result accepted or adopts a policy automatically.

M1 execution uses a typed `ExecutionRequest` plus the **M2-issued authorization token**, not a
machine bearer token. The caller must be the authorized executor and preserve the exact scoped
IDs/token limits from the trusted M2 response. Do not send document content to M2 accounting.
DIS delegation is not implemented because C08 is not approved.

### Failure and retry behavior

- **No automatic retries**, including reads, authorization, provider work and acknowledgments.
- `TransportException` carries `outcomeUnknown` and correlation ID. A POST timeout may already
  have committed work. `ApiException` exposes HTTP status, safe error code, correlation ID and
  `outcomeUnknown` for server errors on POST.
- `ProtocolException` marks malformed/oversized responses and whether a mutation's outcome
  needs reconciliation. A successful HTTP status alone does not prove a valid operation result.
- Persist request IDs and payloads before sending. Never reuse an idempotency key for changed
  inputs. Conflicts are surfaced; the backend is the idempotency authority.
- After an uncertain provider outcome, use known action/execution status and reconciliation;
  never create a new attempt automatically. If the initial response is lost before its ID is
  known, use the backend's documented idempotency/reconciliation procedure; the SDK does not
  invent an unavailable lookup-by-key endpoint.
- Redirects are refused; TLS verification stays on. HTTP is allowed only for loopback.
- The SDK does not log payloads, bearer tokens, private keys or raw backend errors. Do not log
  full responses, fake request histories or signed tokens in host applications. Guzzle transport
  exceptions are deliberately not chained because they retain sensitive request material.

## Durable billing integration

Publish and explicitly apply the optional **application-owned inbox** migration:

```powershell
php artisan vendor:publish --tag=maxx-ai-migrations
php artisan migrate
```

Inspect migrations and select the intended database before running. If
`billing.connection` is not the default connection, migrate the same named connection explicitly
using the host's migration procedure. No central wallet/ledger or business tenant tables are created.
Inbox downgrade refuses to delete evidence.

In `config/maxx-ai.php`, set `billing.public_key_paths` to a map of trusted `kid` values to
local **public** RSA PEM files. The issuer defaults to `maxx-m2`; audience/invoice owner must
equal `machine.application_id`, and account must equal `machine.account_id`. Retain old public
keys while their immutable billing events remain replayable. Tokens cannot supply their own keys.

Bind your application's implementation of `Maxx\Ai\Billing\InvoiceHandler`:

```php
$this->app->bind(
    \Maxx\Ai\Billing\InvoiceHandler::class,
    \App\Billing\MaxxInvoiceHandler::class,
);
```

`handle(BillingEvent $event): string` must:

1. Resolve the verified account/action/tenant mapping to the correct local invoice customer.
2. Create a local invoice line for `charge_finalized`, or a linked credit/debit adjustment for
   `charge_adjustment`; preserve amounts as exact decimal strings.
3. Use **the same database connection/transaction as the inbox** and add a unique event linkage.
4. Return a stable invoice reference matching `[A-Za-z0-9_.:-]{1,255}`.
5. Never make provider/payment/network calls inside the transaction. For external invoicing,
   implement a host-owned durable outbox and reconciliation workflow instead.

No default invoice handler exists: an SDK cannot safely guess your tax, approval or invoice rules.
Do not call billing processing inside an existing database transaction.

Processing locks a unique inbox event, rejects changed payload hashes, commits the host invoice
reference, then acknowledges remotely. If acknowledgment fails, the local record remains and
replay retries acknowledgment **without running the invoice handler again**. This is an atomic
same-database guarantee, not a claim of exactly-once external network side effects.

### Pull worker

```powershell
php artisan maxx-ai:billing-pull --limit=100 --max-pages=10
```

Schedule it using your application's scheduler and `withoutOverlapping()`. Each invocation starts
from the oldest unacknowledged events, validates signed payloads and advances bounded opaque
cursors. Failures propagate; the command never skips a failed invoice or writes a permanent
cursor that could strand it.

### Optional webhook

The SDK deliberately registers no route. Register this controller in your host's API routes,
outside browser session authentication/CSRF middleware and with appropriate ingress rate limits:

```php
Route::post('/integrations/maxx/billing', \Maxx\Ai\Laravel\BillingWebhookController::class);
```

Both `signed_event` and `delivery` are mandatory. The controller verifies the event plus the
short-lived delivery envelope, pinned key, scope, expiry, event ID and exact backend payload hash
before touching the inbox. Duplicate deliveries use the same durable event deduplication as polling.
Configure the HTTPS destination with the backend owner. Add a reverse-proxy request-size limit.
Keep `APP_DEBUG=false` on exposed receivers and prevent request-body capture in access/APM logs.

## Tests and fakes

```powershell
composer install
composer lint
composer test
composer audit
php examples\fake-portals.php
```

For this Windows XAMPP installation, if PHP is not on PATH or ZIP is disabled:

```powershell
$env:PATH = "D:\XAMPP\php;" + $env:PATH
$env:OPENSSL_CONF = "D:\XAMPP\apache\conf\openssl.cnf"
php -d extension=zip C:\composer\composer.phar install
```

`FakeTransport` queues deterministic responses/exceptions, records calls for assertions and
never falls through to the network. [The HRMS/CRM example](examples/fake-portals.php) uses the
same package for both portals.

The suite covers serialized requests against exported backend JSON schemas, JWT identity,
signature/algorithm/key rotation, exact decimals, timeout/no-retry behavior, changed billing
payloads, duplicate invoicing, transaction rollback, acknowledgment outage/replay, polling and
webhook boundaries, including two concurrent PHP processes invoicing the same event once.
Laravel integration tests use isolated SQLite, not a host application's data.
The separate live qualification below also tests PostgreSQL concurrent polling/webhook processing.
MySQL and actual host-application integrations still need their own evidence.

The [CI workflow](.github/workflows/tests.yml) tests the locked baseline and Laravel 12
latest/lowest dependency resolution on PHP 8.2/8.3/8.4. Every job runs the full suite, including
invoice concurrency, and uploads JUnit evidence; resolution jobs also retain their lockfile.
A configured workflow is not evidence that remote CI has run.

The contract-parity job checks this SDK candidate against an immutable backend revision.
Repository variables `MAXX_UAI_BACKEND_REPOSITORY` and `MAXX_UAI_BACKEND_SHA` select the producer;
defaults are `SandipKumar123/maxx-universal-ai-platform` at
`09da738a138b1a05be7e33abe75e5f97c83a1396`. Update both when changing the approved producer.
For a private cross-repository checkout, configure a read-only `CROSS_REPO_READ_TOKEN` secret
with access to the producer; the default workflow token normally cannot read another private
repository. No personal token belongs in source.

Check upstream snapshots without modifying them:

```powershell
php tools\sync-contracts.php "C:\xampp\htdocs\maxx-universal-ai-platform" --check
```

The check fails on missing/changed schemas or invalid provenance. Only LF/CRLF differences
are accepted; JSON/schema changes require explicit review. Matching snapshots demonstrate
parity, not automatic backward compatibility or approval of an incompatible contract.

Refresh exported schema snapshots only after reviewing the backend changes:

```powershell
php tools\sync-contracts.php "D:\XAMPP\htdocs\maxx-universal-ai-platform"
composer test
```

[The provenance manifest](contracts/manifest.json) records each upstream schema path, original
source SHA-256 and the SHA-256 of its LF-normalized SDK snapshot.
Snapshots are provisional, not C01-C08 approval.

### Local verification

On 27 October 2026, the working-tree candidate on PHP 8.5.12 with locked Laravel 12.70.0
passed **87 tests, 176 assertions**, including read-only upstream parity checks, changed/missing
schema rejection, and LF/CRLF handling. This is separate from historical PHP 8.2 results and
does not establish the configured remote PHP matrix, a tagged release, or production acceptance.

Separate temporary package copies also passed all 87 tests / 176 assertions with Laravel
12.70.1 (latest resolution) and 12.69.0 (secure lowest resolution). The lowest resolution emitted
two PHP 8.5 deprecations from Testbench's bundled database configuration; the locked/latest
runs were clean. No vendor files or advisory policies were changed to suppress these notices.
Composer strict validation, PHP lint, locked dependency audit, and the eight-schema upstream
parity check passed. Both workflow files passed actionlint.

### Real backend and PostgreSQL qualification

An opt-in backend test, `tests/integration/test_laravel_sdk_live.py`, drives this SDK fixture
over **real loopback HTTP**, not a mocked HTTP client. It uses the actual FastAPI M1/M2 routers,
ephemeral RSA identities, the dummy provider, real PostgreSQL accounting, and the SDK's actual
published inbox migration in a separate PostgreSQL host schema.

Verified on 19 October 2026 using PostgreSQL 17.11, PHP 8.2.12, Laravel 12.70.0 and
an isolated Python 3.13.16 runtime:

- Authorization and execution replay retain the original IDs; changed authorization payloads fail.
- Cross-account access, missing human-adoption evidence and jurisdiction mismatches fail.
- Accepted/rejected completions and duplicate receipt settlement retain durable accounting evidence.
- Rejected work emits a zero charge without discarding consumed provider expense.
- Real Python decimal strings such as `0E-12` are normalized exactly, without floats.
- Unknown provider outcomes keep their hold and deny recovery; no new provider attempt is created.
- Signed billing pages and opaque cursors work with the actual backend.
- A real remote acknowledgment followed by a deliberately lost response replays without reinvoicing.
- Two PHP processes race polling versus a Python-signed webhook for one PostgreSQL inbox event:
  one local invoice effect is committed. Invalid delivery signatures are rejected.
- Conflicting acknowledgment references fail; acknowledged events disappear from billing pull.
- The test server stops and generated schemas, credentials, keys and signed temporary evidence
  are removed after the run. Existing demo/customer records are not altered.

Run from the **backend** directory with a working project Python environment:

```powershell
$env:MAXX_UAI_RUN_DB_TESTS = "true"
$env:MAXX_UAI_RUN_SDK_TESTS = "true"
$env:MAXX_UAI_SDK_PATH = "D:\XAMPP\htdocs\maxx-laravel-ai-sdk"
$env:MAXX_UAI_SDK_PHP = "D:\XAMPP\php\php.exe"
$env:MAXX_UAI_SDK_PHP_PGSQL_EXTENSION = "true"
.\.venv\Scripts\python.exe -m pytest tests\integration\test_laravel_sdk_live.py -q -p no:cacheprovider
```

PHP needs `pdo_pgsql`; the extension flag enables it only for the test child processes. Omit
the flag if already enabled. The backend's existing isolated-database fixture uses
`MAXX_UAI_TEST_DATABASE_URL` when supplied, otherwise the configured local database; the database
role needs permission to create temporary schemas. No deployment migrations are applied.
The SDK repository is required only when this test is explicitly enabled.

The platform's `Pinned Laravel SDK qualification` workflow runs this test with PostgreSQL 16
and PHP 8.2, alongside parity, lint, dependency audit and the standalone suite. After committing
both candidates, dispatch it at the intended platform revision with `sdk_repository` and the
full 40-character `sdk_sha`. It records the actual platform/SDK SHAs and rejects a skipped
live test. Private SDK checkout requires the platform's read-only `CROSS_REPO_READ_TOKEN`.
It does not deploy services, use live AI, or qualify HRMS/CRM legal invoicing.

This verifies a freshly started isolated instance of the real backend, not your existing running
demo server or a live AI provider. The test invoice handler creates synthetic invoice evidence:
it is not an HRMS/CRM tax/invoicing implementation. Local PostgreSQL concurrency success does not
qualify another database/version, a production deployment, crash recovery, or operational load.

On 27 October 2026 the changed working-tree SDK candidate passed this real HTTP/PHP test
against the local platform candidate using PHP 8.5.12 and PostgreSQL 18.6: **1 passed, zero
skipped**. Validation created and removed a separate randomly named database; the configured
application database was not migrated or used for test records. This is local evidence, not a
remote CI run or release acceptance.

The existing project `.venv` was found unusable during this run: `C:\Python313` was missing
its standard library. Verification used an isolated managed Python 3.13 runtime with the unchanged
backend lockfile, rather than repairing shared Python or overwriting the project environment.

### Remaining integration checklist

| Area | Current evidence / remaining requirement |
| --- | --- |
| Real SDK-to-backend integration | Passed locally against the real isolated FastAPI server and PostgreSQL, with the dummy provider. |
| HRMS/CRM installation | India, USA and UAE HRMS adapters and locked SDK references are present; runtime qualification, upgrades and end-to-end host acceptance remain deployment-specific. CRM acceptance is separate. |
| Host invoice handler | Synthetic PostgreSQL handler verified; actual customer/tax/invoice mapping remains application-owned. |
| Approved production contracts and SSO | Still requires owner-approved contracts, real identity integration and acceptance. |
| Database concurrency | Local PostgreSQL 17.11 polling/webhook race passed; target deployment, failure/load and any MySQL qualification remain. |
| GitHub publication / release | Local Git remote is configured; publication of this candidate, passing remote workflows and an approved immutable release tag remain required. |

## Release gates and rollback

Before an approved release: freeze contracts/identity and error semantics, audit actual HRMS/CRM
PHP/Laravel baselines, run live machine/receiver tests and production-database concurrency tests,
review tax/invoice and human-approval integration, rehearse outages/rollback, record baseline/release
SHAs and reviewer acceptance, then publish/tag an immutable version. Do not remove the production
gate merely to make a deployment start.

Roll back the compatible package version while retaining the inbox and host invoice evidence.
Never delete rows to bypass a billing conflict. A configured Git remote or local passing tests
do not establish public publication, a release tag, host deployment or production acceptance.

<!-- SDK-INTEGRATION-2026-10-27:START -->
## HRMS integration documentation (27 October 2026)

The India, USA and UAE applications already reference this package and use `HrmsAiClient` for governed local/test generation. See [the host integration and operator manual](docs/USER-MANUAL.md) for the six-field regional connection workflow, locked/source version distinction, request recovery and host-owned invoice handling. These inspected HRMS applications require Laravel 12 in their manifests; this does not qualify every deployed host or add Laravel 10/11 support. The selected source SDK suite passed 65 tests / 111 assertions; host end-to-end and production acceptance are separate.
<!-- SDK-INTEGRATION-2026-10-27:END -->
