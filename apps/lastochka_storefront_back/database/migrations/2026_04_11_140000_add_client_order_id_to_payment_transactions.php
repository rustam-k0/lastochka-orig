<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_transactions', 'client_order_id')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->uuid('client_order_id')->nullable()->after('order_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_transactions', 'client_order_id')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->dropIndex(['client_order_id']);
                $table->dropColumn('client_order_id');
            });
        }
    }
};
