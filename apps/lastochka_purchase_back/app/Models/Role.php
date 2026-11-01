<?php

namespace App\Models;

/**
 * @mixin IdeHelperRole
 */
class Role extends \Spatie\Permission\Models\Role
{

  protected $table = self::table;
  const table = "roles";

  protected $guarded = [];

  const ADMIN = "admin";
  const COLLECTOR = 'collector';
  const COURIER = 'courier';
  const API = 'api';

  const SHOP = 'shop';

  const SUPERADMIN = 'superadmin';

  const SCOPE_GLOBAL = 'global';
  const SCOPE_SHOP = 'shop';

}
