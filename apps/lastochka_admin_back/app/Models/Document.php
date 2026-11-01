<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperDocument
 */
class Document extends Model
{

  protected $table = self::table;
  const table = "documents";

  protected $fillable = ["path", "origin_name", "alt", "size", "type"];
}
