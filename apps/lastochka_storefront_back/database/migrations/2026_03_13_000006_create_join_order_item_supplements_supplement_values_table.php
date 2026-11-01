<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('join_order_item_supplements_supplement_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_item_supplement_id');
            $table->unsignedBigInteger('supplement_value_id');
            $table->integer('amount')->nullable();

            $table->foreign('order_item_supplement_id', 'order_sup_val_item_sup_id_fk')
                ->references('id')->on('order_item_supplements')->cascadeOnDelete();
            $table->foreign('supplement_value_id', 'order_sup_val_value_id_fk')
                ->references('id')->on('supplement_values')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('join_order_item_supplements_supplement_values');
    }
};
