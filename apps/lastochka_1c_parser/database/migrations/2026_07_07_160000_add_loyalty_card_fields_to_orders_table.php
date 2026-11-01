<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'card_number')) {
            return;
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('has_card')->default(false)->after('bonus_spent');
            $table->string('card_number', 16)->nullable()->after('has_card');
            $table->string('loyalty_id', 64)->nullable()->after('card_number');
            $table->timestamp('confirmed_published_at')->nullable()->after('loyalty_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('orders', 'card_number')) {
            return;
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['has_card', 'card_number', 'loyalty_id', 'confirmed_published_at']);
        });
    }
};
