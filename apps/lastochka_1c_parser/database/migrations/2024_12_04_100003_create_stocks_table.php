<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('warehouse_id', 36)->nullable()->comment('GUID склада из 1С');
            $table->string('warehouse_name', 255)->nullable();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
