<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'stop_list_hidden')) {
                $table->boolean('stop_list_hidden')->default(false)->after('pickup_only')->comment('Стоп-лист «Не отображать»: товар полностью скрыт с витрины этого магазина');
            }
            if (!Schema::hasColumn('products', 'stop_list_pickup_only')) {
                $table->boolean('stop_list_pickup_only')->default(false)->after('stop_list_hidden')->comment('Стоп-лист «Не доставлять»: товар в этом магазине доступен только самовывозом');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['stop_list_hidden', 'stop_list_pickup_only']);
        });
    }
};
