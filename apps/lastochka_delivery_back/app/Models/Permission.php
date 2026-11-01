<?php

namespace App\Models;

use App\Traits\ModelTools;

/**
 * @mixin IdeHelperPermission
 */
class Permission extends \Spatie\Permission\Models\Permission
{
  use ModelTools;

  protected $table = self::table;
  const table = "permissions";

  protected $guarded = [];
}
