<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('rp_offices')) {
            return;
        }

        Schema::create('rp_offices', function (Blueprint $t) {
            $t->id();
            $t->string('postal_code', 6)->unique();
            $t->string('name', 255)->nullable();
            $t->text('address')->nullable();
            $t->string('settlement', 255)->nullable();
            $t->string('region', 255)->nullable();
            $t->decimal('latitude', 10, 6)->nullable();
            $t->decimal('longitude', 10, 6)->nullable();
            $t->string('phone', 64)->nullable();
            $t->text('work_time')->nullable();
            $t->string('ops_type', 64)->nullable();
            $t->boolean('accepts_parcels')->default(true);
            $t->boolean('is_handout')->default(true);
            $t->boolean('is_active')->default(true);
            $t->json('raw')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();

            $t->index(['latitude', 'longitude']);
            $t->index('settlement');
            $t->index('ops_type');
            $t->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rp_offices');
    }
};
