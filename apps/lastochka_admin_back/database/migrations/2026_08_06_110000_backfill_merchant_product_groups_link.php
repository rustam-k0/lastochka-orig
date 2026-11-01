<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * MerchantProductGroup::boot() генерирует link только при create/update —
     * у уже существующих групп link остаётся NULL, пока их кто-то не
     * пересохранит в админке. Бэкфиллим тем же способом, что и
     * ModelTools::getUniqueLink() (Str::slug без явной локали — тот же
     * вызов, что и в самом trait), чтобы результат не разъезжался с тем,
     * что сгенерировалось бы при обычном сохранении. Уникальность —
     * глобальная (как и у getUniqueLink(), без scope по shop_id).
     */
    public function up(): void
    {
        $taken = DB::table('merchant_product_groups')
            ->whereNotNull('link')
            ->where('link', '!=', '')
            ->pluck('link')
            ->flip()
            ->all();

        DB::table('merchant_product_groups')
            ->where(fn ($q) => $q->whereNull('link')->orWhere('link', ''))
            ->orderBy('id')
            ->select(['id', 'title'])
            ->chunkById(500, function ($groups) use (&$taken) {
                foreach ($groups as $group) {
                    $base = Str::slug((string) $group->title);

                    if ($base === '') {
                        continue;
                    }

                    $link = $base;
                    $suffix = 2;
                    while (isset($taken[$link])) {
                        $link = $base . '-' . $suffix;
                        $suffix++;
                    }

                    $taken[$link] = true;

                    DB::table('merchant_product_groups')->where('id', $group->id)->update(['link' => $link]);
                }
            });
    }

    public function down(): void
    {
        // Не откатываем — необратимая нормализация данных.
    }
};
