<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AddressPhoto;
use App\Models\OrderAddress;
use Illuminate\Console\Command;

/**
 * Backfill для исторических snapshot'ов order_addresses.object_data.photos[*].id.
 *
 * Snapshot, созданный до фикса OrderObjectSnapshotter (commit a3918ab), не содержал
 * id фотографии — только url+mime. Эта команда проходит по всем order_addresses,
 * где в object_data.photos есть фото без id, и пытается сматчить id из address_photos
 * по полю path (path = всё после `/storage/` в url).
 *
 * Best-effort: если фото удалено (forceDelete) или url вне `/storage/` — id остаётся
 * null, команда не падает.
 *
 * Использование:
 *   php artisan client-orders:backfill-photo-ids                  # dry-run, только отчёт
 *   php artisan client-orders:backfill-photo-ids --apply          # реально записывает JSON
 *
 * Безопасность: перед --apply на prod обязательно сделать
 *   mysqldump --single-transaction --quick electrohub_storefront order_addresses
 */
final class BackfillClientOrderPhotoIdsCommand extends Command
{
    protected $signature = 'client-orders:backfill-photo-ids {--apply : Реально записать изменения}';

    protected $description = 'Backfill id фотографий в JSON-snapshot order_addresses.object_data.photos';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $mode = $apply ? 'APPLY' : 'DRY-RUN';

        $this->line("Режим: {$mode}");

        $scanned = 0;
        $touched = 0;
        $matched = 0;
        $stillMissing = 0;

        OrderAddress::query()
            ->whereNotNull('object_data')
            ->cursor()
            ->each(function (OrderAddress $oa) use (&$scanned, &$touched, &$matched, &$stillMissing, $apply): void {
                $scanned++;

                $data = $oa->object_data;
                if (! is_array($data) || empty($data['photos']) || ! is_array($data['photos'])) {
                    return;
                }

                $changed = false;
                $newPhotos = [];

                foreach ($data['photos'] as $photo) {
                    $photo = is_array($photo) ? $photo : [];

                    if (isset($photo['id']) && $photo['id'] !== null) {
                        $newPhotos[] = $photo;

                        continue;
                    }

                    $url = (string) ($photo['url'] ?? '');
                    $path = $this->extractStoragePath($url);
                    $foundId = null;

                    if ($path !== null) {
                        $foundId = AddressPhoto::query()
                            ->where('path', $path)
                            ->value('id');
                    }

                    if ($foundId !== null) {
                        $photo['id'] = (int) $foundId;
                        $matched++;
                        $changed = true;
                    } else {
                        $photo['id'] = null;
                        $stillMissing++;
                    }

                    $newPhotos[] = $photo;
                }

                if (! $changed) {
                    return;
                }

                $touched++;

                if ($apply) {
                    $oa->update(['object_data' => array_merge($data, ['photos' => $newPhotos])]);
                }
            });

        $this->info("Сканировано order_addresses:        {$scanned}");
        $this->info("С изменениями:                      {$touched}");
        $this->info("Photo.id найдено и проставлено:     {$matched}");
        $this->info("Photo без матча (id=null остаётся): {$stillMissing}");

        if (! $apply) {
            $this->warn('Запущено в dry-run. Для записи добавьте --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Достаёт relative-path хранилища из URL.
     *
     * Storage::disk('public')->url($path) возвращает `<scheme>://<host>/storage/<path>`.
     * Возвращаем `<path>` или null если url не содержит /storage/.
     */
    private function extractStoragePath(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $marker = '/storage/';
        $pos = strpos($url, $marker);

        if ($pos === false) {
            return null;
        }

        $path = substr($url, $pos + strlen($marker));

        return $path !== '' ? $path : null;
    }
}
