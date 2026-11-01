<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contragents')) {
            return;
        }
        Schema::create('contragents', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 64)->nullable()->index(); // GUID в 1С
            $table->string('loyalty_id', 64)->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('surname')->nullable();
            $table->string('patronymic')->nullable();
            $table->string('phone', 32)->nullable()->index();
            $table->string('email')->nullable();
            $table->date('birthday')->nullable();
            $table->string('gender', 8)->nullable();
            $table->integer('bonus_balance')->default(0);
            $table->unsignedBigInteger('shop_id')->nullable()->index();
            $table->timestamp('exported_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contragents');
    }
};
