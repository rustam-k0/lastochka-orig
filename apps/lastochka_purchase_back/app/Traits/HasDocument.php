<?php

namespace App\Traits;

use App\Models\Document;
use App\Models\JoinDocument;
use App\Models\Status;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

trait HasDocument
{

  /**
   * Получение связки главного файла
   */
  public function joinHeadDocument(): BelongsTo
  {
    return $this->belongsTo(JoinDocument::class, "id", "item_id")->where([
      ["head_id", Status::CODE_HEAD_FILE],
      ["table_name", self::table],
    ]);
  }

  /**
   * Получение главного файла
   */
  public function headDocument(): HasOneThrough
  {
    return $this->hasOneThrough(Document::class, JoinDocument::class,
      'item_id',
      'id',
      'id',
      'document_id')
      ->where([
        ["head_id", Status::CODE_HEAD_FILE],
        ["table_name", self::table],
      ]);
  }

  /**
   * Сохранение файла
   */
  public function saveDocument(array $file, int $head_code = Status::CODE_HEAD_FILE, string $table_name = self::table)
  {
    $file = Document::create($file);

    return JoinDocument::create([
      'item_id' => $this->id,
      'document_id' => $file->id,
      'head_id' => $head_code,
      'table_name' => $table_name
    ]);
  }

  public function updateDocument(?JoinDocument $join,?array $file): void
  {
    if ($join && !$file) {
      $this->deleteDocument($join);
    } elseif (isset($join, $file)) {
      $join->document->update($file);
    }
  }

  public function deleteDocument(JoinDocument $join): void
  {
    $join->delete();
  }
}
