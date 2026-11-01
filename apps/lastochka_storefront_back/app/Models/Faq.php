<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Faq extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'source',
        'external_id',
        'question',
        'answer',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Активные вопросы.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
