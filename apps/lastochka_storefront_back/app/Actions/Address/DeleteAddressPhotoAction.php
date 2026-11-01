<?php

declare(strict_types=1);

namespace App\Actions\Address;

use App\Models\AddressPhoto;
use Illuminate\Support\Facades\Storage;

final class DeleteAddressPhotoAction
{
    public function execute(AddressPhoto $photo): void
    {
        $disk = $photo->disk;
        $path = $photo->path;

        // Soft-delete запись (для аудита)
        $photo->delete();

        // Физически удалить файл
        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }
}
