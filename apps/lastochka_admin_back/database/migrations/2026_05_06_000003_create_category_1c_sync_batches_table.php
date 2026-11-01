<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('category_1c_sync_batches')) {
            return;
        }

        Schema::create('category_1c_sync_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->enum('status', ['PENDING', 'IN_PROGRESS', 'COMPLETED', 'FAILED'])
                ->default('PENDING');
            $table->integer('progress_total')->default(0);
            $table->integer('progress_done')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')
                ->references('id')->on('shops')
                ->onDelete('cascade');

            $table->index(['shop_id', 'status', 'created_at'], 'idx_shop_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_1c_sync_batches');
    }
};
