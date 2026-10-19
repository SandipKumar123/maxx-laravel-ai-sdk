<?php

declare(strict_types=1);

use Maxx\Ai\Data\ActionContext;
use Maxx\Ai\Data\AuthorizationRequest;
use Maxx\Ai\GovernanceClient;

// Run inside a configured Laravel application, after its own user/tenant permission checks.
$client = app(GovernanceClient::class);
$context = new ActionContext(
    externalTenantId: 'tenant-local',
    actorRef: 'actor-local',
    moduleCode: 'hrms',
    serviceCode: 'policy',
    actionCode: 'interpret',
);
$result = $client->authorize(new AuthorizationRequest(
    context: $context,
    idempotencyKey: 'persisted-request-id',
    resourceId: 'dummy-resource',
    modelId: 'dummy-text',
    region: 'local',
    executorSubject: 'dis-service',
    disJobId: 'persisted-job-id',
));

// Persist the response securely. Do not log its signed authorization or dispatch on a timeout.
$actionId = $result->string('logical_action_id');
