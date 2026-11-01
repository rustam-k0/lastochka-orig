<?php

namespace App\Models;

use App\Traits\ModelTools;

/**
 * @mixin IdeHelperRole
 */
class Role extends \Spatie\Permission\Models\Role
{
  use ModelTools;

  protected $table = self::table;
  const table = "roles";

  protected $guarded = [];

  const ADMIN = "admin";

  const COLLECTOR = 'collector';
  const COURIER = 'courier';

  const SUPERADMIN = 'superadmin';

}
