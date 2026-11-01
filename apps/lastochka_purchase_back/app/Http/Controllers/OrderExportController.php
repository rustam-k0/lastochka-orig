<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\Role;
use App\Services\Export\OrderExportQuery;
use App\Services\Export\OrderItemsExportWriter;
use App\Services\Export\OrderStaffResolver;
use App\Services\Export\OrderSummaryExportWriter;
use App\Support\OrderActionAccess;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderExportController extends Controller
{
    public function __construct(
        private OrderExportQuery $query,
        private OrderItemsExportWriter $itemsWriter,
        private OrderSummaryExportWriter $summaryWriter,
        private OrderStaffResolver $staffResolver
    ) {
    }

    /**
     * Экспорт заказов в Excel (xlsx)
     */
    public function export(Request $request): StreamedResponse
    {
        $user = auth()->guard('api')->user();

        if (AdminSetting::getValue('role_access_matrix_enabled') === '1') {
            // Роль api теряет экспорт без гранта — фича админ-UI, сервисные
            // токены её не зовут; роль shop сохраняет через грант миграции
            if (!OrderActionAccess::allowedUnderMatrix($user, 'orders.export')) {
                abort(403, 'Forbidden');
            }
        } elseif (!$user->hasRole([Role::ADMIN, Role::API, Role::SHOP])) {
            abort(403, 'Forbidden');
        }

        $input = $request->validate([
            'order_ids' => 'sometimes|array',
            'order_ids.*' => 'integer',
            'title' => 'sometimes|nullable|string',
            'shops' => 'sometimes|array',
            'shops.*' => 'integer',
            'statuses' => 'sometimes|array',
            'statuses.*' => 'integer',
            'types' => 'sometimes|array',
            'types.*' => 'integer',
            'payment_types' => 'sometimes|array',
            'payment_types.*' => 'integer',
            'payment_statuses' => 'sometimes|array',
            'payment_statuses.*' => 'string',
            'couriers' => 'sometimes|array',
            'couriers.*' => 'integer',
            'collectors' => 'sometimes|array',
            'collectors.*' => 'integer',
            'client_search' => 'sometimes|nullable|string',
            'amount_from' => 'sometimes|nullable|numeric',
            'amount_to' => 'sometimes|nullable|numeric',
            'created_at_start_date' => 'sometimes|nullable|date',
            'created_at_end_date' => 'sometimes|nullable|date',
            'format' => 'sometimes|in:items,summary',
        ]);

        $orders = $this->query->build($input)->get();
        $staff = $this->staffResolver->resolve($orders);

        $writerService = ($input['format'] ?? 'items') === 'summary'
            ? $this->summaryWriter
            : $this->itemsWriter;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($writerService->title());

        $writerService->write($sheet, $orders, $staff);

        $writer = new Xlsx($spreadsheet);
        $filename = $writerService->filenamePrefix() . '_' . date('Y-m-d_H-i-s') . '.xlsx';

        return new StreamedResponse(
            static function () use ($writer) {
                $writer->save('php://output');
            },
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ]
        );
    }
}
