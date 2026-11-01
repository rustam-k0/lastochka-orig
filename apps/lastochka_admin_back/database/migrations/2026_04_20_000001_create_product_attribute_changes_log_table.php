<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_attribute_changes_log')) {
            return;
        }

        Schema::create('product_attribute_changes_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('operation', 50);
            $table->json('payload');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('product_id');
            $table->index('operation');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_changes_log');
    }
};
