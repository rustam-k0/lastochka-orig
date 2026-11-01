<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Колонка может уже существовать (миграция 2025_11_21_172400)
        if (!Schema::hasColumn('attributes', 'code')) {
            Schema::table('attributes', function (Blueprint $table) {
                $table->string('code', 100)->nullable()->after('title');
            });
        }

        // Заполняем коды для существующих атрибутов
        $codeMap = [
            'Белки' => 'proteins',
            'Жиры' => 'fats',
            'Углеводы' => 'carbs',
            'Ккал' => 'calories',
            'Состав' => 'composition',
            'Условия хранения' => 'storage_conditions',
            'Срок хранения' => 'shelf_life',
            'Вес брутто' => 'gross_weight',
            'Вес нетто' => 'net_weight',
            'Габариты' => 'dimensions',
        ];

        foreach ($codeMap as $title => $code) {
            DB::table('attributes')
                ->where('title', $title)
                ->whereNull('code')
                ->update(['code' => $code]);
        }
    }

    public function down(): void
    {
        DB::table('attributes')
            ->whereIn('code', ['proteins', 'fats', 'carbs', 'calories', 'composition', 'storage_conditions', 'shelf_life', 'gross_weight', 'net_weight', 'dimensions'])
            ->update(['code' => null]);
    }
};
