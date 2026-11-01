<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AddressPhoto;
use App\Models\OrderAddress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class CleanupOrphanAddressPhotosCommand extends Command
{
    protected $signature = 'addresses:cleanup-orphan-photos {--dry-run : Только показать, не удалять}';

    protected $description = 'Физически удаляет файлы фото soft-deleted адресов без активных ссылок в order_addresses';

    public function handle(): int
    {
        $orphans = AddressPhoto::onlyTrashed()
            ->whereHas('address', fn ($q) => $q->onlyTrashed())
            ->cursor();

        $deleted = 0;
        foreach ($orphans as $photo) {
            $url = Storage::disk($photo->disk)->url($photo->path);
            $referenced = OrderAddress::query()
                ->whereJsonContains('object_data->photos', ['url' => $url])
                ->exists();

            if ($referenced) {
                continue;
            }

            $this->line("Удаляю: {$photo->disk}:{$photo->path}");
            if (! $this->option('dry-run')) {
                Storage::disk($photo->disk)->delete($photo->path);
                $photo->forceDelete();
                $deleted++;
            }
        }

        $this->info("Готово. Удалено файлов: {$deleted}".($this->option('dry-run') ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
