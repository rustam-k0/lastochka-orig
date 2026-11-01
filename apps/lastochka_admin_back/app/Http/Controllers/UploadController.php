<?php

namespace App\Http\Controllers;

use App\Helpers\Upload;
use App\Http\Requests\FetchLinkRequest;
use App\Http\Requests\UploadEditorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
  /**
   * Парсинг ссылки для EditorJS LinkTool.
   */
  public function fetchLink(FetchLinkRequest $request): JsonResponse
  {
    $result = Upload::fetchLink($request->validated());

    return response()->json([
      "success" => $result ? 1 : 0,
      "meta" => $result ?: null,
    ]);
  }

  /**
   * Загрузка изображения для EditorJS ImageTool (byFile/byUrl).
   */
  public function uploadEditor(UploadEditorRequest $request): JsonResponse
  {
    $url = null;

    if ($request->hasFile("image")) {
      $url = Upload::uploadFile($request->file("image"), "editor");
    } elseif ($request->filled("url")) {
      $url = $this->downloadAndStore($request->validated()["url"]);
    }

    return response()->json([
      "success" => $url ? 1 : 0,
      "file" => $url ? ["url" => url($url)] : null,
    ]);
  }

  /**
   * Скачать удалённое изображение и положить в editor/.
   * Возвращает Storage::url() или null если URL не вернул валидную картинку.
   */
  private function downloadAndStore(string $remoteUrl): ?string
  {
    $scheme = parse_url($remoteUrl, PHP_URL_SCHEME);
    if (!in_array($scheme, ["http", "https"], true)) {
      return null;
    }

    $response = Http::timeout(10)->get($remoteUrl);
    if (!$response->ok()) {
      return null;
    }

    $contentType = (string) $response->header("Content-Type");
    if (!Str::startsWith($contentType, "image/")) {
      return null;
    }

    $ext = match (Str::before($contentType, ";")) {
      "image/jpeg", "image/jpg" => "jpg",
      "image/png" => "png",
      "image/gif" => "gif",
      "image/webp" => "webp",
      default => null,
    };
    if (!$ext) {
      return null;
    }

    $path = "editor/" . Str::random(40) . "." . $ext;
    if (!Storage::put($path, $response->body())) {
      return null;
    }

    return Storage::url($path);
  }
}
