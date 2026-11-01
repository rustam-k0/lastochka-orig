<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 10); // earn | spend | refund | cancel
            $table->unsignedInteger('amount'); // всегда > 0
            $table->unsignedInteger('balance_after');
            $table->string('description', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id');
            $table->index('order_id');
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_transactions');
    }
};
