<?php

namespace App\GraphQL\Validators\Upload;

use App\Helpers\Upload;
use Nuwave\Lighthouse\Validation\Validator;

class ImageUploadCropValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      "image" => ["required", "file", Upload::getImageRules()],
      "x" => ["required"],
      "y" => ["required"],
      "width" => ["required"],
      "height" => ["required"],
      "rotate" => ["required"],
      "scaleX" => ["required"],
      "scaleY" => ["required"],
    ];
  }
}
