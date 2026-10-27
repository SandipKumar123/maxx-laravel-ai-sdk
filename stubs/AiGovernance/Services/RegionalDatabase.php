<?php

namespace Modules\AiGovernance\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

final class RegionalDatabase
{
    public static function connection(string $region): Connection
    {
        $database = DB::connection();
        $expected = ['in' => 'hrms_in', 'ae' => 'hrms_ae', 'us' => 'hrms_us'][$region] ?? null;
        if (! $expected || (! app()->environment('testing') && $database->getDatabaseName() !== $expected)) {
            throw new \LogicException('AI usage requires the configured regional database.');
        }

        return $database;
    }
}
