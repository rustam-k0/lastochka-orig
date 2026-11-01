<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Справочник причин уточнения товара при сборке (assembly_items.explain_reason_id).
     * Таблица создана в 2023 без is_active/position — добавляем поля,
     * чтобы справочник управлялся из админки как причины отмены.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('explain_reasons', 'is_active')) {
            Schema::table('explain_reasons', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('title');
            });
        }

        if (!Schema::hasColumn('explain_reasons', 'position')) {
            Schema::table('explain_reasons', function (Blueprint $table) {
                $table->integer('position')->nullable()->after('is_active');
            });
        }

        // Дефолтная причина — только при пустой таблице (идемпотентно).
        // На живых стендах запись уже есть из ExplainReasonSeeder delivery_back.
        if (DB::table('explain_reasons')->count() === 0) {
            $now = now();

            DB::table('explain_reasons')->insert([
                'title' => 'Упаковка товара повреждена',
                'is_active' => true,
                'position' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('explain_reasons', 'position')) {
            Schema::table('explain_reasons', function (Blueprint $table) {
                $table->dropColumn('position');
            });
        }

        if (Schema::hasColumn('explain_reasons', 'is_active')) {
            Schema::table('explain_reasons', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
