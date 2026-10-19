<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maxx_ai_billing_inbox', function (Blueprint $table): void {
            $table->string('event_id', 255)->primary();
            $table->char('payload_hash', 64);
            $table->string('account_id', 255);
            $table->string('invoice_owner_id', 255);
            $table->string('invoice_reference', 255)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Billing inbox evidence is retained. Roll back package code, not financial evidence.');
    }
};
