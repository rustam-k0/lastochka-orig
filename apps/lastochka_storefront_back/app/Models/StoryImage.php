<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StoryImage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'story_id',
        'path',
        'position',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    // ==================== Relations ====================

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}
