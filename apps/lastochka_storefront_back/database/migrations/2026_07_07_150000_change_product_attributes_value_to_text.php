<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `1406 Data too long for column 'value'`: составы из 1С (реквизит «Состав»)
 * достигают 800-1200+ символов и не влезали в varchar(500) — insert падал,
 * событие product.updated целиком уходило в ретраи/DLQ. В индексах value
 * не участвует, расширение до TEXT безопасно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->text('value')->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->string('value', 500)->change();
        });
    }
};
