<?php

namespace App\Helpers;

use Goutte\Client;
use Intervention\Image\Facades\Image;
use Storage;
use Str;
use Symfony\Component\HttpClient\Exception\TransportException;

class Upload
{
  /**
   * Обрезка изображения
   * @param array $data
   * @return array
   */
  public static function uploadCrop(array $data): array
  {
    $image = new \Imagick();
    $image->readImage($data["image"]);

    $image->rotateImage("transparent", $data["rotate"]);
    if ($data["scaleX"] < 0) {
      $image->flopImage();
    }
    if ($data["scaleY"] < 0) {
      $image->flipImage();
    }

    // rotateImage оставляет у изображения смещённую page geometry (виртуальный
    // холст), и cropImage начинает считать x/y относительно неё — прямоугольник
    // «не пересекается» с картинкой, Imagick бросает
    // "geometry does not contain image" (GlitchTip ADMIN_BACK-C). Сбрасываем
    // страницу и подрезаем геометрию под реальные размеры: кроппер во фронте
    // отдаёт координаты по своей отрисовке и на границах даёт выход за холст.
    $image->setImagePage(0, 0, 0, 0);

    $geometry = self::clampCropGeometry(
      (int) round((float) $data["x"]),
      (int) round((float) $data["y"]),
      (int) round((float) $data["width"]),
      (int) round((float) $data["height"]),
      $image->getImageWidth(),
      $image->getImageHeight()
    );

    if ($geometry === null) {
      throw self::invalidCropArea();
    }

    // Сам вход, уронивший кроп в проде, восстановить не удалось (multipart-тело
    // в трекер не попадает), поэтому оставляем жёсткую границу: любая
    // ImagickException на обрезке — понятная 422 оператору, а не 500.
    try {
      $image->cropImage($geometry["width"], $geometry["height"], $geometry["x"], $geometry["y"]);
    } catch (\ImagickException $e) {
      \Log::warning('uploadCrop: cropImage failed', [
        'geometry' => $geometry,
        'image_size' => [$image->getImageWidth(), $image->getImageHeight()],
        'rotate' => $data["rotate"],
        'error' => $e->getMessage(),
      ]);

      throw self::invalidCropArea();
    }

    $result["type"] = $data["image"]->clientExtension();
    $result["origin_name"] = $data["image"]->getClientOriginalName();
    $result["path"] = "cropped/" . Str::random(40) . "." . $result["type"];

    self::uploadFile($image->getImageBlob(), $result["path"]);

    $result["size"] = self::humanReadableSize(Storage::size($result["path"]));
    $result["path"] = Storage::url($result["path"]);

    // Применяем санитизацию UTF-8 ко всему результату
    return self::sanitizeUtf8($result);
  }

  /**
   * Ошибка «не удалось обрезать по этой области» для оператора админки.
   */
  protected static function invalidCropArea(): \Illuminate\Validation\ValidationException
  {
    return new \Illuminate\Validation\ValidationException(
      validator([], []),
      response()->json([
        'message' => 'Некорректная область обрезки изображения',
        'errors' => ['image' => ['Некорректная область обрезки изображения']],
      ], 422)
    );
  }

  /**
   * Подрезать прямоугольник обрезки под реальные размеры изображения.
   * Возвращает null, если пересечения с изображением нет вообще.
   *
   * @return array{x:int,y:int,width:int,height:int}|null
   */
  public static function clampCropGeometry(int $x, int $y, int $width, int $height, int $imageWidth, int $imageHeight): ?array
  {
    if ($width <= 0 || $height <= 0 || $imageWidth <= 0 || $imageHeight <= 0) {
      return null;
    }

    // Отрицательный офсет: кроп начался левее/выше картинки — сдвигаем начало
    // в 0 и на столько же уменьшаем сторону.
    if ($x < 0) {
      $width += $x;
      $x = 0;
    }
    if ($y < 0) {
      $height += $y;
      $y = 0;
    }

    if ($x >= $imageWidth || $y >= $imageHeight) {
      return null;
    }

    $width = min($width, $imageWidth - $x);
    $height = min($height, $imageHeight - $y);

    if ($width <= 0 || $height <= 0) {
      return null;
    }

    return ["x" => $x, "y" => $y, "width" => $width, "height" => $height];
  }

