<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Каноничные коды для распространённых русских названий.
     * Покрывает то, что генерирует ProductEventTransformer::generateAttributeCode()
     * на лету, плюс ещё несколько популярных.
     */
    private const TITLE_TO_CODE = [
        'калорийность' => 'calories',
        'калории' => 'calories',
        'ккал' => 'calories',
        'энергетическая ценность' => 'calories',
        'белки' => 'proteins',
        'белок' => 'proteins',
        'жиры' => 'fats',
        'жир' => 'fats',
        'углеводы' => 'carbs',
        'углевод' => 'carbs',
        'состав' => 'composition',
        'условия хранения' => 'storage_conditions',
        'срок хранения' => 'shelf_life',
        'вес брутто' => 'gross_weight',
        'вес нетто' => 'net_weight',
        'габариты' => 'dimensions',
    ];

    public function up(): void
    {
        $used = DB::table('attributes')
            ->whereNotNull('code')
            ->pluck('code')
            ->map(fn ($code) => (string) $code)
            ->all();
        $used = array_flip($used);

        DB::table('attributes')
            ->whereNull('code')
            ->orderBy('id')
            ->get(['id', 'title'])
            ->each(function ($row) use (&$used) {
                $code = $this->resolveCode((string) $row->title, (int) $row->id, $used);
                $used[$code] = true;

                DB::table('attributes')->where('id', $row->id)->update(['code' => $code]);
            });

        Schema::table('attributes', function (Blueprint $table) {
            $table->string('code', 100)->nullable(false)->change();
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->string('code', 100)->nullable()->change();
        });
    }

    private function resolveCode(string $title, int $id, array $used): string
    {
        $lower = mb_strtolower(trim($title));
        $base = self::TITLE_TO_CODE[$lower] ?? Str::slug($title, '_');

        if ($base === '') {
            $base = 'attribute_' . $id;
        }

        $code = $base;
        $i = 2;
        while (isset($used[$code])) {
            $code = "{$base}_{$i}";
            $i++;
        }

        return $code;
    }
};
