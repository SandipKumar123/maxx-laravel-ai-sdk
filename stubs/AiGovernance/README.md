# AI Governance

## Installation and removal

From a compatible Laravel HRMS host with the Composer repository configured:

```powershell
composer require maxx/laravel-ai-sdk:"dev-main"
php artisan maxx-ai:install
composer dump-autoload
php artisan config:clear
php artisan route:clear
# Back up and review the intended regional database first:
php Modules\AiGovernance\Tools\install.php
```

The Artisan installer copies this module, registers its provider, publishes missing SDK
configuration and creates `bootstrap/maxx-ai-installation.json`. Existing module directories
are refused; it does not upgrade an original HRMS module in place. Configure permissions
and the six-field SDK connection separately. Business call sites and sidebar links remain
host-owned. The migration tool is a separate database-changing step, not part of Artisan
installation.

To remove an unchanged generated module and then the package:

```powershell
php artisan maxx-ai:uninstall
# Continue only after successful uninstall and review of remaining host SDK dependencies:
composer remove maxx/laravel-ai-sdk
```

For an SDK-generated installation, run `php artisan maxx-ai:uninstall` before
`composer remove maxx/laravel-ai-sdk`. Uninstall only removes an unchanged module tracked by
its original `bootstrap/maxx-ai-installation.json` manifest; added/modified/missing files
and directories stop removal. Configuration, environment settings, credentials, permissions
and all database evidence are retained. The module provider is unregistered and route/config
caches cleared. Back up and review customized modules and remaining business SDK call sites
through the host deployment process; Composer removal alone does not delete this directory.

Open `/ai-governance` from the regional sidebar. Its Overview, Usage Analytics, Request History, and SDK Connection pages separate the tenant summary, filtered breakdowns, paginated records, and SDK status/check. Analytics and request history retain date/provider/status/user/module filters across page navigation. Active tenant administrators have the application's existing full module access; other users require `ai-governance.index`. Permission does not expand the tenant or region scope. Dates are UTC.

Every filter has a visible prompt. Dates accept `YYYY-MM-DD`, with a calendar picker when available; blank filters include all matching tenant records.

The connection card reports local setup readiness without making a network call on page load. Its authenticated, CSRF-protected, rate-limited POST check verifies the submitted Universal AI origin and platform credential with the six submitted connection/execution values. SDK scope checks use server-owned tenant/user identity; the SDK's machine mode remains a distinct configured fallback. It validates the scope response and returns only a safe connection result. Checks do not generate AI work or count as consumption. Shared account-level wallet balances are not exposed as tenant balances. SDK authorization and scope-key requests also enforce the authenticated tenant and actor at the transport boundary.

Activation requires each region's real backend URL and a verified Universal AI Client Access credential saved through SDK Connection. Existing SDK environment and opt-in gates remain in force. No identities, credentials or backend registrations are invented by this module. A ready configuration is not a verified connection; only a successful explicit backend check reports connected.

## JSON API

The bundled SDK version adds the same API structure as the regional Workspaces module:
`App/Http/Controllers/Api/AiGovernanceApiController.php`, `Routes/api.php`, and provider-loaded
`api/ai-governance` routes. It reuses the web module's reporting, permissions, regional
scope, SDK verification, and owner-scoped execution status services. The original source
HRMS repository is not automatically updated when SDK stubs change.

Prerequisites: Laravel Sanctum 4, its token table, `HasApiTokens` on the regional user model,
the host's `regional.scope` middleware and `App\Support\ScopeContext`, and this module's
migrations. Authenticate through the existing HRMS login API and send:

```http
Authorization: Bearer <HRMS-login-token>
Accept: application/json
Content-Type: application/json
```

Only personal access bearer tokens are accepted; session-only authentication is insufficient.
Existing HRMS login tokens work without new token abilities. Tokens never replace host permissions:
all operations validate active tenant/region identity and `ai-governance.index`; connection
checks/saves also require the administrator or `ai-governance.sdk.manage` authority enforced
by `GovernanceAccess`. No tenant, actor or regional ownership is taken from client input.

