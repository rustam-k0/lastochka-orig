<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attribute_family_attributes')) {
            return;
        }

        Schema::create('attribute_family_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_family_id')->constrained('attribute_families')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_configurable')->default(false);
            $table->timestamps();

            $table->unique(['attribute_family_id', 'attribute_id'], 'afa_family_attribute_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_family_attributes');
    }
};
