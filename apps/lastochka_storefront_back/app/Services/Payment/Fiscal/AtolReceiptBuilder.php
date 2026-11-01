<?php
declare(strict_types=1);
namespace App\Services\Payment\Fiscal;
use App\Enums\MeasurementUnit;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\IntegrationConfigService;

final class AtolReceiptBuilder
{
    /**
     * Единицы измерения строки чека. Ключ — внутренний код единицы (`kg`/`l`),
     * значение — то, что уходит в API АТОЛ:
     *  - ФФД 1.05 (APIv4): строковый `measurement_unit` (тег 1197, необязательный);
     *  - ФФД 1.2 (APIv5): числовой `measure` (тег 2108, обязательный; 0 = штуки).
     * Коды 11 (килограмм) и 41 (литр) проверены на testonline.atol.ru v5.
     */
    private const UNIT_LABELS_FFD105 = ['kg' => 'кг', 'l' => 'л'];

    private const UNIT_CODES_FFD12 = ['kg' => 11, 'l' => 41];

    /**
     * Допустимое расхождение `price × quantity` и `sum` в копейках. АТОЛ (v4 и v5)
     * принимает чек при Δ = 1 коп (33.33 × 3 = 99.99 при sum 100.00 — ФД выписан) и
     * отвергает кодом 16 при Δ = 2 коп (16.67 × 6 = 100.02). Проверено на стендах
     * testonline.atol.ru 2026-08-17.
     */
    private const PRICE_SUM_TOLERANCE_CENTS = 1;

