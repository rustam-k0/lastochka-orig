<?php

declare(strict_types=1);

namespace App\Actions\Address;

use App\Models\Address;
use App\Models\AddressPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class UploadAddressPhotoAction
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, AddressPhoto>
     */
    public function execute(Address $address, array $files): Collection
    {
        $disk = config('filesystems.address_photos_disk', 'public');
        $startSort = (int) ($address->photos()->max('sort_order') ?? 0);

        $photos = new Collection();

        foreach (array_values($files) as $i => $file) {
            $path = $file->store('address-photos/'.$address->id, $disk);

            $photo = $address->photos()->create([
                'disk' => $disk,
                'path' => $path,
                'mime' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize() ?: 0,
                'sort_order' => $startSort + $i + 1,
            ]);

            $photos->push($photo);
        }

        return $photos;
    }
}
