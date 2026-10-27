<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('regional_sdk_connections') && ! Schema::hasColumn('regional_sdk_connections', 'verified_at')) {
            Schema::table('regional_sdk_connections', function (Blueprint $table) {
                $table->timestamp('verified_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('regional_sdk_connections') && Schema::hasColumn('regional_sdk_connections', 'verified_at')) {
            Schema::table('regional_sdk_connections', function (Blueprint $table) {
                $table->dropColumn('verified_at');
            });
        }
    }
};
