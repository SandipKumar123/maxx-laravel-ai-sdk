<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regional_sdk_connections', function (Blueprint $table) {
            $table->string('id', 16)->primary();
            $table->string('base_url', 2048);
            $table->text('access_token');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regional_sdk_connections');
    }
};
