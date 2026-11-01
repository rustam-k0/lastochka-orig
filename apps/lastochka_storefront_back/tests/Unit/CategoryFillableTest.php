<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use Tests\TestCase;

final class CategoryFillableTest extends TestCase
{
    public function test_background_image_path_is_fillable(): void
    {
        $this->assertContains('background_image_path', (new Category)->getFillable());
    }
}
