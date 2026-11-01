<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['one', 'many'])->default('one');
            $table->boolean('is_required')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplements');
    }
};