| Method | URL | Response data |
| --- | --- | --- |
| GET | `/api/ai-governance/overview` | `summary`, `filters`, `identity` |
| GET | `/api/ai-governance/analytics` | `summary`, `byModule`, `byUser`, `modules`, `users`, `filters`, `identity` |
| GET | `/api/ai-governance/requests` | `records`, `modules`, `users`, `filters`, `identity` |
| GET | `/api/ai-governance/sdk-connection` | `sdk`, `sdkConfiguration`, `canManageSdk`, `filters`, `identity` |
| POST | `/api/ai-governance/connection-check` | Connection result with `state`, `message`, `can_check`, `checked_at` |
| PUT | `/api/ai-governance/sdk-connection` | Confirmation `message` and non-secret `configuration` |
| GET | `/api/ai-governance/executions/{requestId}` | Owner-scoped `request_id`, `status`, and `execution_id` when available |

Success responses use `{"success":true,"data":{...}}`. Responses are private/no-store.
Reporting accepts optional `from`/`to` UTC dates (`YYYY-MM-DD`, inclusive), `user_id`, `module`,
`provider` (`openai` or `maxx-ai`) and `status` (`pending`, `completed`, `failed`, `unknown`).
Request records use Laravel pagination (25 per page, `page`); analytics users use 10 per page
with `users_page`. Each paginator exposes `data`, `current_page`, `per_page`, `total`, and links.
Invalid filters produce HTTP 422 with field errors. Filter choices remain tenant/region-scoped;
counts with unknown token usage are separate from measured totals.

An empty connection-check body checks the saved configuration. To check a proposed connection,
or to save it with PUT, supply all six fields:

```json
{
  "base_url": "https://your-approved-ai-api.example",
  "access_token": "<platform-issued-client-access-secret>",
  "resource_id": "<registered-resource>",
  "model_id": "<registered-model>",
  "execution_region": "<registered-execution-region>",
  "executor_subject": "<registered-executor>"
}
```

PUT independently verifies these settings before saving; a previous POST check is not a
session proof and never saves anything. A failed PUT leaves the previous saved settings intact.
`base_url` must be an HTTPS origin (loopback HTTP only); the credential must match the
platform's `maxx_sdk_` credential format. The backend scope check authenticates the
credential but does not prove execution availability or validate resource catalogue membership.
Resource/model/region/executor identifiers are syntactically checked and must match the backend
registry; actual execution still validates the authorized scope. No credential appears in
responses, and saved credentials use Laravel encryption.

Unauthenticated requests return 401; forbidden scope/permission or blocked provisional access
returns 403; invalid fields or missing/disabled connection settings return 422. Backend
unavailability returns 503; malformed/backend execution-status errors return 502. An unavailable
execution feature returns 503 with its existing `error_code`. Missing/other-owner execution
requests return 404 after the existing execution readiness gate passes. No AI work is resubmitted.
All endpoints allow 60 requests/minute per authenticated user; connection checks and saves share
an additional six-per-minute limit and return 429 with `Retry-After` when exceeded.

This API adds no generation endpoint, legal invoice handler, automatic permission grants,
settlement, or production-execution bypass. Existing web verify/save flows still use CSRF and
their session-bound verification proof.

### HRMS generation adapter

Resume parsing, campaign templates, job generation/validation, requisitions/justification, notification suggestions, assessment question generation and assessment evaluation now use `HrmsAiClient`. No HRMS AI feature calls OpenAI directly. The adapter authorizes the action with M2, submits it to M1 using the M2-issued execution authorization (never the client access credential), and reads the execution result. It validates M2's namespaced tenant ID and configured resource/model/region/executor. Backend policy controls model selection and token ceilings; JSON output instructions are included in the text prompt and business services validate their output.

