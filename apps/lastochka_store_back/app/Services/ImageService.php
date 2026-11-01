<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Intervention\Image\Facades\Image;
use Storage;

class ImageService
{

  const FORMAT = 'webp';
  const DEFAULT_QUALITY = 90;


  public function getCroppedImage($path, $width = null, $height = null, $quality = 90)
  {
    $path = $this->generatePath($path);

    $image = Image::make(File::get($path));

    if (isset($width, $height)) {
      $image = $image->resize((int)$width, (int)$height, function ($constraint) {
        $constraint->aspectRatio();
      });
    }

    $quality = $this->checkQuality($quality);

    return $image->response(self::FORMAT, $quality);
  }

  public function generatePath($path): string
  {
    if (str_starts_with($path, "/files")) {
      $path = substr($path, 7);
    }

    return Storage::path($path);
  }

  public function checkQuality($quality): int
  {
    $quality = (int)$quality;

    if ($quality < 1 || $quality > 100) {
      $quality = self::DEFAULT_QUALITY;
    }

    return $quality;
  }
}
