<?php

namespace App\GraphQL\Mutations\Upload;

use App\Helpers\Upload;

class ImageUploadCrop
{
  /**
   * @param null $_
   * @param array<string, mixed> $args
   */
  public function __invoke($_, array $args): array
  {
    return Upload::uploadCrop($args);
  }
}
