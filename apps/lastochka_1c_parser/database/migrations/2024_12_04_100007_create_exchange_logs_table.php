<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->comment('catalog_import, offers_import, orders_export');
            $table->string('status', 20)->default('started')->comment('started, success, error');
            $table->text('message')->nullable();
            $table->integer('records_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_logs');
    }
};
