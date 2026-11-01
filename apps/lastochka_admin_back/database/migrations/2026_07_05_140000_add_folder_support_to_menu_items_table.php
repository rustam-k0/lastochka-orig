<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Поддержка папок-разделов в боковом меню: is_folder помечает строку
     * как некликабельный контейнер, parent_id группирует обычные пункты
     * внутри конкретной папки. Иерархия — строго два уровня: у папки
     * (is_folder=true) parent_id всегда null, у обычного пункта parent_id
     * может указывать только на строку с is_folder=true (проверяется
     * в резолверах мутаций, не на уровне БД).
     */
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->boolean('is_folder')->default(false)->after('accesses');
            $table->foreignId('parent_id')->nullable()->after('is_folder')
                ->constrained('menu_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'is_folder']);
        });
    }
};
