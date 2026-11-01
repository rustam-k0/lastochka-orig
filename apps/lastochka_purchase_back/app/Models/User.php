<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @mixin IdeHelperUser
 */
class User extends Authenticatable implements MustVerifyEmail
{
  use HasApiTokens,
    HasRoles,
    SoftDeletes;

  protected static function booted(): void
  {
    static::addGlobalScope('onlyAdmin', function ($builder) {
      $builder->where(function ($query){
        $query->whereRelation('roles', 'name', Role::ADMIN)
          ->orWhereRelation('roles', 'name', Role::API)
          ->orWhereRelation('roles', 'name', Role::SHOP);

        // При включённой матрице доступов (role_access_matrix_enabled)
        // аутентифицируются также superadmin и кастомные (не системные)
        // роли global/shop-скоупа — например «Товаровед» с section.orders.
        // Системные collector/courier сюда не попадают.
        if (AdminSetting::getValue('role_access_matrix_enabled') === '1') {
          $query->orWhereRelation('roles', 'name', Role::SUPERADMIN)
            ->orWhereHas('roles', function ($q) {
              $q->where('is_system', false)
                ->whereIn('scope_type', [Role::SCOPE_GLOBAL, Role::SCOPE_SHOP]);
            });
        }
      });
    });
  }


  public function shops(): HasMany
  {
    return $this->hasMany(UserShop::class);
  }

  /**
   * Видит ли пользователь заказы всех магазинов (как админ). При включённой
   * матрице доступов — также superadmin и кастомные глобальные роли
   * (например «Товаровед»); shop-scope роли скоупятся своим магазином.
   */
  public function seesAllShopsOrders(): bool
  {
    if ($this->hasRole(Role::ADMIN)) {
      return true;
    }

    if (AdminSetting::getValue('role_access_matrix_enabled') !== '1') {
      return false;
    }

    return $this->roles()
      ->where('scope_type', Role::SCOPE_GLOBAL)
      ->where(function ($q) {
        $q->where('is_system', false)->orWhere('name', Role::SUPERADMIN);
      })
      ->exists();
  }

}
