<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

final class AddressPhoto extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'address_id',
        'disk',
        'path',
        'mime',
        'size',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
