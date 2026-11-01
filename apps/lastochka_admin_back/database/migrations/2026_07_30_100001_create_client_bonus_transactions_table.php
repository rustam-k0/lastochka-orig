<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Зеркало журнала операций с баллами. Пишется двумя путями:
 * ручная операция создаётся мутацией в статусе pending, все остальные записи
 * приезжают фактом bonus_operation.created из storefront_back.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('client_bonus_transactions')) {
            return;
        }

        Schema::create('client_bonus_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('client_id')->index();
            $table->string('operation_id')->unique();
            $table->string('type', 20)->comment('earn|spend|refund|cancel');
            $table->unsignedInteger('amount');
            $table->unsignedInteger('balance_after')->nullable();
            $table->string('source', 20)->comment('manual|online|offline|initial');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('status', 20)->default('pending')->comment('pending|applied|rejected');
            $table->string('reject_reason', 50)->nullable();
            $table->text('reject_message')->nullable();
            $table->string('order_external_id')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_bonus_transactions');
    }
};
