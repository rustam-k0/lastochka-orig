<?php

declare(strict_types=1);

namespace App\Casts;

use App\DTOs\ObjectAddressData;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

final class ObjectAddressDataCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?ObjectAddressData
    {
        if ($value === null) {
            return null;
        }

        $raw = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($raw) || empty($raw['name'])) {
            return null;
        }

        return ObjectAddressData::fromArray($raw);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if ($value instanceof ObjectAddressData) {
            return [$key => json_encode($value->toArray(), JSON_UNESCAPED_UNICODE)];
        }

        if (is_array($value)) {
            if (empty($value['name'])) {
                throw new \InvalidArgumentException(
                    "Cannot cast array to ObjectAddressData: 'name' is required."
                );
            }
            $dto = ObjectAddressData::fromArray($value);

            return [$key => json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE)];
        }

        return [$key => null];
    }
}
