<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attribute_values')) {
            return;
        }

        if (!Schema::hasColumn('attribute_values', 'code')) {
            Schema::table('attribute_values', function (Blueprint $table) {
                $table->string('code', 100)->nullable()->after('title');
            });

            $this->backfillCodes();

            Schema::table('attribute_values', function (Blueprint $table) {
                $table->unique(['attribute_id', 'code'], 'attribute_values_attr_code_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('attribute_values', 'code')) {
            Schema::table('attribute_values', function (Blueprint $table) {
                $table->dropUnique('attribute_values_attr_code_unique');
                $table->dropColumn('code');
            });
        }
    }

    private function backfillCodes(): void
    {
        $rows = DB::table('attribute_values')
            ->whereNull('code')
            ->orderBy('attribute_id')
            ->orderBy('id')
            ->get(['id', 'attribute_id', 'title']);

        $used = [];

        foreach ($rows as $row) {
            $attributeId = (int) $row->attribute_id;
            $base = Str::slug((string) $row->title, '_');
            if ($base === '') {
                $base = 'value_' . $row->id;
            }

            $code = $base;
            $counter = 2;
            while (isset($used[$attributeId][$code])) {
                $code = $base . '_' . $counter;
                $counter++;
            }

            $used[$attributeId][$code] = true;

            DB::table('attribute_values')->where('id', $row->id)->update(['code' => $code]);
        }
    }
};
