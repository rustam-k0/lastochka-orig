/**
 * Диапазоны стоимости доставки зоны (MID-280): ступени «от суммы заказа from_amount: доставка price»,
 * суммы в целых рублях, price = 0 означает бесплатную доставку.
 * Строка редактора: { from_amount: { value, errors }, price: { value, errors } }; ошибки сервера
 * delivery_price_ranges.N.from_amount и delivery_price_ranges.N.price раскладывает parseGqlErrors.
 */

// Верхняя граница GraphQL Int и колонок бэка
const MAX_RUBLES = 2147483647;

export const INVALID_RUBLES_MESSAGE = "Укажите целое число от 0";

// Ступени зоны из API по возрастанию суммы. Зона без ступеней, но с порогом бесплатной доставки
// (так хранилось до MID-280) читается как одна бесплатная ступень, как на бэке.
export function zoneDeliveryPriceRanges(zone) {
  const ranges = Array.isArray(zone?.delivery_price_ranges) ? zone.delivery_price_ranges : [];
  if (ranges.length) {
    return [...ranges].sort((a, b) => a.from_amount - b.from_amount);
  }
  if (zone?.free_delivery_from !== null && zone?.free_delivery_from !== undefined) {
    return [{ from_amount: zone.free_delivery_from, price: 0 }];
  }
  return [];
}

export function deliveryPriceRangeRow(fromAmount = "", price = "") {
  return { from_amount: { value: fromAmount, errors: [] }, price: { value: price, errors: [] } };
}

export function deliveryPriceRangeRows(ranges) {
  return ranges.map((range) => deliveryPriceRangeRow(String(range.from_amount), String(range.price)));
}

// Целое число рублей от 0 из поля ввода (пробелы разрядов допускаются), иначе null.
export function parseRubles(raw) {
  const text = String(raw ?? "").replace(/\s/g, "");
  if (!/^\d+$/.test(text)) return null;
  const number = parseInt(text, 10);
  return number <= MAX_RUBLES ? number : null;
}

// Сумма бесплатной ступени (цена 0) среди строк редактора строкой для поля ввода либо "".
export function freeDeliveryFromRows(rows) {
  const free = rows.find(
    (row) => parseRubles(row.price.value) === 0 && parseRubles(row.from_amount.value) !== null
  );
  return free ? String(parseRubles(free.from_amount.value)) : "";
}

// Краткая запись для таблицы зон: «от 1 000 ₽: 100 ₽; от 3 000 ₽: бесплатно» или «—».
export function formatDeliveryPriceRanges(ranges, formatPrice) {
  if (!ranges.length) return "—";
  return ranges
    .map(
      (range) =>
        `от ${formatPrice(range.from_amount)}: ${range.price === 0 ? "бесплатно" : formatPrice(range.price)}`
    )
    .join("; ");
}
