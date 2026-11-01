<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * ЧПУ-слаг товара для ссылок на карточку с фронта витрины.
     *
     * Backfill существующих товаров из title (транслит ru); дальше слаг
     * генерируется при создании товара (ProductSlugGenerator). Уникальность
     * обеспечивается кодом, не индексом — чтобы гонка консьюмера не роняла
     * событие в DLQ из-за UNIQUE-нарушения.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'slug')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('slug')->nullable()->after('title')->index();
            });
        }

        // Идемпотентно: обновляем только товары без слага (включая soft-deleted,
        // чтобы restore не оживил товар с NULL-слагом).
        $taken = DB::table('products')
            ->whereNotNull('slug')
            ->pluck('slug')
            ->flip()
            ->all();

        DB::table('products')
            ->whereNull('slug')
            ->orderBy('id')
            ->select(['id', 'title'])
            ->chunkById(500, function ($products) use (&$taken) {
                foreach ($products as $product) {
                    $base = mb_substr(Str::slug((string) $product->title, '-', 'ru'), 0, 240);

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

                    DB::table('products')->where('id', $product->id)->update(['slug' => $slug]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'slug')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('slug');
            });
        }
    }
};
