<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ELH-117: описание атрибута (INFO-модалка) и семантическая группа
     * (вкладки «Общие» / «Параметры упаковки» / «Состав») приезжают
     * из admin_back в payload атрибута (`description`, `group`).
     */
    public function up(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            if (! Schema::hasColumn('product_attributes', 'description')) {
                $table->text('description')->nullable()->after('value');
            }
            if (! Schema::hasColumn('product_attributes', 'group_code')) {
                $table->string('group_code', 32)->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            if (Schema::hasColumn('product_attributes', 'group_code')) {
                $table->dropColumn('group_code');
            }
            if (Schema::hasColumn('product_attributes', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
