<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Services\QuarantineExportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Скачивание «Карантина товаров» в XLSX с учётом текущих фильтров страницы
 * (причина + магазин) — FOODLINK-11194.
 *
 * GET /action/export/quarantine?code=<__all__|double_match|no_barcode|missing_*>&shop_id=<int>
 * Оба параметра опциональны: без code — все причины агрегатора, без shop_id — все магазины.
 * Auth: Bearer-токен (guard api), только админ. Запрос слать с Accept: application/json.
 */
class QuarantineExportController extends Controller
{
  public function download(Request $request, QuarantineExportService $service): StreamedResponse
  {
    $this->authorize('onlyAdmin', Admin::class);

    $validated = $request->validate([
      'code' => ['nullable', 'string', Rule::in(QuarantineExportService::allowedCodes())],
      'shop_id' => ['nullable', 'integer'],
    ]);

    $code = $validated['code'] ?? QuarantineExportService::CODE_ALL;
    $shopId = isset($validated['shop_id']) ? (int) $validated['shop_id'] : null;

    $rows = $service->rows($code, $shopId);

    $filename = sprintf(
      'quarantine_%s%s_%s.xlsx',
      $code === QuarantineExportService::CODE_ALL ? 'all' : $code,
      $shopId !== null ? "_shop{$shopId}" : '',
      now()->format('Y-m-d_H-i'),
    );

    return response()->streamDownload(function () use ($rows) {
      $writer = new Writer();
      $writer->openToFile('php://output');
      $writer->addRow(Row::fromValues(QuarantineExportService::HEADERS));
      foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
      }
      $writer->close();
    }, $filename, [
      'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ]);
  }
}
