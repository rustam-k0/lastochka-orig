<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('store_operating_costs')) {
            return;
        }

        Schema::create('store_operating_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained('shops')
                ->comment('NULL = правило для всей сети');
            $table->string('category', 32)->comment('rent | payroll | marketing | other');
            $table->string('rule_type', 16)->comment('fixed_amount (сумма ₽/мес) | pct_of_revenue (% от оборота)');
            $table->decimal('value', 14, 2)->comment('Сумма ₽ либо доля % — по rule_type');
            $table->date('period_start')->comment('Начало действия правила');
            $table->date('period_end')->nullable()->comment('Конец действия; NULL = бессрочно');
            $table->string('comment')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_operating_costs');
    }
};
