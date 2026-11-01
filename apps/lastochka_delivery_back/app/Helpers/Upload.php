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
    $image->cropImage($data["width"], $data["height"], $data["x"], $data["y"]);

    $result["type"] = $data["image"]->clientExtension();
    $result["origin_name"] = $data["image"]->getClientOriginalName();
    $result["path"] = "cropped/" . Str::random(40) . "." . $result["type"];

    self::uploadFile($image->getImageBlob(), $result["path"]);

    $result["size"] = self::humanReadableSize(Storage::size($result["path"]));
    $result["path"] = Storage::url($result["path"]);
    return $result;
  }

  /**
   * Загрузка файла на сервер
   * @param $file
   * @param string $path
   * @return string
   */
  public static function uploadFile($file, string $path = ""): string
  {
    $path = Storage::put($path, $file);

    return Storage::url($path);
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
    return round($bytes / pow(1024, $i), [0, 0, 2, 2, 3][(int) $i]) .
      ["Б", "КБ", "МБ", "ГБ", "ТБ"][(int) $i];
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
    return [
      "title" => $title,
      "description" => $description,
      "image" => [
        "url" => $image,
      ],
    ];
  }
}
