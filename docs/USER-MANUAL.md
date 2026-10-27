# Laravel AI SDK: host integration and operator manual

Updated 27 October 2026. This describes the inspected local source and selected tests; it is not production acceptance.

## Ownership and request flow

| Component | Responsibility |
| --- | --- |
| Global HRMS (`maxx-hrms-platform`) | Business identity/routing and canonical commercial orchestration. It does not require an SDK dependency merely because the regional apps use AI. |
| India / USA / UAE | Business permissions, regional tenant and actor scope, prompts, output validation, human decisions and regional request/usage evidence. |
| `maxx-laravel-ai-sdk` (`maxx/laravel-ai-sdk`) | Typed governance/execution clients, server authentication, bounded transport, signed billing verification and a host billing inbox processor. |
| `maxx-universal-ai-platform` M2 | Registered action/policy authorization, reservations, settlement and governance billing events. |
| Universal AI M1 | Authorized provider execution, result/status retrieval and usage/cost evidence. |
| Universal AI DIS | Separate document-job domain; the current colocated local/test MVP handles text/native-text PDFs and human review. |

The HRMS execution path is `Regional business action -> HrmsAiClient -> SDK -> M2 authorize -> M1 submit/status -> Regional output validation and business handling`.
M1 submission uses the M2-issued execution authorization, never the customer SDK credential.
Provider model selection and token ceilings are governed by backend policy. The UI's saved resource/model/region/executor identifiers must match backend registration.

The regional `HrmsAiClient.php` and `RegionalAiExecutionGate.php` files are byte-identical across India, USA and UAE at this review.
Integrated call sites include resume parsing, campaign templates, recruitment/requisitions, notification suggestions, assessment questions and queued assessment evaluation. This is source evidence, not proof that every workflow has passed live acceptance.

## Regional administrator setup

1. Use an approved local/test environment. The SDK accepts only Laravel `local` or `testing` with explicit provisional opt-in. Universal AI accepts its enabled provisional runtimes only outside production. Do not relabel production to bypass these gates.
2. In Universal AI **Client Access**, register the real customer account, HRMS application and regional deployment. Issue a separate least-privilege credential for each deployment; record the one-time secret privately. Backend actions, policies, resource/model catalogue, budgets and funding must be provisioned separately.
3. Sign in to the intended regional HRMS and open **AI Governance -> SDK Connection**. Access requires active, matching tenant/region scope and `ai-governance.index`; managing the connection additionally requires the administrator/SDK-management authority enforced by `GovernanceAccess`.
4. Enter the Universal AI API origin, credential, Resource ID, Model ID, Execution region and Executor subject. Use the backend API origin, not the Global HRMS URL, regional portal URL or frontend UI address. HTTPS is required except for loopback HTTP.
5. Select **Verify with Universal AI**, then save the verified connection. The six submitted values are bound to the verification session; changing a value requires verification again. The check authenticates and reads scoped access; it does not generate AI, grant funds or prove an action is authorized.
6. Run one authorized low-impact test action and inspect **Request History**, **Usage Analytics**, and backend action/execution status. A saved verification timestamp proves a previous check only; re-verify after credential or backend changes.

The regional credential is encrypted in the regional database and is not displayed again. Credential rotation in Universal AI immediately revokes the old credential; update and verify the matching regional connection with the replacement. There is no guaranteed overlap period. If issuance is interrupted, reconcile/revoke inaccessible credentials before issuing another.

## Developer installation and configuration

The three regional Composer lockfiles resolve SDK revision `f2d811e63ef80b8ffd4242f64554a00c869e0c66` at this review. India's manifest uses `dev-main`; USA/UAE specify that revision. A lockfile preserves the installed resolution, but India's next dependency update can move it. Use a reviewed common immutable release for upgrades; do not edit `vendor` or silently substitute the SDK checkout.

The standalone SDK source reviewed here was at `e92a0f755e4a27082bb64dcb8cab4beb066e1626`; its tests do not certify the different regional locked revision. Requirements are PHP 8.2+, Laravel 12 and the package extensions. Confirm each host's actual runtime and lockfile before upgrades; Laravel 10/11 compatibility is not claimed.

Install approved locked dependencies with `composer install`, then rebuild discovery through `php artisan package:discover --ansi`. Publish/merge SDK configuration only when needed; do not overwrite a deployment's configuration or run `composer update` as a repair step.

