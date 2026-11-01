<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FetchLinkRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      "url" => ["required", "url", "max:2048"],
    ];
  }
}
