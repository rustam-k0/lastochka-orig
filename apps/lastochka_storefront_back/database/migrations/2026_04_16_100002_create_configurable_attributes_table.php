<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configurable_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('attribute_code', 100);
            $table->string('attribute_name', 200);
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'attribute_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configurable_attributes');
    }
};
