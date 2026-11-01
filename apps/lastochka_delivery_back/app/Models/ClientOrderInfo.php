<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperClientOrderInfo
 */
class ClientOrderInfo extends Model
{
  use SoftDeletes;

  protected $fillable = [
    'order_id',
    'name',
    'surname',
    'phone',
    'email',
    'address',
    'latitude',
    'longitude',
    'first_order',
    'entrance',
    'intercom',
    'floor',
    'flat',
    'object_data',
  ];

  protected $casts = [
    'object_data' => 'array',
  ];

  /**
   * Camelize object_data for GraphQL output.
   *
   * @param  array<string, mixed>|string|null  $value
   * @return array<string, mixed>|null
   */
  public function getObjectDataAttribute($value): ?array
  {
    if (! $value) {
      return null;
    }

    $raw = is_array($value) ? $value : json_decode((string) $value, true);

    if (! is_array($raw) || empty($raw['name'])) {
      return null;
    }

    return [
      'name'             => $raw['name'],
      'contactName'      => $raw['contact_name']       ?? null,
      'contactPhone'     => $raw['contact_phone']      ?? null,
      'company'          => $raw['company']            ?? null,
      'liftSize'         => $raw['lift_size']          ?? null,
      'hasLift'          => $raw['has_lift']           ?? null,
      'needsPass'        => $raw['needs_pass']         ?? null,
      'canOversized'     => $raw['can_oversized']      ?? null,
      'workingHoursFrom' => $raw['working_hours_from'] ?? null,
      'workingHoursTo'   => $raw['working_hours_to']   ?? null,
      'is247'            => (bool) ($raw['is_24_7']    ?? false),
      'workingDays'      => $raw['working_days']       ?? [],
      'isDaily'          => (bool) ($raw['is_daily']   ?? false),
      'photos'           => array_map(
        fn ($p) => ['url' => $p['url'] ?? '', 'mime' => $p['mime'] ?? null],
        $raw['photos'] ?? [],
      ),
    ];
  }
}