Apply `2026_10_23_000001_create_ai_execution_requests.php` and `2026_10_23_000002_add_regional_ai_execution_settings.php` to the intended regional database. On SDK Connection, enter the real AI API origin, credential, Resource ID, Model ID, Execution region and Executor subject. Verify with Universal AI, then save the verified connection. All six values are bound to the verification session; changing them requires verification again. Saved execution settings take precedence over environment defaults. Existing connections without saved execution settings can still use these environment defaults:

```dotenv
HRMS_AI_RESOURCE_ID=
HRMS_AI_MODEL_ID=
HRMS_AI_EXECUTION_REGION=
HRMS_AI_EXECUTOR_SUBJECT=
```

The backend action registry must allow each module/action used by the adapter, with service code `hrms`, for that deployment and tenant. A verified connection alone does not grant execution permission or provide catalogue IDs. Local/testing still requires `MAXX_AI_ALLOW_PROVISIONAL=true`. Production remains blocked by the installed SDK; do not change a production environment to bypass this restriction.

Use the Universal AI API origin, not an HRMS frontend or regional HRMS portal address. A missing endpoint, authentication failure, HTML page or malformed scope response must never be marked verified. A saved verification timestamp records a successful past check; the connection card offers re-verification to confirm current backend availability.

Before authorization, an application-owned request UUID and prompt hash are persisted. The request stores backend action/execution IDs and an encrypted execution authorization for reconciliation; it stores no prompt or generated output. One consumption row covers the entire generation operation. No authorization/submission is automatically retried. Status polling is bounded. Pending/uncertain outcomes return a request reference and status URL; `GET /ai-governance/executions/{requestId}` is restricted to the original tenant, user and region and only reads backend status. It does not resubmit work or automatically apply late output to business records. Expired authorizations and lost submission responses require backend reconciliation using the recorded request/action IDs before another attempt.

### Tenant and regional usage tracking

Each region must use a credential registered to its intended Universal AI application/deployment. Reusing one deployment across regional portals also reuses its tenant namespace: the backend tenant key is SHA-256 of the canonical `[application_id, deployment_id, external_tenant_id]` array. The execution region describes the AI resource location, not the originating HRMS jurisdiction. Local ledger ownership always includes the authenticated tenant, user and HRMS region.

Universal AI's authorized execution-status response supplies persisted native provider usage. `HrmsAiClient` validates these counts, aggregates the non-overlapping cached/cache-creation/cache-read input categories with ordinary input, and forwards normalized input/output/total counts to the existing recorder. Missing usage remains unknown, not zero; malformed counts fail explicitly. Provider-reported usage on a failed execution is still retained.

An owner-scoped execution status check updates the existing request and usage rows atomically, including late/reconciled counts. It does not insert another consumption row or submit work. Repeated reads replace the same measured totals rather than adding them; a later response without usage does not erase previously recorded counts.

In Universal AI, select the regional customer account and open Usage. Rows identify application, deployment, external HRMS tenant, backend tenant, actor, module/service/action and execution region. Apply exact deployment/tenant filters to the server and page through results; the search box filters only the loaded page. Central consumed units are receipt-settled governance evidence, not a claim of finalized billing or business acceptance. Provider costs, commercial charges and legal invoices remain distinct. No completion approval, receipt settlement, invoice or billing permission is fabricated by HRMS usage tracking.

Universal AI also provides an explicit `maxx_uai.modules.platform_admin.deliver_receipts` command for trusted account-scoped M1-to-M2 receipt delivery. It defaults to read-only preview and requires a separately approved pinned machine identity with `receipts:deliver`; ordinary HRMS SDK credentials cannot settle receipts. `--apply` can change held funds and usage credits and must be operator-approved. Installing these tracking fixes does not start this command, settle existing requests or backfill old local token counts. See the Universal AI backend README for provisioning prerequisites and commands.

Run `php Modules/AiGovernance/Tools/install.php` in each regional application. The installer validates `in -> hrms_in`, `ae -> hrms_ae`, or `us -> hrms_us` and applies only this module's migration. Its permission is registered without granting it to ordinary users. No existing customer records are backfilled or changed. The usage migration retains evidence on rollback.

