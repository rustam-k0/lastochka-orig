<?php

declare(strict_types=1);

namespace App\Actions\Address;

use App\Models\Address;

final class UpdateAddressAction
{
    /**
     * Обновляет адрес.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Address $address, array $data): Address
    {
        $wasObject = (bool) $address->is_object;

        $address->fill($data);
        $address->full_address = $address->buildFullAddress();
        $address->save();

        // При выключении is_object — чистим фото и object_data
        if ($wasObject && ! $address->is_object) {
            $deleter = app(\App\Actions\Address\DeleteAddressPhotoAction::class);
            foreach ($address->photos as $photo) {
                $deleter->execute($photo);
            }
            $address->object_data = null;
            $address->save();
        }

        return $address;
    }
}
