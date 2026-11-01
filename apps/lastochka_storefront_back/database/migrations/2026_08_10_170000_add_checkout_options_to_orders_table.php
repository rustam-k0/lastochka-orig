<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'checkout_options')) {
                $table->json('checkout_options')->nullable()->after('comment')
                    ->comment('Снапшот выбранных опций чекаута: [{code, label}] (MENDEV-17)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'checkout_options')) {
                $table->dropColumn('checkout_options');
            }
        });
    }
};
