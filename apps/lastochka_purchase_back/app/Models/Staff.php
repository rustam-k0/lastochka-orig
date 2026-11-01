<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lightweight read-only proxy to the users table without the admin-only global scope.
 * Used to resolve collectors and couriers in GraphQL queries.
 */
class Staff extends Model
{
    use SoftDeletes;

    protected $table = 'users';

    protected $fillable = [];
}
