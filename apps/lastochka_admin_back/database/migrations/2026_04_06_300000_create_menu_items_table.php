<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('menu_items')) {
            return;
        }

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('title', 255);
            $table->string('icon', 100)->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('accesses');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
