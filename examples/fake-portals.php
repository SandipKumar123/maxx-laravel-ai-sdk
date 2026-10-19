<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Maxx\Ai\Data\ActionContext;
use Maxx\Ai\Data\AuthorizationRequest;
use Maxx\Ai\GovernanceClient;
use Maxx\Ai\Testing\FakeTransport;

foreach (['hrms', 'crm'] as $module) {
    $fake = (new FakeTransport())->enqueue(['logical_action_id' => $module.'-synthetic-action']);
    $client = new GovernanceClient($fake);
    $response = $client->authorize(new AuthorizationRequest(
        new ActionContext('tenant-local', 'actor-local', $module, 'policy', 'interpret'),
        $module.'-request', 'dummy-resource', 'dummy-text', 'local', $module.'-service',
    ));
    echo $module.': '.$response->string('logical_action_id').PHP_EOL;
}
