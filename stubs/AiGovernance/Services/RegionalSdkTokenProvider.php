<?php

namespace Modules\AiGovernance\Services;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Maxx\Ai\Auth\MachineTokenProvider;
use Maxx\Ai\Auth\TokenProvider;
use Modules\AiGovernance\Models\RegionalSdkConnection;

final readonly class RegionalSdkTokenProvider implements TokenProvider
{
    public function __construct(private Container $container) {}

    public function token(): string
    {
        $connection = RegionalSdkConnection::query()->find('regional');

        if ($connection === null) {
            return $this->container->make(MachineTokenProvider::class)->token();
        }

        if (! $connection->enabled || ! is_string($connection->access_token) || trim($connection->access_token) === '') {
            throw new LogicException('The regional Maxx AI connection is disabled or incomplete.');
        }

        return $connection->access_token;
    }
}
