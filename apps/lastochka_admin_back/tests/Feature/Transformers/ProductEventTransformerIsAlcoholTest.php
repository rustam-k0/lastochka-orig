<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProductEventTransformerIsAlcoholTest extends TestCase
{
  use DatabaseTransactions;

  private function fromEvent(array $overrides): array
  {
    return app(ProductEventTransformer::class)->fromEvent(array_merge([
      'event_id' => 'test-event-id',
      'id' => 'ext-alc-1',
      'shop_code' => 'default',
      'source' => '1c',
      'event_source' => '1c',
      'sku' => 'ALC-1',
      'title' => 'Водка',
      'price' => 500,
      'unit_type' => 'piece',
    ], $overrides));
  }

  public function test_true_enables_adult_only_and_pickup_only(): void
  {
    $attrs = $this->fromEvent(['is_alcohol' => true]);

    $this->assertTrue($attrs['adult_only']);
    $this->assertTrue($attrs['pickup_only']);
  }

  public function test_false_disables_both_even_if_flags_sent(): void
  {
    $attrs = $this->fromEvent(['is_alcohol' => false, 'adult_only' => true, 'pickup_only' => true]);

    $this->assertFalse($attrs['adult_only']);
    $this->assertFalse($attrs['pickup_only']);
  }

  public function test_null_and_string_values(): void
  {
    $this->assertFalse($this->fromEvent(['is_alcohol' => null])['adult_only']);
    $this->assertTrue($this->fromEvent(['is_alcohol' => 'true'])['pickup_only']);
    $this->assertTrue($this->fromEvent(['is_alcohol' => 1])['adult_only']);
  }

  public function test_recognized_values_keep_previous_semantics(): void
  {
    foreach ([[true, true], ['true', true], [1, true], ['1', true], [false, false], ['false', false], [0, false], ['0', false]] as [$value, $expected]) {
      $attrs = $this->fromEvent(['is_alcohol' => $value]);

      $this->assertSame($expected, $attrs['adult_only'], 'is_alcohol=' . var_export($value, true));
      $this->assertSame($expected, $attrs['pickup_only'], 'is_alcohol=' . var_export($value, true));
    }
  }

  public function test_explicit_null_clears_both_flags(): void
  {
    $attrs = $this->fromEvent(['is_alcohol' => null, 'adult_only' => true, 'pickup_only' => true]);

    $this->assertFalse($attrs['adult_only']);
    $this->assertFalse($attrs['pickup_only']);
  }

  public function test_unrecognized_value_does_not_touch_flags_and_warns(): void
  {
    foreach (['Да', 'Y'] as $value) {
      Log::spy();

      $attrs = $this->fromEvent(['is_alcohol' => $value]);

      $this->assertArrayNotHasKey('adult_only', $attrs, "is_alcohol={$value}");
      $this->assertArrayNotHasKey('pickup_only', $attrs, "is_alcohol={$value}");
      Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) => str_contains($message, 'is_alcohol') && ($context['is_alcohol'] ?? null) === $value)
        ->once();
    }
  }

  public function test_absent_key_does_not_touch_flags(): void
  {
    $attrs = $this->fromEvent([]);

    $this->assertArrayNotHasKey('adult_only', $attrs);
    $this->assertArrayNotHasKey('pickup_only', $attrs);
  }
}
