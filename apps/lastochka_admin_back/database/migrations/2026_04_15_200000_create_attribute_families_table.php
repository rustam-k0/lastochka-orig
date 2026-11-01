<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attribute_families')) {
            return;
        }

        Schema::create('attribute_families', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('code', 100)->nullable()->unique();
            $table->boolean('is_system')->default(false);
            $table->integer('position')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_families');
    }
};
