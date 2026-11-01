<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Зона сборки (этаж). Справочник ведётся в admin_back (общая БД).
 */
class AssemblyZone extends Model
{
  protected $fillable = [
    'title',
    'color',
    'is_active',
    'description',
    'position',
  ];

  protected $casts = [
    'is_active' => 'boolean',
  ];
}