    /** @return array<string, mixed> */
    public function build(Order $order, PaymentType $paymentType, float $amount): array
    {
        // activeItems, а не items: отменённые и заменённые при сборке позиции не
        // входят ни в `orders.total`, ни в фактически переданный клиенту состав.
        $order->loadMissing(['activeItems', 'user']);
        // RabbitMQ propagates credentials FLAT (IntegrationConfigEventTransformer::toEvent
        // sends only the active driver's sub-dict, no `atol` wrapper key). Accept both shapes.
        $all = IntegrationConfigService::getCredentials('fiscal');
        $creds = $all['atol'] ?? $all;

        $isCash = $paymentType === PaymentType::Cash;
        $paymentMethod = $isCash ? 'full_payment' : 'full_prepayment';
        $isFfd12 = ($creds['ffd_version'] ?? '1.05') === '1.2';

        $lines = $this->lines($order, $paymentMethod, $creds, $isFfd12);
        // Активных позиций не осталось (например, возврат по заказу, где сборщик
        // отменил всё): чек без позиций ATOL отвергает целиком, поэтому одна
        // обобщённая строка на всю сумму — как в ReceiptBuilder::distribute().
        if ($lines === []) {
            $lines = [$this->fallbackLine($paymentMethod, $creds, $isFfd12, $amount)];
        }

        $items = $this->distribute($lines, $amount);

        return [
            'client' => array_filter([
                'email' => $order->user?->email,
                'phone' => $this->phone($order->user?->phone),
            ]),
            'company' => array_filter([
                'email' => $creds['company_email'] ?? null,
                'sno' => $creds['sno'] ?? null,
                'inn' => $creds['inn'] ?? null,
                'payment_address' => $creds['payment_address'] ?? null,
            ]),
            'items' => $items,
            'payments' => [[
                'type' => $isCash ? 0 : 1,
                'sum' => round($amount, 2),
            ]],
            'total' => round($amount, 2),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function lines(Order $order, string $paymentMethod, array $creds, bool $isFfd12): array
    {
        // Ставка для позиций без собственного НДС и для доставки. Задаётся
        // настройкой интеграции («Ставка НДС по умолчанию»), где с 01.01.2026
        // доступны vat22/vat122; дефолт кода намеренно оставлен прежним.
        $defaultVat = $creds['vat'] ?? 'vat20';
        $paymentObject = $creds['payment_object'] ?? 'commodity';
        $lines = [];
        foreach ($order->activeItems as $item) {
            [$quantity, $unit] = $this->quantityAndUnit($item);
            // Позиция, которую клиент целиком вернул курьеру при доставке (tg-14265):
            // delivery_back присылает её со статусом confirmed, но quantity = 0 и
            // total = 0. Товар не продан, а строку с нулевым количеством АТОЛ
            // отвергает вместе со всем чеком (code 32 NumberTooSmall, прод-заказ 363).
            if ($quantity <= 0) {
                continue;
            }
            $name = $item->title !== null && $item->title !== '' ? $item->title : 'Товар';
            $line = [
                'name' => mb_substr((string) $name, 0, 128),
                'price' => (float) $item->price,
                'quantity' => $quantity,
                'sum' => round((float) $item->total, 2),
                'payment_method' => $paymentMethod,
                'payment_object' => $isFfd12 ? $this->paymentObjectCode($paymentObject) : $paymentObject,
                'vat' => ['type' => $this->vatType($item->vat_rate ?? null, $defaultVat)],
            ];
            $this->applyUnit($line, $unit, $isFfd12);
            $lines[] = $line;
        }

        if (($order->delivery_cost ?? 0) > 0) {
            $line = [
                'name' => 'Доставка',
                'price' => (float) $order->delivery_cost,
                'quantity' => 1.0,
                'sum' => round((float) $order->delivery_cost, 2),
                'payment_method' => $paymentMethod,
                'payment_object' => $isFfd12 ? $this->paymentObjectCode('service') : 'service',
                'vat' => ['type' => $defaultVat],
            ];
            $this->applyUnit($line, null, $isFfd12);
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Количество и единица измерения строки чека по единице ХРАНЕНИЯ позиции заказа
     * (`order_items.measurement_unit`; для весовых storefront хранит граммы/миллилитры,
     * см. CreateOrderAction и OrderEventTransformer). Весовые уходят в чек в кг/л:
     * граммы → кг (÷1000, до 3 знаков — предел quantity в API АТОЛ), мл → л.
     * Штучные и позиции без единицы (старые заказы) — количество как есть, без единицы.
     *
     * Раньше весовые уходили в граммах: `price` получался ценой за грамм (0.60 ₽ у
     * голубики 378 ₽/630 г), а строки, где round(sum/граммы, 2) не сходился, схлопывались
     * в «1 × сумма» — чек выглядел по-разному от позиции к позиции.
     *
     * @return array{0: float, 1: 'kg'|'l'|null}
     */
    private function quantityAndUnit(OrderItem $item): array
    {
        $quantity = (float) $item->quantity;

        return match ($item->measurement_unit) {
            MeasurementUnit::Gram => [round($quantity / 1000, 3), 'kg'],
            MeasurementUnit::Kilogram => [$quantity, 'kg'],
            MeasurementUnit::Milliliter => [round($quantity / 1000, 3), 'l'],
            MeasurementUnit::Liter => [$quantity, 'l'],
            default => [$quantity, null],
        };
    }

    /**
     * Проставить единицу измерения в формате нужной версии ФФД.
     * `$unit === null` — штуки/единицы: в v5 `measure = 0`, в v4 тег не передаётся.
     *
     * @param  array<string, mixed>  $line
     * @param  'kg'|'l'|null  $unit
     */
    private function applyUnit(array &$line, ?string $unit, bool $isFfd12): void
    {
        unset($line['measure'], $line['measurement_unit']);
        if ($isFfd12) {
            $line['measure'] = $unit === null ? 0 : self::UNIT_CODES_FFD12[$unit];
        } elseif ($unit !== null) {
            $line['measurement_unit'] = self::UNIT_LABELS_FFD105[$unit];
        }
    }

    /**
     * Обобщённая позиция на всю сумму платежа — для чеков, в которых не осталось
     * ни одной активной позиции заказа.
     *
     * @return array<string, mixed>
     */
    private function fallbackLine(string $paymentMethod, array $creds, bool $isFfd12, float $amount): array
    {
        $paymentObject = $creds['payment_object'] ?? 'commodity';
        $line = [
            'name' => 'Оплата заказа',
            'price' => round($amount, 2),
            'quantity' => 1.0,
            'sum' => round($amount, 2),
            'payment_method' => $paymentMethod,
            'payment_object' => $isFfd12 ? $this->paymentObjectCode($paymentObject) : $paymentObject,
            'vat' => ['type' => $creds['vat'] ?? 'vat20'],
        ];
        if ($isFfd12) {
            $line['measure'] = 0;
        }

        return $line;
    }

    /**
     * APIv5 (ФФД 1.2): признак предмета расчёта (тег 1212) — числовой код,
     * а не строка как в APIv4. Маппим значения, доступные в настройках интеграции.
     */
    private function paymentObjectCode(string $object): int
    {
        return match ($object) {
            'commodity' => 1,
            'excise' => 2,
            'job' => 3,
            'service' => 4,
            default => 1,
        };
    }

    /**
     * Код ставки ЮKassa (`products.vat_rate`) → тип НДС АТОЛ.
     *
     * 11/12 — базовая ставка РФ с 01.01.2026 (22% и расчётная 22/122), их
     * присылает `lastochka_1c_parser::normalizeNdsToVatCode`. Пока кодов не было
     * в маппинге, позиция проваливалась в `$default` из настроек интеграции —
     * на проде это «без НДС», то есть товар с 22% пробивался как необлагаемый.
     * Типы `vat22`/`vat122` проверены на тестовом стенде АТОЛ (ФФД 1.05, APIv4).
     */
    private function vatType(?int $vatCode, string $default): string
    {
        return match ($vatCode) {
            1 => 'none', 2 => 'vat0', 3 => 'vat10', 4 => 'vat20', 5 => 'vat110', 6 => 'vat120',
            11 => 'vat22', 12 => 'vat122',
            default => $default,
        };
    }

    /**
     * Пропорционально подогнать сумму позиций под целевую сумму (остаток — на последнюю).
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function distribute(array $lines, float $target): array
    {
        $current = array_sum(array_map(static fn ($l) => $l['sum'], $lines));
        if ($current <= 0 || $lines === []) {
            return $lines;
        }
        $ratio = $target / $current;
        $acc = 0.0;
        $last = count($lines) - 1;
        foreach ($lines as $i => &$line) {
            if ($i === $last) {
                $line['sum'] = round($target - $acc, 2);
            } else {
                $line['sum'] = round($line['sum'] * $ratio, 2);
                $acc += $line['sum'];
            }
            $qty = $line['quantity'] > 0 ? $line['quantity'] : 1;
            $line['price'] = round($line['sum'] / $qty, 2);

            // ATOL требует равенства price * quantity == sum с точностью до копейки
            // (цена — 2 знака после запятой). Цена за единицу восстанавливается из
            // sum/quantity, и для весовой строки округление может не сойтись
            // (16.67 ₽/кг × 6 кг = 100.02 при sum 100.00) → ATOL отвечает кодом 16
            // "Неверная цена (сумма)". Если расхождение больше допуска — схлопываем
            // строку в 1 условную единицу: quantity=1.0, price=sum, единица измерения
            // сбрасывается на штуки («1 кг × 100 ₽» было бы ложью). Инвариант
            // выполняется точно, сумма чека не меняется.
            //
            // Сравнивать нужно ТОЧНОЕ произведение price × quantity, а не округлённое
            // предварительно до копеек: округление съедает до половины копейки в каждую
            // сторону, поэтому реальное расхождение до 1.5 коп читалось как «ровно 1 коп»
            // и проходило допуск. Так упал прод-чек 73 (заказ 194, 2026-09-10): «Тыква»
            // 56.39 × 2.51 = 141.5389 при sum 141.55 — это 1.11 коп, но round(14153.89)
            // = 14154 против 14155 давало ровно 1. Порог ККТ снят на стенде АТОЛ v4 для
            // той же строки: sum 141.53 (0.89 коп) и 141.54 (0.11 коп) → ФД выписан,
            // sum 141.52 (1.89 коп) и 141.55 (1.11 коп) → код 16; ровно 1 коп
            // (33.33 × 3 = 99.99 при sum 100.00) принимается.
            //
            // Произведение цены (2 знака) на количество (до 3 знаков) имеет пять знаков
            // после запятой, поэтому считаем в тысячных долях копейки и целыми числами:
            // копейки цены × тысячные доли количества. Сотых долей копейки мало —
            // прод-чек 291 (заказ 450, 2026-09-25): «Картофель» 34.39 × 2.082 = 71.59998
            // при sum 71.61 — это 1.002 коп, а round(715999.8) = 716000 давало ровно 1 коп.
            $sumMilliCents = (int) round($line['sum'] * 100) * 1000;
            $priceQtyMilliCents = (int) round($line['price'] * 100) * (int) round($qty * 1000);
            if (abs($priceQtyMilliCents - $sumMilliCents) > self::PRICE_SUM_TOLERANCE_CENTS * 1000) {
                $line['quantity'] = 1.0;
                $line['price'] = $line['sum'];
                $this->applyUnit($line, null, array_key_exists('measure', $line));
            }
        }

        return $lines;
    }

    private function phone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone);

        return $digits === '' ? null : '+'.$digits;
    }
}
