<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('kpi_targets')) {
            return;
        }

        Schema::create('kpi_targets', function (Blueprint $table) {
            $table->id();
            $table->string('metric_key', 64)->index()
                ->comment('Ключ метрики (согласуется с датасетами Superset), напр. revenue, avg_check, gross_margin_pct');
            $table->foreignId('shop_id')->nullable()->constrained('shops')
                ->comment('NULL = вся сеть');
            $table->foreignId('category_id')->nullable()->constrained('categories')
                ->comment('NULL = без разреза по категории');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_value', 14, 2);
            $table->string('comment')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_targets');
    }
};
