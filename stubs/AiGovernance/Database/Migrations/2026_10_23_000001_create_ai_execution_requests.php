<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_execution_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('region', 2);
            $table->string('module', 80);
            $table->string('action', 80);
            $table->string('payload_hash', 64);
            $table->string('logical_action_id')->nullable();
            $table->string('execution_id')->nullable();
            $table->text('authorization')->nullable();
            $table->string('status', 30);
            $table->timestamps();
            $table->index(['tenant_id', 'region', 'created_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('AI execution evidence must be retained.');
    }
};
