<?php

use Illuminate\Contracts\Console\Kernel;
use Spatie\Permission\PermissionRegistrar;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Apply only this module's migration, after checking the regional target.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$region = strtolower((string) config('app.jurisdiction'));
$expected = ['in' => 'hrms_in', 'ae' => 'hrms_ae', 'us' => 'hrms_us'][$region] ?? null;
if (! $expected || DB::connection()->getDatabaseName() !== $expected) {
    throw new RuntimeException('Refusing to install AI Governance outside its regional database.');
}
echo 'Installing AI Governance in '.$expected.PHP_EOL;
$code = Artisan::call('migrate', ['--path' => 'Modules/AiGovernance/Database/Migrations', '--force' => true]);
echo Artisan::output();
if ($code !== 0) {
    exit($code);
}
app(PermissionRegistrar::class)->forgetCachedPermissions();
echo 'Usage table: '.(Schema::hasTable('ai_governance_usage') ? 'ready' : 'missing').PHP_EOL;