  /**
   * Загрузка файла на сервер
   * @param $file
   * @param string $path
   * @return string
   */
  public static function uploadFile($file, string $path = ""): string
  {
    try {
      \Log::info('Upload attempt', [
        'initial_path' => $path,
        'file_type' => is_string($file) ? 'string' : get_class($file),
        'file_size' => is_string($file) ? strlen($file) : (method_exists($file, 'getSize') ? $file->getSize() : 'unknown'),
        'storage_driver' => config('filesystems.default'),
        'storage_root' => config('filesystems.disks.local.root'),
      ]);

      $result = Storage::put($path, $file);
      
      if ($result === false) {
        \Log::error('Storage::put returned false', [
          'path' => $path,
          'storage_root' => config('filesystems.disks.local.root'),
          'disk_config' => config('filesystems.disks.local'),
          'disk_exists' => Storage::disk()->exists(''),
          'permissions' => @fileperms(config('filesystems.disks.local.root')),
        ]);
        throw new \Exception('Failed to save file. Storage::put returned false');
      }

      \Log::info('Upload successful', [
        'saved_path' => $result,
        'url' => Storage::url($result),
      ]);

      return Storage::url($result);
    } catch (\Exception $e) {
      \Log::error('Upload exception', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'path' => $path,
      ]);
      throw $e;
    }
  }

  /**
   * Форматирование размера
   * @param $bytes
   * @return string
   */
  public static function humanReadableSize($bytes): string
  {
    if (!$bytes) {
      return "0";
    }

    $i = floor(log($bytes, 1024));
    $size = round($bytes / pow(1024, $i), [0, 0, 2, 2, 3][(int) $i]) .
      ["Б", "КБ", "МБ", "ГБ", "ТБ"][(int) $i];

    // Гарантируем корректную UTF-8 кодировку
    return mb_convert_encoding($size, 'UTF-8', 'UTF-8');
  }

  /**
   * Рекурсивная санитизация UTF-8 для массивов и строк
   * @param mixed $data
   * @return mixed
   */
  public static function sanitizeUtf8($data)
  {
    if (is_array($data)) {
      return array_map([self::class, 'sanitizeUtf8'], $data);
    }

    if (is_string($data)) {
      // Если строка уже корректная UTF-8, возвращаем как есть
      if (mb_check_encoding($data, 'UTF-8')) {
        // Дополнительная проверка через json_encode
        $encoded = json_encode($data);
        if ($encoded !== false) {
          return $data;
        }
      }

      // Пытаемся определить кодировку и конвертировать
      $encoding = mb_detect_encoding($data, ['UTF-8', 'Windows-1251', 'ISO-8859-1', 'CP1251'], true);
      if ($encoding && $encoding !== 'UTF-8') {
        $data = mb_convert_encoding($data, 'UTF-8', $encoding);
      }

      // Агрессивная очистка через iconv
      $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $data);
      if ($cleaned !== false) {
        $data = $cleaned;
      }

      // Финальная проверка через json_encode/decode для удаления проблемных символов
      $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
      if ($json !== false) {
        $data = json_decode($json);
      }

      return $data;
    }

    return $data;
  }

  /**
   * Какие изображения можно загружать на сервер
   * @return string
   */
  public static function getImageRules(): string
  {
    // Для ограничения файла по размеру добавить max:50000 (5мб)
    return "mimes:jpeg,jpg,png,gif";
  }

  /**
   * Парсинг ссылки для EditorJS
   * @param $request
   * @return array|bool
   */
  public static function fetchLink($request)
  {
    // Получение содержимого сайта с помощью DomCrawler
    $client = new Client();
    try {
      $crawler = $client->request("GET", $request["url"]);
    } catch (TransportException $exception) {
      return false;
    }

    // Получение заголовка
    $title = $request["url"];
    $crawledTitle = $crawler->filter("head > title")->getNode(0);
    $crawledOGTitle = $crawler
      ->filter('head > meta[property="og:title"]')
      ->getNode(0);
    if ($crawledTitle) {
      $title = $crawledTitle->textContent;
    } elseif (
      $crawledOGTitle &&
      count($crawledOGTitle->attributes) &&
      $crawledOGTitle->attributes->getNamedItem("content")
    ) {
      $title = $crawledOGTitle->attributes->getNamedItem("content")->nodeValue;
    }

    // Получение описания
    $description = "";
    $crawledDescription = $crawler
      ->filter('head > meta[name="description"]')
      ->getNode(0);
    $crawledOGDescription = $crawler
      ->filter('head > meta[property="og:description"]')
      ->getNode(0);
    if (
      $crawledDescription &&
      count($crawledDescription->attributes) &&
      $crawledDescription->attributes->getNamedItem("content")
    ) {
      $description = $crawledDescription->attributes->getNamedItem("content")
        ->nodeValue;
    } elseif (
      $crawledOGDescription &&
      count($crawledOGDescription->attributes) &&
      $crawledOGDescription->attributes->getNamedItem("content")
    ) {
      $description = $crawledOGDescription->attributes->getNamedItem("content")
        ->nodeValue;
    }

    // Получение изображения
    $image = "";
    $crawledOGImage = $crawler
      ->filter('head > meta[property="og:image"]')
      ->getNode(0);
    $crawledImage = $crawler->filter("body img")->getNode(0);
    if (
      $crawledOGImage &&
      count($crawledOGImage->attributes) &&
      $crawledOGImage->attributes->getNamedItem("content")
    ) {
      $image = $crawledOGImage->attributes->getNamedItem("content")->nodeValue;
    } elseif (
      $crawledImage &&
      count($crawledImage->attributes) &&
      $crawledImage->attributes->getNamedItem("src")
    ) {
      $image = $crawledImage->attributes->getNamedItem("src")->nodeValue;
    }
    $result = [
      "title" => $title,
      "description" => $description,
      "image" => [
        "url" => $image,
      ],
    ];

    // Применяем санитизацию UTF-8 ко всему результату
    return self::sanitizeUtf8($result);
  }
}
