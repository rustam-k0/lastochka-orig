<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;


/**
 * @mixin IdeHelperClient
 */
class Client extends Authenticatable
{

  use HasApiTokens,
    HasFactory,
    Notifiable,
    ModelTools,
    HasImage,
    SoftDeletes;

  protected $fillable = ["name", "phone", "surname", "patronymic", "email", "password"];

  protected $hidden = ["password"];


  protected $casts = [
    "email_verified_at" => "datetime",
  ];


  public function getTitleAttribute(): string
  {
    return trim("{$this->surname} {$this->name} {$this->patronymic}");
  }

  protected static function boot()
  {
    parent::boot();
    static::creating(function ($model) {
      if (!$model->password) {
        $model->password = bcrypt(Str::random());
      }
    });
  }

  public function orders(): HasMany
  {
    return $this->hasMany(Order::class);
  }

}
