<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('address_photos')) {
            return;
        }

        Schema::create('address_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path');
            $table->string('mime', 64);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['address_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_photos');
    }
};
