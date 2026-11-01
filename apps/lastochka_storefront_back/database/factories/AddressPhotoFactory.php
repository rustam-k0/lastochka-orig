<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Address;
use App\Models\AddressPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddressPhoto>
 */
final class AddressPhotoFactory extends Factory
{
    protected $model = AddressPhoto::class;

    public function definition(): array
    {
        return [
            'address_id' => Address::factory(),
            'disk' => 'public',
            'path' => 'address-photos/'.$this->faker->uuid().'.jpg',
            'mime' => 'image/jpeg',
            'size' => $this->faker->numberBetween(50_000, 4_000_000),
            'sort_order' => 0,
        ];
    }
}
