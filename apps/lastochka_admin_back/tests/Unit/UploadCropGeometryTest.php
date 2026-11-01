<?php

namespace Tests\Unit;

use App\Helpers\Upload;
use PHPUnit\Framework\TestCase;

class UploadCropGeometryTest extends TestCase
{
  public function test_geometry_inside_image_is_not_changed(): void
  {
    $this->assertSame(
      ["x" => 10, "y" => 20, "width" => 100, "height" => 50],
      Upload::clampCropGeometry(10, 20, 100, 50, 400, 300)
    );
  }

  public function test_negative_offset_is_shifted_to_zero(): void
  {
    $this->assertSame(
      ["x" => 0, "y" => 0, "width" => 90, "height" => 45],
      Upload::clampCropGeometry(-10, -5, 100, 50, 400, 300)
    );
  }

  public function test_size_is_cut_to_image_bounds(): void
  {
    $this->assertSame(
      ["x" => 350, "y" => 280, "width" => 50, "height" => 20],
      Upload::clampCropGeometry(350, 280, 200, 200, 400, 300)
    );
  }

  public function test_rectangle_fully_outside_image_returns_null(): void
  {
    $this->assertNull(Upload::clampCropGeometry(500, 10, 100, 100, 400, 300));
    $this->assertNull(Upload::clampCropGeometry(10, 400, 100, 100, 400, 300));
  }

  public function test_zero_or_negative_size_returns_null(): void
  {
    $this->assertNull(Upload::clampCropGeometry(10, 10, 0, 100, 400, 300));
    $this->assertNull(Upload::clampCropGeometry(10, 10, 100, -5, 400, 300));
  }

  public function test_negative_offset_larger_than_size_returns_null(): void
  {
    $this->assertNull(Upload::clampCropGeometry(-150, 10, 100, 100, 400, 300));
  }
}
