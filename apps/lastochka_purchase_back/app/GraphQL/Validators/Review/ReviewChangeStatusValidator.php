<?php

namespace App\GraphQL\Validators\Review;

use App\Models\Status;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ReviewChangeStatusValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'id' => ['required', Rule::exists('reviews')->whereNull('deleted_at')],
      'status_id'=>['required',Rule::in([
        Status::REVIEW_MODERATION,
        Status::REVIEW_PUBLISHED,
        Status::REVIEW_HIDDEN,
      ])]
    ];
  }
}
