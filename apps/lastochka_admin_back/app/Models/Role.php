<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;

/**
 * @mixin IdeHelperRole
 */
class Role extends \Spatie\Permission\Models\Role
{
  use ModelTools;

  protected $table = self::table;
  const table = "roles";

  protected $guarded = [];

  protected $casts = [
    'is_system' => 'boolean',
  ];

  const ADMIN = "admin";
  const COLLECTOR = 'collector';
  const COURIER = 'courier';
  const API = 'api';
  const SHOP = 'shop';
  const SUPERADMIN = 'superadmin';

  const SCOPE_GLOBAL = 'global';
  const SCOPE_SHOP = 'shop';


  public function scopeNotApi($query)
  {
    return $query->where('name','!=',self::API);
  }

  public function scopeNotSuperAdmin($query)
  {
    return $query->where('name','!=',self::SUPERADMIN);
  }

  /**
   * Для партнёра (shop-scope) список назначаемых ролей ограничен сборщиком и курьером.
   * Для глобального админа — без ограничений.
   */
  public function scopeForAuthScope($query)
  {
    $authUser = \Auth::user();

    if ($authUser && \App\Support\PartnerStaffAccess::isPartner($authUser)) {
      $query->whereIn('name', \App\Support\PartnerStaffAccess::ALLOWED_ROLES);
    }
  }

}
