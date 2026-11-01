<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attribute_families') || !Schema::hasTable('attribute_family_attributes')) {
            return;
        }

        // Don't re-run if already migrated
        if (DB::table('attribute_families')->count() > 0) {
            return;
        }

        DB::transaction(function () {
            $now = now();

            // 1. System family "Состав" (category_type_id = 100)
            $compositionId = DB::table('attribute_families')->insertGetId([
                'title' => 'Состав',
                'code' => 'composition',
                'is_system' => true,
                'position' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $compositionAttrs = DB::table('attributes')
                ->where('category_type_id', 100)
                ->whereNull('deleted_at')
                ->orderBy('position')
                ->get();

            foreach ($compositionAttrs as $i => $attr) {
                DB::table('attribute_family_attributes')->insert([
                    'attribute_family_id' => $compositionId,
                    'attribute_id' => $attr->id,
                    'position' => $i,
                    'is_required' => false,
                    'is_configurable' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // 2. System family "Параметры" (category_type_id = 101)
            $paramsId = DB::table('attribute_families')->insertGetId([
                'title' => 'Параметры',
                'code' => 'parameters',
                'is_system' => true,
                'position' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $paramsAttrs = DB::table('attributes')
                ->where('category_type_id', 101)
                ->whereNull('deleted_at')
                ->orderBy('position')
                ->get();

            foreach ($paramsAttrs as $i => $attr) {
                DB::table('attribute_family_attributes')->insert([
                    'attribute_family_id' => $paramsId,
                    'attribute_id' => $attr->id,
                    'position' => $i,
                    'is_required' => false,
                    'is_configurable' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // 3. Transfer category_attributes → families
            if (!Schema::hasTable('category_attributes')) {
                return;
            }

            $categoryGroups = DB::table('category_attributes')
                ->whereNull('deleted_at')
                ->select('category_id')
                ->groupBy('category_id')
                ->get();

            // Group categories by their attribute set (same set = same family)
            $signatureToFamily = [];

            foreach ($categoryGroups as $group) {
                $attrIds = DB::table('category_attributes')
                    ->where('category_id', $group->category_id)
                    ->whereNull('deleted_at')
                    ->orderBy('attribute_id')
                    ->pluck('attribute_id')
                    ->toArray();

                if (empty($attrIds)) {
                    continue;
                }

                $signature = implode(',', $attrIds);

                if (!isset($signatureToFamily[$signature])) {
                    $familyId = DB::table('attribute_families')->insertGetId([
                        'title' => 'Семейство #' . (count($signatureToFamily) + 1),
                        'code' => null,
                        'is_system' => false,
                        'position' => count($signatureToFamily) + 2,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    foreach ($attrIds as $i => $attrId) {
                        DB::table('attribute_family_attributes')->insert([
                            'attribute_family_id' => $familyId,
                            'attribute_id' => $attrId,
                            'position' => $i,
                            'is_required' => false,
                            'is_configurable' => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    $signatureToFamily[$signature] = $familyId;
                }

                DB::table('categories')
                    ->where('id', $group->category_id)
                    ->update(['attribute_family_id' => $signatureToFamily[$signature]]);
            }
        });
    }

    public function down(): void
    {
        // No rollback needed — old tables are kept as fallback
    }
};
