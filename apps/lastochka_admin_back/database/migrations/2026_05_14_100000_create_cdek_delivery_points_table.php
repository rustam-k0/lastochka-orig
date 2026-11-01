<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cdek_delivery_points')) {
            return;
        }

        Schema::create('cdek_delivery_points', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();
            $t->string('name', 255)->nullable();
            $t->text('address')->nullable();
            $t->string('city', 255)->nullable();
            $t->string('region', 255)->nullable();
            $t->string('postal_code', 16)->nullable();
            $t->decimal('latitude', 10, 6)->nullable();
            $t->decimal('longitude', 10, 6)->nullable();
            $t->string('phone', 64)->nullable();
            $t->text('work_time')->nullable();
            $t->string('type', 16)->nullable();
            $t->boolean('have_cashless')->default(false);
            $t->boolean('have_cash')->default(false);
            $t->boolean('allowed_cod')->default(false);
            $t->boolean('is_handout')->default(true);
            $t->boolean('is_reception')->default(false);
            $t->boolean('accepts_parcels')->default(false);
            $t->boolean('is_active')->default(true);
            $t->longText('raw')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();

            $t->index(['latitude', 'longitude']);
            $t->index(['city', 'is_active']);
            $t->index('type');
            $t->index('postal_code');
            $t->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cdek_delivery_points');
    }
};
