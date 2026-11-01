<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('delivery_cities')) {
            return;
        }

        Schema::create('delivery_cities', function (Blueprint $t) {
            $t->id();
            $t->string('name', 255);
            $t->string('slug', 255)->unique();
            $t->string('region', 255)->nullable();
            $t->string('zip', 16)->nullable();
            $t->decimal('lat', 10, 6)->nullable();
            $t->decimal('lng', 10, 6)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();

            $t->index('name');
            $t->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_cities');
    }
};
