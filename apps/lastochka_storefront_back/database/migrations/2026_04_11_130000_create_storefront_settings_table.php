<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('storefront_settings')) {
            Schema::create('storefront_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->string('type', 32)->default('string'); // string | json | int | boolean
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_settings');
    }
};
