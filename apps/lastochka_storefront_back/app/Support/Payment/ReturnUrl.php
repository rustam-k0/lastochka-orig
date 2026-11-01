<?php

declare(strict_types=1);

namespace App\Support\Payment;

/**
 * Адрес возврата после оплаты (ЮKassa `confirmation.return_url`).
 *
 * Клиент передаёт свой адрес возврата прямо в запросе (создание заказа,
 * повторная оплата, привязка карты), поэтому значение ОБЯЗАТЕЛЬНО проверяется
 * по белому списку: без проверки любой желающий увёл бы пользователя после
 * оплаты на чужой домен (open redirect в связке с фишингом), причём переход
 * выглядел бы как возврат от банка.
 *
 * Разрешены:
 *  - относительный путь `/payment/success?orderId=1` — склеивается с базой
 *    дефолтного адреса возврата (адрес витрины);
 *  - абсолютный http(s)-адрес, чей хост входит в белый список
 *    `services.yookassa.allowed_return_hosts` (поддерживается `*.example.ru` —
 *    матчит и сам домен, и любые его поддомены);
 *  - диплинк мобильного приложения, если его схема явно перечислена
 *    в `services.yookassa.allowed_return_schemes` (например `mendevapp`).
 *
 * Хост и схема дефолтного адреса возврата, а также APP_URL разрешены всегда —
 * иначе настройка «по умолчанию» ломала бы саму себя.
 */
final class ReturnUrl
{
    /** Лимит ЮKassa на длину confirmation.return_url. */
    public const MAX_LENGTH = 2048;

    /** Схемы, недопустимые при любых настройках (XSS через redirect). */
    private const FORBIDDEN_SCHEMES = ['javascript', 'data', 'file', 'blob', 'vbscript'];

    /** Схемы, для которых обязателен хост из белого списка. */
    private const WEB_SCHEMES = ['http', 'https'];

    /**
     * Итоговый адрес для передачи в ЮKassa: проверенный адрес клиента,
     * а если он не передан (или не прошёл проверку) — дефолтный из конфига.
     */
    public static function resolve(?string $candidate): string
    {
        return self::normalize($candidate) ?? self::defaultUrl();
    }

    /**
     * Прошёл ли адрес проверку. null и пустая строка — «не передан», это
     * допустимо: сработает дефолт.
     */
    public static function isAllowed(?string $candidate): bool
    {
        if ($candidate === null || trim($candidate) === '') {
            return true;
        }

        return self::normalize($candidate) !== null;
    }

    /**
     * Привести адрес клиента к абсолютному виду или вернуть null,
     * если он не разрешён.
     */
    public static function normalize(?string $candidate): ?string
    {
        if ($candidate === null) {
            return null;
        }

        $url = trim($candidate);

        if ($url === '' || mb_strlen($url) > self::MAX_LENGTH) {
            return null;
        }

        // Управляющие символы (CR/LF и прочее) — попытка подмешать своё
        // в адрес; в валидном URL их не бывает.
        if (preg_match('/[\x00-\x1F\x7F]/u', $url) === 1) {
            return null;
        }

        // «//evil.tld» — это не путь, а внешний хост со схемой текущей страницы.
        if (str_starts_with($url, '//')) {
            return null;
        }

        // Относительный путь витрины: домен подставляем сами, подменить его нельзя.
        if (str_starts_with($url, '/')) {
            return self::baseUrl().$url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'])) {
            return null;
        }

        $scheme = mb_strtolower((string) $parts['scheme']);

        if (in_array($scheme, self::FORBIDDEN_SCHEMES, true)) {
            return null;
        }

        if (! in_array($scheme, self::allowedSchemes(), true)) {
            return null;
        }

        if (in_array($scheme, self::WEB_SCHEMES, true)) {
            // user:pass@host маскирует настоящий хост в адресной строке.
            if (isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }

            $host = isset($parts['host']) ? mb_strtolower((string) $parts['host']) : '';

            if ($host === '' || ! self::hostAllowed($host)) {
                return null;
            }
        }

        return $url;
    }

    /**
     * Дефолтный адрес возврата. Относительное значение в конфиге склеивается
     * с APP_URL: ЮKassa принимает только абсолютный адрес.
     */
    public static function defaultUrl(): string
    {
        $configured = trim((string) config('services.yookassa.return_url', ''));

        if ($configured === '' || $configured === '/') {
            return self::appBaseUrl().'/';
        }

        if (str_starts_with($configured, '/') && ! str_starts_with($configured, '//')) {
            return self::appBaseUrl().$configured;
        }

        return $configured;
    }

    /**
     * Схемы, разрешённые для адреса возврата (в нижнем регистре).
     *
     * @return list<string>
     */
    public static function allowedSchemes(): array
    {
        $schemes = self::listFromConfig('services.yookassa.allowed_return_schemes');

        $schemes[] = 'https';
        $schemes[] = self::schemeOf(self::defaultUrl());
        $schemes[] = self::schemeOf(self::appBaseUrl());

        $schemes = array_filter(array_map(
            static fn (string $scheme): string => mb_strtolower(trim($scheme, ': /')),
            $schemes,
        ));

        $schemes = array_diff($schemes, self::FORBIDDEN_SCHEMES);

        return array_values(array_unique($schemes));
    }

    /**
     * Хосты, разрешённые для абсолютных http(s)-адресов (в нижнем регистре).
     *
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $hosts = self::listFromConfig('services.yookassa.allowed_return_hosts');

        $hosts[] = (string) parse_url(self::defaultUrl(), PHP_URL_HOST);
        $hosts[] = (string) parse_url(self::appBaseUrl(), PHP_URL_HOST);

        $hosts = array_filter(array_map(
            static fn (string $host): string => mb_strtolower(trim($host)),
            $hosts,
        ));

        return array_values(array_unique($hosts));
    }

    private static function hostAllowed(string $host): bool
    {
        foreach (self::allowedHosts() as $allowed) {
            if ($allowed === $host) {
                return true;
            }

            // «*.example.ru» — сам домен и любые его поддомены.
            if (str_starts_with($allowed, '*.')) {
                $domain = mb_substr($allowed, 2);

                if ($domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * База (схема + хост + порт) для склейки относительных путей —
     * берётся из дефолтного адреса возврата, то есть с домена витрины.
     */
    private static function baseUrl(): string
    {
        $parts = parse_url(self::defaultUrl());

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return self::appBaseUrl();
        }

        $base = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $base .= ':'.$parts['port'];
        }

        return $base;
    }

    private static function appBaseUrl(): string
    {
        return rtrim((string) config('app.url', 'http://localhost'), '/');
    }

    private static function schemeOf(string $url): string
    {
        return (string) parse_url($url, PHP_URL_SCHEME);
    }

    /**
     * Значение конфига как список: принимается и массив, и строка «a,b,c».
     *
     * @return list<string>
     */
    private static function listFromConfig(string $key): array
    {
        $value = config($key, []);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
