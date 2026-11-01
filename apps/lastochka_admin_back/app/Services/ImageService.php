<?php

namespace App\Services;

use App\Helpers\Upload;
use App\Models\ImageLink;
use Illuminate\Support\Facades\File;
use Intervention\Image\Facades\Image;
use Storage;

class ImageService
{

  const FORMAT = 'webp';
  const DEFAULT_QUALITY = 90;


  public function getCroppedImage($path, int $id, $width = null, $height = null, int $quality = 90)
  {

    $path = $this->generatePath($path);

    $quality = $this->checkQuality($quality);

    if ($this->isS3()) {
      return $this->getOrGenerateS3Image($path, $width, $height, $quality, $id);
    }

    if (!$this->fileExists($path)) {
      return null;
    }

    $file = File::get($path);

    $image = $this->cropImage($file, $width, $height);

    return $image->response(self::FORMAT, $quality);
  }

  public function generatePath($path): string
  {
    if (!$this->isS3() && (str_starts_with($path, "/files"))) {
      $path = substr($path, 7);
    }

    return Storage::path($path);
  }

  public function checkQuality(int $quality): int
  {

    if ($quality < 1 || $quality > 100) {
      $quality = self::DEFAULT_QUALITY;
    }

    return $quality;
  }

  private function cropImage($file, $width, $height)
  {
    $image = Image::make($file);

    if (isset($width, $height)) {
      $image = $image->resize((int)$width, (int)$height, function ($constraint) {
        $constraint->aspectRatio();
      });
    }

    return $image;
  }

  private function fileExists(string $path): bool
  {
    return File::exists($path);
  }

  //Генерация пути к обрезанному изображению в S3 хранилище
  private function generateS3Path(string $path, mixed $width, mixed $height, int $quality): string
  {
    $path_info = pathinfo($path);

    $path = "cached/{$path_info['dirname']}/{$path_info['filename']}_{$quality}";

    if (isset($width, $height)) {
      $path .= "_{$width}_{$height}";
    }

    $path .= "." . self::FORMAT;

    return $path;
  }

  /**
   * Проверка, используется ли S3 в проекте
   * @return bool
   */
  public function isS3(): bool
  {
    return config('filesystems.default') == 's3';
  }

  /**
   * Получить уже обрезанное изображение, либо сгенерировать в случае его отсутствия
   * @param string $path
   * @param mixed $width
   * @param mixed $height
   * @param int $quality
   * @return \Illuminate\Http\Response|mixed|null
   * @throws \Illuminate\Contracts\Container\BindingResolutionException
   */
  private function getOrGenerateS3Image(string $path, mixed $width, mixed $height, int $quality, int $id): mixed
  {
    $generated_path = $this->generateS3Path($path, $width, $height, $quality);

    $file = Storage::get($generated_path);

    if (!$file) {
      $file = $this->generateS3Image($generated_path, $path, $width, $height, $quality);
    }

    if (!$file) {
      return null;
    }

    $response = response()->make($file, 200, [
      'Content-Type' => 'image/' . self::FORMAT, // Измените тип содержимого в соответствии с типом вашего изображения
    ]);

    $query = $this->generateImageQuery($quality, $width, $height);

    $this->createImageLink($id, $query, $generated_path);

    return $response;

  }

  /**
   * Обрезать изображение и сохранить его в S3
   * @param string $generated_path
   * @param string $path
   * @param mixed $width
   * @param mixed $height
   * @param int $quality
   * @return string|null
   */
  private function generateS3Image(string $generated_path, string $path, mixed $width, mixed $height, int $quality): ?string
  {

    $origin_file = Storage::get($path);

    if (!$origin_file) {
      return null;
    }

    $image = $this->cropImage($origin_file, $width, $height)->encode(self::FORMAT, $quality)->getEncoded();

    Upload::uploadFile($image, $generated_path);

    return Storage::get($generated_path);
  }

  private function generateImageQuery(int $quality = self::DEFAULT_QUALITY, mixed $width = null, mixed $height = null): string
  {
    $query = "quality=" . $quality;

    if ($width && $height) {
      $query .= "&width=$width&height=$height";
    }

    return $query;
  }

  /**
   * @param int $id
   * @param string $query
   * @param string $generated_path
   * @return void
   */
  protected function createImageLink(int $id, string $query, string $generated_path): void
  {
    $link = ImageLink::firstOrCreate([
      'img_id' => $id,
      'query' => $query,
      'path' => "/" . $generated_path,
    ]);

    if ($link->wasRecentlyCreated) {
      $count = ImageLink::whereImgId($id)->count();
      if ($count > 5) {
        ImageLink::whereImgId($id)->orderBy('updated_at')->first()->delete();
      }
    }
  }
}
