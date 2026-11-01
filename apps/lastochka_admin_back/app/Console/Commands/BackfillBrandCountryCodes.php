<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\CountryDirectory;
use Illuminate\Console\Command;

/**
 * Проставляет брендам коды стран по уже введённым названиям.
 *
 * До появления справочника страны вводились строкой руками, поэтому у части
 * брендов есть название, но нет кода. Команда идемпотентна: заполненные коды
 * не трогает, нераспознанные названия оставляет админу.
 *
 * Сохранение идёт через Eloquent, чтобы изменение уехало в storefront
 * через outbox — миграция с DB::table() событий не порождает.
 */
class BackfillBrandCountryCodes extends Command
{
    protected $signature = 'brands:backfill-country-codes {--dry-run : Показать, что будет изменено, без записи}';

    protected $description = 'Проставить бренду код страны (ISO 3166-1) по названию страны';

    public function handle(): int
    {
        $directory = new CountryDirectory();
        $dryRun = (bool) $this->option('dry-run');

        $fields = [
            'country_origin' => 'country_origin_code',
            'country_production' => 'country_production_code',
        ];

        $updated = 0;
        $unresolved = [];

        Brand::withTrashed()
            ->where(function ($query) {
                $query->whereNotNull('country_origin')
                    ->orWhereNotNull('country_production');
            })
            ->chunkById(100, function ($brands) use ($directory, $fields, $dryRun, &$updated, &$unresolved) {
                foreach ($brands as $brand) {
                    $changes = [];

                    foreach ($fields as $titleField => $codeField) {
                        $title = $brand->{$titleField};

                        if ($title === null || trim((string) $title) === '' || $brand->{$codeField} !== null) {
                            continue;
                        }

                        $code = $directory->codeByTitle((string) $title);

                        if ($code === null) {
                            $unresolved[] = sprintf('#%d %s: «%s»', $brand->id, $brand->title, $title);

                            continue;
                        }

                        $changes[$codeField] = $code;
                    }

                    if ($changes === []) {
                        continue;
                    }

                    $this->line(sprintf(
                        '#%d %s: %s',
                        $brand->id,
                        $brand->title,
                        collect($changes)->map(fn ($v, $k) => "$k = $v")->implode(', '),
                    ));

                    if (! $dryRun) {
                        $brand->update($changes);
                    }

                    $updated++;
                }
            });

        if ($unresolved !== []) {
            $this->newLine();
            $this->warn('Не удалось определить код страны (заполните вручную в админке):');
            foreach ($unresolved as $row) {
                $this->warn('  ' . $row);
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? sprintf('Dry-run: изменений было бы у %d бренда(ов).', $updated)
            : sprintf('Обновлено брендов: %d.', $updated));

        return self::SUCCESS;
    }
}
