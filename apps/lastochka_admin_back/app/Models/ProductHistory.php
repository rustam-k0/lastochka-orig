<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductHistory extends Model
{
    public const ACTION_STATUS_CHANGED = 'status_changed';

    public const ACTOR_STAFF = 'staff';
    public const ACTOR_SYSTEM = 'system';

    protected $table = 'product_history';

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'action',
        'actor_type',
        'actor_id',
        'actor_name',
        'old_value',
        'new_value',
        'comment',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public static function log(
        int $productId,
        string $action,
        string $actorType,
        ?int $actorId,
        ?string $actorName,
        ?string $oldValue,
        ?string $newValue,
        ?string $comment = null
    ): void {
        self::create([
            'product_id' => $productId,
            'action' => $action,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'comment' => $comment,
            'created_at' => now(),
        ]);
    }
}
