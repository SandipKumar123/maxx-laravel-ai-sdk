<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regional_sdk_connections', function (Blueprint $table) {
            $table->string('resource_id')->nullable();
            $table->string('model_id')->nullable();
            $table->string('execution_region')->nullable();
            $table->string('executor_subject')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Regional AI connection settings are retained.');
    }
};
