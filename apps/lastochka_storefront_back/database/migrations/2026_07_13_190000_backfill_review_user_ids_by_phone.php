<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ALADDIN-11937: отзывы, созданные в админке, приходили с user_id = NULL —
 * клиент не видел «свой» отзыв (GET .../reviews/my) и мог создать дубль.
 * Привязываем существующие отзывы к пользователям по author_phone там,
 * где это не нарушает UNIQUE(user_id, store_id/product_id):
 * - индекс учитывает и trashed-строки — конфликт проверяем без deleted_at;
 * - из группы NULL-отзывов одного телефона на одну сущность привязываем
 *   только один (MIN(id)) — второй нарушил бы UNIQUE;
 * - телефон маппится на MIN(users.id) на случай дублей номеров.
 */
return new class extends Migration
{
    public function up(): void
    {
        // UPDATE ... JOIN — MySQL-синтаксис; на SQLite (тесты) данных для
        // backfill нет, пропускаем
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE store_reviews sr
            JOIN (SELECT MIN(id) AS id, phone FROM users GROUP BY phone) u
                ON u.phone = sr.author_phone
            SET sr.user_id = u.id
            WHERE sr.user_id IS NULL
              AND sr.author_phone IS NOT NULL
              AND sr.id = (
                  SELECT MIN(x.id) FROM (SELECT id, author_phone, store_id, user_id FROM store_reviews) x
                  WHERE x.author_phone = sr.author_phone AND x.store_id = sr.store_id AND x.user_id IS NULL
              )
              AND NOT EXISTS (
                  SELECT 1 FROM (SELECT user_id, store_id FROM store_reviews) other
                  WHERE other.user_id = u.id AND other.store_id = sr.store_id
              )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE product_reviews pr
            JOIN (SELECT MIN(id) AS id, phone FROM users GROUP BY phone) u
                ON u.phone = pr.author_phone
            SET pr.user_id = u.id
            WHERE pr.user_id IS NULL
              AND pr.author_phone IS NOT NULL
              AND pr.id = (
                  SELECT MIN(x.id) FROM (SELECT id, author_phone, product_id, user_id FROM product_reviews) x
                  WHERE x.author_phone = pr.author_phone AND x.product_id = pr.product_id AND x.user_id IS NULL
              )
              AND NOT EXISTS (
                  SELECT 1 FROM (SELECT user_id, product_id FROM product_reviews) other
                  WHERE other.user_id = u.id AND other.product_id = pr.product_id
              )
        SQL);
    }

    public function down(): void
    {
        // Данные-фикс, откат не предусмотрен
    }
};
