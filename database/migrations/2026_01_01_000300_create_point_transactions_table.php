<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loyalty_account_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->index();
            $table->integer('points');
            $table->integer('balance_after');
            $table->string('reason');
            $table->nullableMorphs('source');
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->timestamps();

            $table->index(['loyalty_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
    }
};
