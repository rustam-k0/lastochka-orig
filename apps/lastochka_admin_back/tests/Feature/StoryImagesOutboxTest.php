<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Story\StoryCreate;
use App\GraphQL\Mutations\Story\StoryUpdate;
use App\Models\Story;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * LASTOCHKI-JAMI-12236: изменение только картинок истории (слайдер/превью)
 * не публиковало outbox-событие story.updated — img/join_img не трогают
 * модель Story, Eloquent `updated` не стреляет, storefront не узнаёт об
 * изменении, и мобилка показывает устаревший набор слайдов.
 */
class StoryImagesOutboxTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        // Пакет регистрирует eloquent-observers один раз на PHP-процесс
        // (static-флаг), а PHPUnit пересоздаёт приложение на каждый тест —
        // сбрасываем флаг до boot, чтобы outbox-observer работал в каждом тесте.
        $flag = new \ReflectionProperty(RabbitMQEventsServiceProvider::class, 'observersRegistered');
        $flag->setAccessible(true);
        $flag->setValue(null, false);

        parent::setUp();
    }

    private function createStoryWithImage(): Story
    {
        return (new StoryCreate())(null, [
            'title' => 'Outbox Story ' . uniqid(),
            'expires_at' => now()->addMonth(),
            'thumbnail_image' => ['path' => '/files/test-thumb.jpg'],
            'images' => [
                ['path' => '/files/test-slide-1.jpg'],
            ],
        ]);
    }

    private function updatedEventsCount(int $storyId): int
    {
        return EventOutbox::where('entity_type', 'stories')
            ->where('entity_id', $storyId)
            ->where('event_type', 'updated')
            ->count();
    }

    /** @test */
    public function adding_slider_image_without_field_changes_creates_outbox_event()
    {
        $story = $this->createStoryWithImage();
        $baseline = $this->updatedEventsCount($story->id);

        // Правки картинок происходят позже создания — иначе touch() попадёт
        // в ту же секунду и updated_at не станет dirty
        $this->travelTo(now()->addMinute());

        $existingImgId = $story->images()->first()->id;

        (new StoryUpdate())(null, [
            'id' => $story->id,
            'images' => [
                ['id' => $existingImgId],
                ['path' => '/files/test-slide-2.png'],
            ],
        ]);

        $this->assertSame(2, $story->images()->count());
        $this->assertGreaterThan(
            $baseline,
            $this->updatedEventsCount($story->id),
            'Добавление слайда без изменения полей Story должно публиковать story.updated'
        );
    }

    /** @test */
    public function removing_slider_image_without_field_changes_creates_outbox_event()
    {
        $story = $this->createStoryWithImage();

        $this->travelTo(now()->addMinute());
        $existingImgId = $story->images()->first()->id;

        (new StoryUpdate())(null, [
            'id' => $story->id,
            'images' => [
                ['id' => $existingImgId],
                ['path' => '/files/test-slide-2.png'],
            ],
        ]);

        $baseline = $this->updatedEventsCount($story->id);

        // Удаляем второй слайд: передаём только первый
        $this->travelTo(now()->addMinutes(2));

        (new StoryUpdate())(null, [
            'id' => $story->id,
            'images' => [
                ['id' => $existingImgId],
            ],
        ]);

        $this->assertSame(1, $story->images()->count());
        $this->assertGreaterThan(
            $baseline,
            $this->updatedEventsCount($story->id),
            'Удаление слайда без изменения полей Story должно публиковать story.updated'
        );
    }

    /** @test */
    public function replacing_thumbnail_without_field_changes_creates_outbox_event()
    {
        $story = $this->createStoryWithImage();
        $baseline = $this->updatedEventsCount($story->id);

        $this->travelTo(now()->addMinute());

        (new StoryUpdate())(null, [
            'id' => $story->id,
            'thumbnail_image' => ['path' => '/files/test-thumb-new.jpg'],
        ]);

        $this->assertGreaterThan(
            $baseline,
            $this->updatedEventsCount($story->id),
            'Замена превью без изменения полей Story должна публиковать story.updated'
        );
    }

    /** @test */
    public function scalar_update_with_images_does_not_double_publish()
    {
        $story = $this->createStoryWithImage();
        $baseline = $this->updatedEventsCount($story->id);

        $this->travelTo(now()->addMinute());

        $existingImgId = $story->images()->first()->id;

        (new StoryUpdate())(null, [
            'id' => $story->id,
            'title' => 'Обновлённый заголовок ' . uniqid(),
            'images' => [
                ['id' => $existingImgId],
            ],
        ]);

        $this->assertSame(
            $baseline + 1,
            $this->updatedEventsCount($story->id),
            'Изменение полей Story уже публикует story.updated — touch() не должен дублировать событие'
        );
    }
}
