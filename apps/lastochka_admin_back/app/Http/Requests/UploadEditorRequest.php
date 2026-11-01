<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadEditorRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      "image" => ["nullable", "file", "image", "mimes:jpeg,jpg,png,gif,webp", "max:20480"],
      "url" => ["nullable", "url", "required_without:image", "max:2048"],
    ];
  }
}
