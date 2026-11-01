<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('shop_alert_rules')) {
            return;
        }

        Schema::create('shop_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Название правила, напр. «Падение среднего чека»');
            $table->string('metric_key', 64)->index()
                ->comment('Ключ метрики (согласуется с датасетами Superset)');
            $table->string('comparison', 8)->comment('lt | lte | gt | gte — метрика против порога');
            $table->decimal('threshold_value', 14, 2)->comment('Порог срабатывания');
            $table->text('recommended_action_text')->nullable()
                ->comment('Рекомендуемое действие для ленты сигналов');
            $table->foreignId('shop_id')->nullable()->constrained('shops')
                ->comment('NULL = правило для всех магазинов');
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_alert_rules');
    }
};
