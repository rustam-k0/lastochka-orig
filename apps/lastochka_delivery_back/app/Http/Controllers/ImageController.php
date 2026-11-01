<?php

namespace App\Http\Controllers;

use App\Models\Img;
use App\Services\ImageService;
use Illuminate\Http\Request;

class ImageController extends Controller
{
  protected ImageService $service;

  public function __construct(ImageService $service)
  {
    $this->service = $service;
  }

  public function get($id, Request $request)
  {
    $image = Img::findOrFail($id);

    $quality = (int)$request->quality ?? 90;
    $width = $request->width ?? null;
    $height = $request->height ?? null;

    return $this->service->getCroppedImage($image->path, $width, $height, $quality);
  }
}
