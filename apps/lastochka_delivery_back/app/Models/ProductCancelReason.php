<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductCancelReason extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Порядок как в админке: position ASC, NULL-позиции в конце, tie-break по id.
     */
    public function scopeOrdered($query)
    {
        return $query->orderByRaw('position IS NULL')
            ->orderBy('position')
            ->orderBy('id');
    }
}