Generation is recorded where those features are available in the region. SDK submissions outside the HRMS adapter are also decorated; governance/status/billing reads are not consumption. The Maxx SDK remains disabled until separately provisioned and retains its local/testing restriction. This module does not create invoices or automatically approve business output.

Each provider call creates a pending record before sending, with server-owned tenant, actor and region. Missing scope, a wrong regional database, or unavailable ledger storage prevents the provider call. Completion records provider-reported token counts, model, duration and status. A transport exception is conservatively marked unknown; no retries are added. A pending record can also mean the worker stopped before it persisted the result. These statuses must be reconciled before retrying expensive work. Provider request completion is not a claim that the business output was approved.

The ledger stores no prompts, document bodies, response text, API keys, signed tokens, or raw exception messages. Counts start at installation, not historical usage. Unknown token counts stay null and are shown separately. Costs are not inferred from token counts; verified billing/invoice integration remains separate. Maxx SDK execution rows represent submissions, including caller-initiated replays, rather than an authoritative settled financial ledger. Streaming and future integrations must use the recorder explicitly and provide their usage metadata.

Queued assessment evaluation captures the dispatching tenant/user/region, validates the active actor again, establishes a temporary scope, and restores the worker's previous scope. Legacy queued jobs without ownership are refused. Provider calls are limited to one job attempt; do not auto-requeue uncertain outcomes.

Verification: `php vendor/phpunit/phpunit/phpunit tests/Feature/AiGovernanceTest.php` uses isolated in-memory SQLite and fake providers.
The fixture resets SDK opt-in and execution configuration so regional `.env` settings cannot activate a test connection or invalidate missing-configuration checks. Run `tests/Unit/AiGovernanceProviderRegistrationTest.php` alongside it to verify provider registration.

### Deployment and login startup

The regional provider explicitly registers the installed `MaxxAiServiceProvider` before
overriding SDK bindings, rather than assuming Composer package discovery already ran.
Registration is idempotent when Composer has discovered the package. Machine identity
configuration is resolved lazily: a missing signer binding fails explicitly when requested,
not during unrelated login/application startup. Production AI execution restrictions remain
unchanged.

Deploy the locked dependencies with `composer install --no-dev --prefer-dist --optimize-autoloader`
as the subscription/deployment user. Do not use `composer update` to repair a deployment.
Rebuild discovery with `php artisan package:discover --ansi`, then clear stale
configuration/routes/views using `php artisan config:clear`, `php artisan route:clear` and
`php artisan view:clear`. Rebuild configuration/route caches only as required by the
deployment's existing process. Restart the regional PHP-FPM pool through the hosting panel
if its OPcache retains the old provider.

Laravel also requires the actual regional PHP-FPM user to write to `storage` and
`bootstrap/cache`, including existing log/session/view files. For Plesk, identify the
domain's PHP-FPM pool user/group in Hosting Settings or the pool configuration; do not
assume it is `www-data`. After verifying the application directory, assign these two
directories to that user/group and grant owner/group write permission (commonly `ug+rwX`).
Do not use `chmod 777` or change ownership of the entire application. If permission errors
remain, inspect parent-directory traversal permissions, ACLs, filesystem read-only status
and the host's SELinux/AppArmor policy. Do not disable host security controls.

<!-- SDK-INTEGRATION-2026-10-27:START -->
## Current cross-project SDK guide (27 October 2026)

Use [the regional SDK integration guide](../../docs/SDK_INTEGRATION.md) for deployment ownership, version baselines, connection verification, request reconciliation, billing boundaries and troubleshooting. [The user manual](../../docs/user_manual.html#ai-governance-sdk-connection) describes the six-field setup workflow. Credential rotation in Universal AI revokes the old secret immediately; verify the replacement in the corresponding region. Local/test generation is implemented through `HrmsAiClient`; production remains blocked.
<!-- SDK-INTEGRATION-2026-10-27:END -->
