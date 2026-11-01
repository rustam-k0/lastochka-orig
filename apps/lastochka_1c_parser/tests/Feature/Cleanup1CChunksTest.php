<?php

use App\Models\CatalogImportChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 1c:cleanup --logs вычищает и устаревшие записи состава выгрузки
 * (catalog_import_chunks): они копятся, если чекбокс удаления выключили
 * после того, как чанки уже были записаны, и потребить их некому.
 */
function makeChunk(string $createdAt): CatalogImportChunk
{
    $chunk = CatalogImportChunk::create([
        'shop_id' => 1,
        'exchange_log_id' => 1,
        'external_ids' => ['p-1'],
        'products_count' => 1,
    ]);
    CatalogImportChunk::where('id', $chunk->id)->update(['created_at' => $createdAt]);

    return $chunk->fresh();
}

it('удаляет устаревшие чанки и не трогает свежие', function () {
    $stale = makeChunk(now()->subDays(30)->toDateTimeString());
    $fresh = makeChunk(now()->subHour()->toDateTimeString());

    $this->artisan('1c:cleanup', ['--days' => 7, '--logs' => true])
        ->assertExitCode(0);

    expect(CatalogImportChunk::find($stale->id))->toBeNull();
    expect(CatalogImportChunk::find($fresh->id))->not->toBeNull();
});

it('без --logs чанки не трогает', function () {
    $stale = makeChunk(now()->subDays(30)->toDateTimeString());

    $this->artisan('1c:cleanup', ['--days' => 7])->assertExitCode(0);

    expect(CatalogImportChunk::find($stale->id))->not->toBeNull();
});
