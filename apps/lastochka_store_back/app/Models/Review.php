<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;


class Review extends Model
{
  use  ModelTools, SoftDeletes;

  protected $fillable = [
    'main_mark',
    'delivery_mark',
    'assembly_mark',
    'comment',
    'status_id',
    'client_id',
  ];

  public function reviewable(): \Illuminate\Database\Eloquent\Relations\MorphTo
  {
    return $this->morphTo();
  }

  public function client(): \Illuminate\Database\Eloquent\Relations\BelongsTo
  {
    return $this->belongsTo(Client::class);
  }

  public function scopeProduct($query): void
  {
    $query->whereReviewableType(Product::class);
  }

  public function scopeShop($query): void
  {
    $query->whereReviewableType(Shop::class);
  }

  public function scopeOrder($query): void
  {
    $query->whereReviewableType(Order::class);
  }

  public function scopeWithComment(Builder $query): Builder
  {
    return $query->whereNotNull('comment');
  }

  public function scopePublished(Builder $query): Builder
  {
    return $query->where('status_id', Status::REVIEW_PUBLISHED);
  }

  public function scopeAuth(Builder $query): Builder
  {
    return $query->where('client_id',Auth::id());
  }


}
