<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * ЧПУ-слаг товарной группы для ссылок со сторителла/главной на страницу
     * группы, по аналогии со slug у products и categories.
     *
     * Backfill существующих групп из title (транслит ru); дальше слаг
     * приходит из admin_back (MerchantProductGroup::boot() генерирует link,
     * публикуется как slug в MerchantProductGroupEventTransformer). Уникальность
     * обеспечивается кодом, не индексом — чтобы гонка консьюмера не роняла
     * событие в DLQ из-за UNIQUE-нарушения.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('product_groups', 'slug')) {
            Schema::table('product_groups', function (Blueprint $table) {
                $table->string('slug')->nullable()->after('title')->index();
            });
        }

        // Идемпотентно: обновляем только группы без слага.
        $taken = DB::table('product_groups')
            ->whereNotNull('slug')
            ->pluck('slug')
            ->flip()
            ->all();

        DB::table('product_groups')
            ->whereNull('slug')
            ->orderBy('id')
            ->select(['id', 'title'])
            ->chunkById(500, function ($groups) use (&$taken) {
                foreach ($groups as $group) {
                    $base = mb_substr(Str::slug((string) $group->title, '-', 'ru'), 0, 240);

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

                    DB::table('product_groups')->where('id', $group->id)->update(['slug' => $slug]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_groups', 'slug')) {
            Schema::table('product_groups', function (Blueprint $table) {
                $table->dropColumn('slug');
            });
        }
    }
};
