<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Обязательные страницы витрины. Создаются черновиками с пустым
     * EditorJS-документом: админ наполняет текстом и публикует вручную.
     * Флаг is_system запрещает их удаление и смену кода — на эти коды
     * в мобильном приложении зашиты ссылки.
     */
    private const SYSTEM_PAGES = [
        'privacy-policy' => 'Политика конфиденциальности',
        'public-offer'   => 'Публичная оферта',
    ];

    public function up(): void
    {
        $position = (int) DB::table('pages')->max('position');

        foreach (self::SYSTEM_PAGES as $slug => $title) {
            if (DB::table('pages')->where('slug', $slug)->exists()) {
                continue;
            }

            $position++;

            DB::table('pages')->insert([
                'slug'         => $slug,
                'title'        => $title,
                'content'      => json_encode(['blocks' => []], JSON_UNESCAPED_UNICODE),
                'position'     => $position,
                'is_published' => 0,
                'is_system'    => 1,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('pages')
            ->whereIn('slug', array_keys(self::SYSTEM_PAGES))
            ->where('is_system', 1)
            ->delete();
    }
};
