@extends('admin.layouts.base')
@php
    $pages = [
        'overview' => ['label' => 'Overview', 'route' => 'ai-governance.index'],
        'analytics' => ['label' => 'Usage Analytics', 'route' => 'ai-governance.analytics'],
        'requests' => ['label' => 'Request History', 'route' => 'ai-governance.requests'],
        'sdk' => ['label' => 'SDK Connection', 'route' => 'ai-governance.sdk'],
    ];
    $title = $pages[$page]['label'];
@endphp
@section('title', $title.' | AI Governance')
@section('content')
<section class="container-fluid py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-shield-check me-2"></i>AI Governance · {{ $title }}</h1>
            <p class="text-muted mb-0">AI consumption for your tenant · {{ ['in' => 'India', 'ae' => 'UAE', 'us' => 'USA'][$identity->region] }}</p>
        </div>
        <span class="badge text-bg-light border p-2">Tenant #{{ $identity->tenantId }}</span>
    </div>

    <nav class="nav nav-pills flex-wrap gap-2 mb-4" aria-label="AI Governance pages">
        @foreach($pages as $key => $item)
            <a class="nav-link {{ $page === $key ? 'active' : '' }}" href="{{ route($item['route'], $filters) }}"
                @if($page === $key) aria-current="page" @endif>{{ $item['label'] }}</a>
        @endforeach
    </nav>

    @if(in_array($page, ['analytics', 'requests'], true))
        <form method="get" action="{{ route($pages[$page]['route']) }}" class="card card-body mb-4">
            <div class="row g-3 align-items-start">
                <div class="col-sm-6 col-lg-2">
                    <label for="ai-from" class="form-label">From (UTC)</label>
                    <input id="ai-from" type="text" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control ai-governance-date"
                        placeholder="Start date (YYYY-MM-DD)" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}" maxlength="10" autocomplete="off" aria-describedby="ai-from-help">
                    <small id="ai-from-help" class="form-text">Optional. Example: 2026-10-01</small>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="ai-to" class="form-label">To (UTC)</label>
                    <input id="ai-to" type="text" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control ai-governance-date"
                        placeholder="End date (YYYY-MM-DD)" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}" maxlength="10" autocomplete="off" aria-describedby="ai-to-help">
                    <small id="ai-to-help" class="form-text">Optional. Includes the selected day.</small>
                </div>
                <div class="col-sm-6 col-lg-2"><label for="ai-user" class="form-label">User</label><select id="ai-user" name="user_id" class="form-select"><option value="">Choose user (all tenant users)</option>@foreach($users as $id => $name)<option value="{{ $id }}" @selected((string)($filters['user_id'] ?? '') === (string)$id)>{{ $name }} (#{{ $id }})</option>@endforeach</select></div>
                <div class="col-sm-6 col-lg-2"><label for="ai-module" class="form-label">Module</label><select id="ai-module" name="module" class="form-select"><option value="">Choose module (all modules)</option>@foreach($modules as $module)<option @selected(($filters['module'] ?? '') === $module)>{{ $module }}</option>@endforeach</select></div>
                <div class="col-sm-6 col-lg-2"><label for="ai-provider" class="form-label">Provider</label><select id="ai-provider" name="provider" class="form-select"><option value="">Choose provider (all providers)</option>@foreach(['openai' => 'OpenAI', 'maxx-ai' => 'Maxx AI'] as $key => $label)<option value="{{ $key }}" @selected(($filters['provider'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-sm-6 col-lg-2"><label for="ai-status" class="form-label">Status</label><select id="ai-status" name="status" class="form-select"><option value="">Choose status (all statuses)</option>@foreach(['pending', 'completed', 'failed', 'unknown'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Apply filters</button>
                    <a class="btn btn-outline-secondary" href="{{ route($pages[$page]['route']) }}">Reset</a>
                </div>
            </div>
            @if($errors->any())<div class="text-danger mt-3" role="alert">{{ $errors->first() }}</div>@endif
        </form>
    @endif

    @if($page === 'overview' || $page === 'analytics')
        @if($page === 'overview')
            <div class="alert alert-light border small">
                Tracking starts when this module is installed. Token totals include only provider-reported usage; costs are not estimated.
                Failed or unresolved requests may still have consumed tokens.
            </div>
        @endif
        <div class="row g-3 mb-4">
            @foreach(['Requests' => $summary->requests, 'Reported tokens' => $summary->tokens, 'Usage not reported' => $summary->unreported ?? 0, 'Failed requests' => $summary->failed ?? 0, 'Pending / unknown' => $summary->unresolved ?? 0] as $label => $value)
                <div class="col-sm-6 col-lg"><div class="card h-100"><div class="card-body"><div class="text-muted small mb-2">{{ $label }}</div><div class="h3 mb-0">{{ number_format($value) }}</div></div></div></div>
            @endforeach
        </div>
    @endif

    @if($page === 'overview')
        <div class="row g-3">
            <div class="col-md-4"><a class="card h-100 text-decoration-none" href="{{ route('ai-governance.analytics') }}"><div class="card-body"><h2 class="h5">Usage Analytics</h2><p class="text-muted mb-0">Review consumption by module and user, with date and status filters.</p></div></a></div>
            <div class="col-md-4"><a class="card h-100 text-decoration-none" href="{{ route('ai-governance.requests') }}"><div class="card-body"><h2 class="h5">Request History</h2><p class="text-muted mb-0">Inspect individual AI requests, outcomes, token counts, and duration.</p></div></a></div>
            <div class="col-md-4"><a class="card h-100 text-decoration-none" href="{{ route('ai-governance.sdk') }}"><div class="card-body"><h2 class="h5">SDK Connection</h2><p class="text-muted mb-0">Review Maxx AI setup and run an explicit connection check.</p></div></a></div>
        </div>
    @endif

    @if($page === 'analytics')
        <div class="row g-4">
            <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-transparent"><h2 class="h6 mb-0">Consumption by module</h2></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th scope="col">Module</th><th scope="col">Requests</th><th scope="col">Reported tokens</th></tr></thead><tbody>@forelse($byModule as $item)<tr><td>{{ $item->module }}</td><td>{{ number_format($item->requests) }}</td><td>{{ number_format($item->tokens) }}</td></tr>@empty<tr><td colspan="3" class="text-muted p-4">No consumption in this selection.</td></tr>@endforelse</tbody></table></div></div></div>
            <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-transparent"><h2 class="h6 mb-0">Consumption by user</h2></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th scope="col">User</th><th scope="col">Requests</th><th scope="col">Reported tokens</th></tr></thead><tbody>@forelse($byUser as $item)<tr><td>{{ $users[$item->user_id] ?? 'Former tenant user' }} (#{{ $item->user_id }})</td><td>{{ number_format($item->requests) }}</td><td>{{ number_format($item->tokens) }}</td></tr>@empty<tr><td colspan="3" class="text-muted p-4">No consumption in this selection.</td></tr>@endforelse</tbody></table></div><div class="px-3">{{ $byUser->links() }}</div></div></div>
        </div>
    @endif

    @if($page === 'requests')
        <div class="card">
            <div class="card-header bg-transparent"><h2 class="h6 mb-0">Request history</h2></div>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead><tr><th scope="col">Time (UTC)</th><th scope="col">User</th><th scope="col">Module / action</th><th scope="col">Provider / model</th><th scope="col">Status</th><th scope="col">Input</th><th scope="col">Output</th><th scope="col">Total tokens</th><th scope="col">Duration</th></tr></thead>
                <tbody>@forelse($records as $record)
                    <tr><td class="text-nowrap">{{ $record->created_at }}</td><td>{{ $users[$record->user_id] ?? 'Former tenant user' }}<small class="d-block text-muted">#{{ $record->user_id }}</small></td><td>{{ $record->module }}<small class="d-block text-muted">{{ $record->action }}</small></td><td>{{ $record->provider }}<small class="d-block text-muted">{{ $record->model ?? 'Not reported' }}</small></td><td><span class="badge {{ ['completed' => 'text-bg-success', 'failed' => 'text-bg-danger', 'pending' => 'text-bg-secondary', 'unknown' => 'text-bg-warning'][$record->status] ?? 'text-bg-secondary' }}">{{ ucfirst($record->status) }}</span></td><td>{{ $record->input_tokens === null ? 'Not reported' : number_format($record->input_tokens) }}</td><td>{{ $record->output_tokens === null ? 'Not reported' : number_format($record->output_tokens) }}</td><td>{{ $record->total_tokens === null ? 'Not reported' : number_format($record->total_tokens) }}</td><td class="text-nowrap">{{ $record->duration_ms === null ? '—' : number_format($record->duration_ms).' ms' }}</td></tr>
                @empty<tr><td colspan="9" class="text-center p-5 text-muted">No AI requests recorded for your tenant in this selection.</td></tr>@endforelse</tbody>
            </table></div>
            <div class="p-3">{{ $records->links() }}</div>
        </div>
    @endif

    @if($page === 'sdk')
        <div class="card card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h2 class="h5 mb-2">Maxx AI SDK connection</h2>
                    <span class="badge text-bg-light border mb-2">{{ ['disabled' => 'Not configured', 'blocked' => 'Environment restricted', 'configuration_required' => 'Setup required', 'ready' => 'Ready to check', 'verified' => 'Saved and verified'][$sdk['state']] ?? 'Setup required' }}</span>
                    <p class="text-muted mb-0">{{ $sdk['message'] }}</p>
                </div>
            </div>
            @if($canManageSdk)
                @if(session('status'))<div class="alert alert-success mt-3 mb-0" role="status">{{ session('status') }}</div>@endif
                <form id="regional-sdk-connection-form" method="post" action="{{ route('ai-governance.connection-save') }}" class="border rounded p-3 mt-3">
                    @csrf
                    <h3 class="h6">Universal AI Client Access</h3>
                    <p class="small text-muted">Enter the credential issued by Universal AI for this regional deployment. Verify it before saving; it is stored encrypted and never displayed again. Issue a separate credential for each region. A verified key confirms access only; AI generation remains blocked until production-ready Universal AI SDK execution is available.</p>
                    <div class="mb-3">
                        <label for="maxx-ai-url" class="form-label">Universal AI base URL</label>
                        <input id="maxx-ai-url" name="base_url" type="url" class="form-control" required maxlength="2048"
                            value="{{ old('base_url', $sdkConfiguration['base_url']) }}" placeholder="https://ai.example.com">
                        @error('base_url')<div class="text-danger small" role="alert">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="maxx-ai-token" class="form-label">Universal AI SDK credential</label>
                        <input id="maxx-ai-token" name="access_token" type="password" class="form-control" required maxlength="128"
                            autocomplete="new-password" placeholder="maxx_sdk_…">
                        @error('access_token')<div class="text-danger small" role="alert">{{ $message }}</div>@enderror
                    </div>
                    <h3 class="h6 mt-4">Regional execution settings</h3>
                    <p class="small text-muted">Enter the exact values registered in Universal AI for this regional deployment. Connection verification confirms access; execution also requires the corresponding action permissions and resource availability.</p>
                    <div class="row">
                        @foreach(['resource_id' => 'Resource ID', 'model_id' => 'Model ID', 'execution_region' => 'Execution region', 'executor_subject' => 'Executor subject'] as $field => $label)
                            <div class="col-md-6 mb-3">
                                <label for="maxx-ai-{{ $field }}" class="form-label">{{ $label }}</label>
                                <input id="maxx-ai-{{ $field }}" name="{{ $field }}" class="form-control" required maxlength="255"
                                    pattern="[A-Za-z0-9_.:\-]+" autocomplete="off"
                                    value="{{ old($field, $sdkConfiguration['execution'][$field === 'execution_region' ? 'region' : $field] ?? '') }}">
                                @error($field)<div class="text-danger small" role="alert">{{ $message }}</div>@enderror
                            </div>
                        @endforeach
                    </div>
                    <div id="regional-sdk-verification-result" class="small mb-3" role="status" aria-live="polite"></div>
                    <button type="submit" name="connection_action" value="verify" formaction="{{ route('ai-governance.connection-check') }}"
                        class="btn btn-outline-primary">Verify with Universal AI</button>
                    <button id="regional-sdk-save" type="submit" name="connection_action" value="save" class="btn btn-primary ms-2"
                        @disabled(!(is_array(session('ai_sdk_check')) && (session('ai_sdk_check.state') === 'connected')))>Save verified connection</button>
                </form>
            @else
                <p class="small text-muted mt-3 mb-0">A regional administrator must configure and verify this deployment’s connection.</p>
            @endif
            @php($connectionCheck = session('ai_sdk_check'))
            @if(is_array($connectionCheck) && ($connectionCheck['tenant_id'] ?? null) === $identity->tenantId && ($connectionCheck['region'] ?? null) === $identity->region)
                <div class="alert {{ $connectionCheck['state'] === 'connected' ? 'alert-success' : 'alert-warning' }} mt-3 mb-0" role="status">
                    {{ $connectionCheck['message'] }}
                    <small class="d-block">Checked at {{ $connectionCheck['checked_at'] }} (UTC)</small>
                </div>
            @endif
            <p class="small text-muted mt-4 mb-0">Verification confirms tenant-scoped access without submitting prompts, employee records or AI work. Generation requires a verified connection, complete execution settings and backend authorization. The installed SDK permits explicitly enabled local/testing environments; production requires a supported release. Direct provider fallbacks are not used.</p>
        </div>
    @endif
</section>
@endsection
@if($page === 'sdk' && $canManageSdk)
    @push('scripts')
    <script>
        (function () {
            var form = document.getElementById('regional-sdk-connection-form');
            if (!form || !window.fetch) return;
            var verifyButton = form.querySelector('[value="verify"]');
            var saveButton = document.getElementById('regional-sdk-save');
            var result = document.getElementById('regional-sdk-verification-result');

            verifyButton.addEventListener('click', function (event) {
                event.preventDefault();
                if (!form.reportValidity()) return;
                saveButton.disabled = true;
                result.textContent = 'Verifying credentials with Universal AI…';
                fetch(verifyButton.formAction, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
                }).then(function (response) {
                    return response.json().then(function (data) {
                        return {ok: response.ok, data: data};
                    });
                }).then(function (response) {
                    var errors = response.data.errors ? Object.values(response.data.errors).flat().join(' ') : '';
                    result.textContent = response.data.message || errors || 'Unable to verify the connection.';
                    result.className = 'small mb-3 ' + (response.ok && response.data.state === 'connected' ? 'text-success' : 'text-danger');
                    saveButton.disabled = !(response.ok && response.data.state === 'connected');
                }).catch(function () {
                    result.textContent = 'Unable to verify the connection. Check the URL and try again.';
                    result.className = 'small mb-3 text-danger';
                });
            });

            form.querySelectorAll('input').forEach(function (input) {
                input.addEventListener('input', function () {
                    saveButton.disabled = true;
                    result.textContent = '';
                });
            });
        })();
    </script>
    @endpush
@endif
@if(in_array($page, ['analytics', 'requests'], true))
    @push('scripts')
    <script>
        if (typeof window.flatpickr === 'function') {
            document.querySelectorAll('.ai-governance-date').forEach(function (input) {
                window.flatpickr(input, {dateFormat: 'Y-m-d', allowInput: true, disableMobile: true});
            });
        }
    </script>
    @endpush
@endif
