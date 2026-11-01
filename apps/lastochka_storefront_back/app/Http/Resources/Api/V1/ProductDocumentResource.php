<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ProductDocument
 */
final class ProductDocumentResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     fileUrl: string|null,
     *     originalName: string|null,
     *     mimeType: string|null,
     *     size: int|null,
     *     group: string|null,
     *     position: int
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'fileUrl' => $this->file_path ? url('storage/'.$this->file_path) : null,
            'originalName' => $this->original_name,
            'mimeType' => $this->mime_type,
            'size' => $this->size === null ? null : (int) $this->size,
            'group' => $this->group,
            'position' => (int) $this->position,
        ];
    }
}
