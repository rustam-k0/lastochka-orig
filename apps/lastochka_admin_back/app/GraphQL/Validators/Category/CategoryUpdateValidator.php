<?php

namespace App\GraphQL\Validators\Category;

use App\Models\Attribute;
use App\Models\Category;
use App\Rules\NotDefaultAttribute;
use Nuwave\Lighthouse\Validation\Validator;
use Illuminate\Validation\Rule;

final class CategoryUpdateValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {

    $rules =[
     'id'=>['required',Rule::exists((new Category())->getTable(),'id')->whereNull('deleted_at')],
      'title'=>['required','max:255',],
      'parent'=>['nullable',
        Rule::exists((new Category())->getTable(),'id')
          ->whereNull('deleted_at')
          ->whereNot('id',$this->arg('id'))
          ->where(function ($query){
            $query->whereNot('parent_id',$this->arg('id'))->orWhere('parent_id',NULL);
          })
        ],
      'important'=>['nullable',],
      'site_catalog_img'=>['nullable',],
      'site_square_img'=>['nullable',],
      'site_rect_img'=>['nullable',],
      'mobile_column_img'=>['nullable',],
      'mobile_square_img'=>['nullable',],
      'mobile_rect_img'=>['nullable',],
      'min_quantity'=>['nullable','integer','gt:0'],
      'is_active'=>['nullable','boolean'],
      'attributes.*'     => ['bail', Rule::exists((new Attribute())->getTable(), 'id')->whereNull('deleted_at'), new NotDefaultAttribute()],
      'assembly_zone_id' => ['nullable', Rule::exists('assembly_zones', 'id')],
      'tag_text' => ['nullable', 'string', 'max:100'],
      'tag_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
     ];



    return $rules;
  }

}
