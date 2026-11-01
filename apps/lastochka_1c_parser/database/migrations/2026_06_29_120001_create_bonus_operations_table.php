<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('bonus_operations')) {
            return;
        }
        Schema::create('bonus_operations', function (Blueprint $table) {
            $table->id();
            $table->string('operation_id', 64)->unique();
            $table->string('loyalty_id', 64)->index();
            $table->string('type', 16);                 // earn|spend|refund|cancel|initial
            $table->integer('amount');
            $table->string('source', 16);               // online|offline|manual|initial
            $table->string('order_external_id', 64)->nullable();
            $table->string('direction', 24);            // inbound_from_1c | outbound_to_1c
            $table->string('status', 16)->default('pending'); // pending|exported|applied|failed
            $table->json('payload')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_operations');
    }
};
