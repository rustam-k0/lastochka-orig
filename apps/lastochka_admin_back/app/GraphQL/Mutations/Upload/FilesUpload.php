<?php

namespace App\GraphQL\Mutations\Upload;

use App\Helpers\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Storage;

final class FilesUpload
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args): array
  {
    $files = [];

    foreach ($args["files"] as $file) {
      $size = Upload::humanReadableSize($file->getSize());
      $path = Upload::uploadFile($file, $args["path"] ?? "");
      $originName = $file->getClientOriginalName();
      
      // Логируем данные для отладки
      Log::info('File upload data before sanitization', [
        'origin_name' => $originName,
        'origin_name_encoding' => mb_detect_encoding($originName, mb_detect_order(), true),
        'size' => $size,
        'size_encoding' => mb_detect_encoding($size, mb_detect_order(), true),
        'path' => $path,
      ]);
      
      $files[] = [
        "path" => $path,
        "type" => pathinfo($path)["extension"],
        "size" => $size,
        "origin_name" => $originName,
      ];
    }

    // Применяем санитизацию UTF-8 ко всему результату
    $result = Upload::sanitizeUtf8($files);
    
    // Логируем результат после санитизации
    Log::info('File upload result after sanitization', [
      'result' => $result,
      'json_valid' => json_encode($result) !== false,
    ]);
    
    return $result;
  }
}
