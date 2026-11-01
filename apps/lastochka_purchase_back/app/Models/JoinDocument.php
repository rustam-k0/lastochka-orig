<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperJoinDocument
 */
class JoinDocument extends Model
{
  use HasFactory, ModelTools, SoftDeletes;

  protected $table = self::table;
  const table = "join_documents";

  protected $fillable = [
    "item_id",
    "document_id",
    "table_name",
    "head_id",
  ];

  /**
   * Получение файла
   */
  public function document()
  {
    return $this->belongsTo(Document::class, "document_id", "id");
  }

}
