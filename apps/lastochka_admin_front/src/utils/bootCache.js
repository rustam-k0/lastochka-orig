// Кэш boot-конфигов (меню, модули, чекбоксы, поля, брендинг) в localStorage.
// Паттерн stale-while-revalidate: интерфейс рисуется мгновенно из кэша,
// свежие данные подтягиваются фоном и обновляют store + кэш к следующему рендеру.
// Кэш опционален: недоступный localStorage (квота, приватный режим) деградирует
// в обычную сетевую загрузку.
const PREFIX = "bootCache:";
const VERSION = 1;

export function readBootCache(key) {
  try {
    const raw = localStorage.getItem(PREFIX + key);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    return parsed && parsed.v === VERSION ? parsed.data : null;
  } catch (e) {
    return null;
  }
}

export function writeBootCache(key, data) {
  try {
    localStorage.setItem(PREFIX + key, JSON.stringify({ v: VERSION, data }));
  } catch (e) {
    // ignore
  }
}
