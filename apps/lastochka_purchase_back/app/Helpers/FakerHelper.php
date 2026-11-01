<?php

namespace App\Helpers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class FakerHelper
{
  public static function getSvgArray()
  {
    $svg_arr = [
      '<svg height="100" width="100">
  <circle cx="50" cy="50" r="40" stroke="black" stroke-width="3" fill="red" />
  Sorry, your browser does not support inline SVG.
</svg>',
      '<svg width="400" height="110">
  <rect width="300" height="100" style="fill:rgb(0, 0, 255);stroke - width:3;stroke:rgb(0, 0, 0)" />
  Sorry, your browser does not support inline SVG.
</svg>',
      '<svg width="400" height="180">
  <rect x="50" y="20" rx="20" ry="20" width="150" height="150" style="fill:red;stroke:black;stroke - width:5;opacity:0.5" />
  Sorry, your browser does not support inline SVG.
</svg>'
    ];
    return $svg_arr;
  }


  /**
   * Скачать изображение и сохранить на сервере
   * @param $img_url
   * @param $path
   * @param $img_name
   */
  protected static function downloadImage($img_url, $path, $img_name)
  {
    $fp = fopen($path . "/{$img_name}", "w+");

    $ch = curl_init($img_url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    //curl_setopt($ch, CURLOPT_VERBOSE, true);   // Enable this line to see debug prints
    curl_exec($ch);

    fclose($fp);
    curl_close($ch);
  }


  /**
   * Генерация тестового изображения
   * @param string|null $folders - подпапки, куда сохранять файл
   * @param int $width - ширина изображения
   * @param int $height - высота изображения
   * @param bool $databse - возвращать ли изображение в формате масива для сохранения в таблицу images
   * @param bool $from_cmd - вызван ли метод с помощью командной строки
   * @return string|string[]
   */
  public static function generateFakeImage(
    string $folders = null,
           $width = 450,
           $height = 350,
           $databse = true,
           $from_cmd = true
  )
  {
    // С какого сайта скачать файл
    $url = "https://via.placeholder.com";

    // Ссылка на определенный файл пример: https://via.placeholder.com/450x350
    $img_url = $url . "/{$width}x{$height}";

    $db_path = "files/fake";
    if (isset($folders)) {
      $db_path .= "/{$folders}";
    }

    // Путь до папки хранения файла
    $path = $from_cmd ? "public/{$db_path}" : $db_path;
    File::isDirectory($path) or File::makeDirectory($path, 0777, true, true);

    $file = "/img{$width}x{$height}.png";
    if (!file_exists($path . $file)) {
      self::downloadImage($img_url, $path, $file);
      $url = $db_path . $file;
      $img_name = "img{$width}x{$height}.png";
    } else {
      /**
       * Если имеется файл с таким же размером, то не скачиваем
       * файл, а просто копируем уже существующий
       */
      $img_name = Str::random(10) . ".png";

      copy($path . $file, $path . "/{$img_name}");

      $url = $db_path . "/{$img_name}";
    }

    if ($databse) {
      return [
        "path" => "/" . $url,
        "origin_name" => $img_name,
        "alt" => $img_name,
      ];
    } else {
      return $url;
    }
  }



}
