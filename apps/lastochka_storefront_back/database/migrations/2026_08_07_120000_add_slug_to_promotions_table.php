<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * ЧПУ-слаг акции для ссылки со страницы акций/главной на страницу
     * конкретной акции, по аналогии со slug у product_groups.
     *
     * Backfill существующих акций из title (транслит ru); дальше слаг
     * приходит из admin_back (MerchantSale::boot() генерирует link,
     * публикуется как slug в MerchantSaleEventTransformer). Уникальность
     * обеспечивается кодом, не индексом — чтобы гонка консьюмера не роняла
     * событие в DLQ из-за UNIQUE-нарушения.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('promotions', 'slug')) {
            Schema::table('promotions', function (Blueprint $table) {
                $table->string('slug')->nullable()->after('title')->index();
            });
        }

        $taken = DB::table('promotions')
            ->whereNotNull('slug')
            ->pluck('slug')
            ->flip()
            ->all();

        DB::table('promotions')
            ->whereNull('slug')
            ->orderBy('id')
            ->select(['id', 'title'])
            ->chunkById(500, function ($promotions) use (&$taken) {
                foreach ($promotions as $promotion) {
                    $base = mb_substr(Str::slug((string) $promotion->title, '-', 'ru'), 0, 240);

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

                    DB::table('promotions')->where('id', $promotion->id)->update(['slug' => $slug]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('promotions', 'slug')) {
            Schema::table('promotions', function (Blueprint $table) {
                $table->dropColumn('slug');
            });
        }
    }
};
