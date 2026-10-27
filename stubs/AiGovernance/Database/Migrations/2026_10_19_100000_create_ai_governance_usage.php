<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_governance_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('region', 2);
            $table->string('module', 80);
            $table->string('action', 80);
            $table->string('provider', 40);
            $table->string('model', 190)->nullable();
            $table->string('status', 20);
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_type', 100)->nullable();
            $table->string('correlation_id', 100)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'region', 'created_at'], 'ai_usage_tenant_region_date');
            $table->index(['tenant_id', 'region', 'user_id'], 'ai_usage_tenant_region_user');
        });
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->insertOrIgnore([
                'name' => 'ai-governance.index', 'guard_name' => 'web',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        throw new RuntimeException('AI consumption evidence is retained; roll back module code without deleting usage.');
    }
};
