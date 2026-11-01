<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Backfill slug у категорий, созданных до того, как admin_back начал
     * публиковать его в событии category.* (см. CategoryEventTransformer
     * в admin_back и storefront_back). Дальше slug приходит из события
     * (там же генерируется автоматически из title при создании/редактировании
     * категории в админке — App\Models\Category::boot() в admin_back).
     *
     * Идемпотентно: трогает только категории без slug. Уникальность —
     * тем же способом, что и backfill products.slug (2026_08_03_100000):
     * in-memory set, без БД-констрейнта, чтобы гонка консьюмера не роняла
     * событие в DLQ из-за UNIQUE-нарушения.
     */
    public function up(): void
    {
        $taken = DB::table('categories')
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->pluck('slug')
            ->flip()
            ->all();

        DB::table('categories')
            ->where(function ($q) {
                $q->whereNull('slug')->orWhere('slug', '');
            })
            ->orderBy('id')
            ->select(['id', 'name'])
            ->chunkById(500, function ($categories) use (&$taken) {
                foreach ($categories as $category) {
                    $base = mb_substr(Str::slug((string) $category->name, '-', 'ru'), 0, 190);

                    if ($base === '') {
                        continue;
                    }

                    $slug = $base;
                    $suffix = 2;
                    while (isset($taken[$slug])) {
                        $slug = $base . '-' . $suffix;
                        $suffix++;
                    }

                    $taken[$slug] = true;

                    DB::table('categories')->where('id', $category->id)->update(['slug' => $slug]);
                }
            });
    }

    public function down(): void
    {
        // Не откатываем — необратимая нормализация данных (как и backfill products.slug).
    }
};
