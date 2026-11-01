<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.4 — marketplace bonus aggregation (M.14=a).
 *
 * В marketplace режиме бонусы начисляются на клиентский заказ целиком (единый
 * источник Electrohub). Поле `client_order_id` nullable — в standard режиме
 * (Aladdin/Lastochka/Foodlink/Cron) всегда null и код идёт старым путём.
 *
 * @see docs/design-analysis/marketplace-model.md §5.1 M.14
 * @see memory/sync-core-feature-flag-pattern.md — миграции nullable + hasColumn guard
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bonus_transactions', 'client_order_id')) {
            return;
        }

        Schema::table('bonus_transactions', function (Blueprint $table): void {
            $table->uuid('client_order_id')->nullable()->after('order_id');
            $table->index('client_order_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bonus_transactions', 'client_order_id')) {
            return;
        }

        Schema::table('bonus_transactions', function (Blueprint $table): void {
            $table->dropIndex(['client_order_id']);
            $table->dropColumn('client_order_id');
        });
    }
};