Relevant local/test configuration names (real values belong in server configuration, never this guide):

```dotenv
MAXX_AI_ALLOW_PROVISIONAL=true
HRMS_AI_RESOURCE_ID=
HRMS_AI_MODEL_ID=
HRMS_AI_EXECUTION_REGION=
HRMS_AI_EXECUTOR_SUBJECT=
```

Saved, verified execution settings take precedence over the four HRMS environment defaults. The SDK environment fallback additionally uses `MAXX_AI_ENABLED`, `MAXX_AI_BASE_URL`, auth mode and registered identity/key settings; consult `config/maxx-ai.php`. Machine JWT mode and platform customer credentials are distinct authentication modes. Normal regional activation uses the verified connection workflow; setting an environment flag alone does not activate it.

Apply the reviewed regional AI Governance migrations to the selected regional database through the established deployment process, including the execution-request and execution-settings migrations dated 23 October 2026. No migrations or `.env` changes were made by this documentation update. Clear/rebuild caches and restart workers through the existing deployment process after an approved configuration/package change.

## Requests, uncertainty and usage

The adapter persists a request UUID and prompt hash before authorization. It records action/execution references and encrypted execution authorization for reconciliation; the request journal does not store prompt/output bodies. One usage record covers the operation. Domain modules may store accepted business output under their own rules.

There is no automatic authorization/submission retry. Polling is bounded. A pending/unknown result returns a request reference and status URL; do not repeatedly press Generate or requeue uncertain work. `/ai-governance/executions/{requestId}` is restricted to the originating tenant/user/region and reads status without resubmitting or automatically applying late output. Reconcile the recorded references with Universal AI before a new attempt.

Usage Analytics is operational evidence, not a settled wallet or legal invoice. Null token counts mean unknown, not zero. Keep regional usage records, the Global legacy AI-credit API, and M2's governed reservation/settlement ledger distinct; do not introduce a second spend authority or assume they reconcile automatically.

## Billing, documents and acceptance limits

Signed billing processing requires trusted backend public keys, inbox deduplication and a host-owned `InvoiceHandler` mapping verified events to the real invoice owner. The SDK fails explicitly without that binding. No such binding was found in the inspected HRMS application code; synthetic SDK billing tests are not customer/tax invoicing acceptance. Laravel retains legal invoices, tax, payments and human policy adoption.

Production C01-C08 contracts, identity integration, real HRMS billing receiver, recovery/operations rehearsals and owner acceptance remain outstanding. UAI-WF-03-B additionally requires governed DIS policy interpretation, source provenance, explicit human review/adoption, completion reporting and replay-safe invoice mapping; text generation alone does not complete it.

DIS currently supports bounded UTF-8 text/native-text PDF jobs in the host FastAPI process, disabled by default. OCR, Office formats, delegated-origin callbacks, independent production service/worker and end-to-end HRMS policy adoption remain outside the verified implementation. Do not describe the local MVP as approved C08 delivery.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Disabled/unavailable | Local/testing environment, explicit opt-in, saved enabled/verified connection and execution settings. Preserve production gates. |
| Verification fails | API origin, credential expiry/revocation, deployment registration, scope and backend availability. HTML or malformed responses are not verification. |
| Verified but execution denied | Registered service/module/action, tenant mapping, executor, resource/model/region, policies, budget and available funding. Verification is not funding. |
| Pending or unknown | Inspect the recorded request/action/execution and reconcile backend state before another submission. |
| Billing cannot process | Trusted signature keys, correct scope, inbox migration and a reviewed host `InvoiceHandler`; do not create duplicate invoice lines. |

Never copy credentials, machine private keys, signed authorizations, prompts, customer documents or raw provider errors into tickets, chat or logs.

## Verification recorded on 27 October 2026

- Standalone SDK Client/Contract/Security selection: **65 tests, 111 assertions passed**, with the local XAMPP OpenSSL config set for that test process.
- Universal AI backend unit suite: **191 passed** using the project virtual environment, bytecode disabled and pytest cache disabled.
- These runs did not exercise live HRMS-to-backend actions, production providers, PostgreSQL billing integration or rollout. Separately recorded integration experiments in the SDK README retain their own candidate/environment scope; they are not implied by these results.
