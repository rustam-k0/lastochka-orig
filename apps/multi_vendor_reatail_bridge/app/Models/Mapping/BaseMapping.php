<?php

namespace App\Models\Mapping;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

abstract class BaseMapping extends Model
{
    use SoftDeletes;
    
    /**
     * Указывает, что ID не должен быть инкрементным.
     *
     * @var bool
     */
    public $incrementing = false;
    
    /**
     * Тип первичного ключа.
     *
     * @var string
     */
    protected $keyType = 'string';
    
    /**
     * Атрибуты, которые можно массово назначать.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'external_id',
        'source',
        'data',
    ];
    
    /**
     * Атрибуты, которые должны быть преобразованы.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
