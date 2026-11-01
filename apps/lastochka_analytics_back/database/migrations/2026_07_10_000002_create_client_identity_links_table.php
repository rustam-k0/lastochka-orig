<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Склейка анонимного client_id устройства с user_id после авторизации
     * (identity stitching, контракт #11642).
     */
    public function up(): void
    {
        Schema::create('client_identity_links', function (Blueprint $table) {
            $table->id();
            $table->string('anon_client_id');
            $table->string('user_id');
            $table->timestampTz('linked_at');
            $table->unique(['anon_client_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_identity_links');
    }
};
