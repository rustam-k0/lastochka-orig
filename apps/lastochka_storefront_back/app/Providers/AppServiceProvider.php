<?php

namespace App\Providers;

use App\Events\OrderStatusChangedEvent;
use App\Listeners\SendCourierDepartedNotification;
use App\Models\Address;
use App\Models\Product;
use App\Observers\AddressObserver;
use App\Observers\ProductObserver;
use App\RabbitMQ\Debug\RabbitDebugService;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\DeliverySystemClient;
use App\Services\Delivery\FakeDeliveryClient;
use App\Services\Geo\Contracts\IpGeolocatorInterface;
use App\Services\Geo\FakeIpGeolocator;
use App\Services\Geo\IpApiGeolocator;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\MeilisearchClient;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use App\Services\SlotsApi\SlotsApiClient;
use App\Services\Settings\ModuleConfigService;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Contract\Messaging;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Ключ сессии, в котором запоминается успешная проверка токена документации.
     */
    private const API_DOCS_SESSION_KEY = 'api_docs_access';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // В тестах используем FakeDeliveryClient
        if ($this->app->runningUnitTests()) {
            $this->app->singleton(DeliveryClientInterface::class, FakeDeliveryClient::class);
            $this->app->singleton(SlotsApiClientInterface::class, FakeSlotsApiClient::class);
            $this->app->singleton(IpGeolocatorInterface::class, FakeIpGeolocator::class);
        } else {
            $this->app->bind(DeliveryClientInterface::class, DeliverySystemClient::class);
            $this->app->bind(SlotsApiClientInterface::class, SlotsApiClient::class);
            $this->app->bind(IpGeolocatorInterface::class, IpApiGeolocator::class);
        }

        // RabbitMQ Debug Service
        $this->app->singleton(RabbitDebugService::class);

        // Singleton — ресурсы зовут app(ModuleConfigService::class) на каждый
        // товар листинга; per-instance мемоизация работает только на общем инстансе
        $this->app->singleton(\App\Services\Settings\ModuleConfigService::class);

        // Scoped — кеш процента начисления бонусов живёт один запрос:
        // ProductResource зовёт резолвер на каждый товар выдачи
        $this->app->scoped(\App\Services\Bonus\StoreBonusEarnResolver::class);

        // Scoped — состав сравнения пользователя загружается один раз за запрос
        $this->app->scoped(\App\Services\Comparison\UserComparisonResolver::class);

        // Scoped — лесенки «цены от количества» (MID-308) грузятся пачкой один раз
        // за запрос: резолвер цены, ресурсы и корзина делят один кеш лесенок
        $this->app->scoped(\App\Services\Pricing\ProductTierLadderProvider::class);
        $this->app->scoped(\App\Services\Pricing\ProductPriceResolver::class);

        // Search Client
        if ($this->app->runningUnitTests()) {
            $this->app->singleton(SearchClientInterface::class, FakeSearchClient::class);
        } else {
            $this->app->singleton(SearchClientInterface::class, MeilisearchClient::class);
        }

        // Payment Gateway. В mock-режиме (YOOKASSA_MOCK_MODE) поверх боевого
        // шлюза встаёт декоратор: запросы в ЮKassa уходят как обычно, но
        // подтверждения платежей не ждём — вебхука на стенде нет.
        $this->app->bind(\App\Contracts\PaymentGateway::class, function ($app) {
            $gateway = $app->make(\App\Services\Payment\YooKassaService::class);

            return \App\Support\Payment\PaymentMockMode::enabled()
                ? new \App\Services\Payment\MockPaymentGateway($gateway)
                : $gateway;
        });

        // Fiscalizer — выбирается по активному драйверу интеграции 'fiscal'
        $this->app->bind(\App\Contracts\Fiscalizer::class, function ($app) {
            $driver = \App\Services\IntegrationConfigService::getDriver('fiscal');

            return $driver === 'atol'
                ? $app->make(\App\Services\Payment\Fiscal\AtolFiscalizer::class)
                : $app->make(\App\Services\Payment\Fiscal\YooKassaFiscalizer::class);
        });

        // Yandex Delivery
        if ($this->app->runningUnitTests()) {
            $this->app->singleton(
                \App\Services\YandexDelivery\YandexGoClientInterface::class,
                \App\Services\YandexDelivery\FakeYandexGoClient::class,
            );
        } else {
            $this->app->bind(
                \App\Services\YandexDelivery\YandexGoClientInterface::class,
                \App\Services\YandexDelivery\YandexGoClient::class,
            );
        }

        // Firebase Services — bind (не singleton): credentials читаются из БД
        // при каждом резолве, чтобы свежезагруженные через админку настройки
        // подхватывались без рестарта воркеров.
        $this->app->bind(Factory::class, function ($app) {
            $factory = new Factory;
            $credentials = $this->resolveFirebaseCredentials();

            if ($credentials) {
                $factory = $factory->withServiceAccount($credentials);
            }

            if ($databaseUrl = config('firebase.projects.app.database.url')) {
                $factory = $factory->withDatabaseUri($databaseUrl);
            }

            return $factory;
        });

        $this->app->bind(Messaging::class, function ($app) {
            return $app->make(Factory::class)->createMessaging();
        });
    }

    /**
     * Разрешает Firebase service account credentials.
     *
     * Приоритет: запись `integration_configs(integration_type='client_app', driver='firebase', is_active=1)`,
     * далее — fallback на файл из `FIREBASE_CREDENTIALS`.
     *
     * @return array<string, mixed>|null
     */
    private function resolveFirebaseCredentials(): ?array
    {
        try {
            $config = \App\Models\IntegrationConfig::ofType('client_app')
                ->active()
                ->first();

            if ($config) {
                $credentials = $config->credentials ?? [];
                $driverCredentials = $credentials['firebase'] ?? $credentials;
                $json = $driverCredentials['service_account_json'] ?? null;

                if ($json) {
                    $decoded = is_string($json) ? json_decode($json, true) : $json;

                    if (is_array($decoded) && ! empty($decoded['project_id'])) {
                        return $decoded;
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to load Firebase credentials from IntegrationConfig', [
                'error' => $e->getMessage(),
            ]);
        }

        $credentialsPath = config('firebase.projects.app.credentials');

        if (is_string($credentialsPath) && $credentialsPath !== '' && file_exists($credentialsPath)) {
            $decoded = json_decode((string) file_get_contents($credentialsPath), true);

            if (is_array($decoded) && ! empty($decoded['project_id'])) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // За https-терминирующей проксей Laravel видит запрос как http и строит
        // http-URL (server URL в Scramble-доке, пути картинок) → mixed content.
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        Address::observe(AddressObserver::class);
        Product::observe(ProductObserver::class);

        // Сетевой каталог: карточка товара глобальна — дедуп по (source,
        // external_id) без shop_code. Мерж вынесен в хелпер (он пишет флаг
        // в обе стороны, чтобы выключение модуля снимало global_scope с
        // запечённого config:cache). Console-safe: isNetworkCatalogEnabled()
        // сам ловит Throwable, при недоступной БД флаг просто false.
        \App\RabbitMQ\Support\NetworkCatalog::applyGlobalScopeConfig();

        // Event Listeners
        Event::listen(OrderStatusChangedEvent::class, SendCourierDepartedNotification::class);

        $this->configureApiDocsAccess();

        Scramble::configure()
            ->routes(fn (RoutingRoute $route): bool => $this->isApiDocsRouteVisible($route))
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                        ->setDescription('Laravel Sanctum. Передавайте Bearer-токен в заголовке `Authorization`.')
                );

                // deliveryDate/pickupDate — условные поля ($this->when по
                // array_key_exists): Scramble не распознаёт это условие и
                // ошибочно кладёт их в required. Контракт условности закреплён
                // тестами (ProductCardDeliveryDatesTest) — правим схему.
                $productSchema = $openApi->components->schemas['ProductResource'] ?? null;
                if ($productSchema !== null) {
                    $productSchema->type->required = array_values(array_diff(
                        $productSchema->type->required,
                        ['deliveryDate', 'pickupDate'],
                    ));
                }

                $this->removeDisabledModuleProperties($openApi);
            })
            ->withOperationTransformers(function (Operation $operation, RouteInfo $routeInfo) {
                $middleware = $routeInfo->route->gatherMiddleware();

                if (in_array('auth:sanctum', $middleware, true)) {
                    $operation->addSecurity(new SecurityRequirement(['http' => []]));

                    return;
                }

                if (in_array(\App\Http\Middleware\OptionalAuth::class, $middleware, true)) {
                    $operation->addSecurity(new SecurityRequirement(['http' => []]));
                    $operation->addSecurity(new SecurityRequirement([]));
                }
            });
    }

    /**
     * Доступ к OpenAPI-документации вне local-окружения.
     *
     * Scramble закрывает `/docs/api` и `/docs/api.json` middleware
     * RestrictedDocsAccess: на не-local окружении он спрашивает gate
     * `viewApiDocs`. Пускаем по секретному токену из `.env` (`API_DOCS_TOKEN`),
     * который принимается в query-параметре `key`, заголовке `X-Docs-Key`
     * или как Bearer. Удачная проверка запоминается в сессии, чтобы
     * `api.json` открывался и без повторной передачи токена.
     */
    private function configureApiDocsAccess(): void
    {
        Gate::define('viewApiDocs', function (?Authenticatable $user = null): bool {
            $token = (string) config('api_docs.token');

            if ($token === '') {
                return false;
            }

            $request = request();

            $provided = $request->query('key')
                ?? $request->header('X-Docs-Key')
                ?? $request->bearerToken();

            if (is_string($provided) && $provided !== '' && hash_equals($token, $provided)) {
                if ($request->hasSession()) {
                    $request->session()->put(self::API_DOCS_SESSION_KEY, true);
                }

                return true;
            }

            return $request->hasSession()
                && $request->session()->get(self::API_DOCS_SESSION_KEY) === true;
        });
    }

    /**
     * Попадает ли роут в OpenAPI-документацию.
     *
     * Повторяет базовый матчер Scramble (префикс `scramble.api_path` и домен)
     * и дополнительно прячет то, чего в этом проекте нет: служебные ручки,
     * пути выключенных модулей и маски из `API_DOCS_HIDDEN_PATHS`.
     */
    private function isApiDocsRouteVisible(RoutingRoute $route): bool
    {
        $apiPath = (string) config('scramble.api_path', 'api');
        $expectedDomain = config('scramble.api_domain');

        if ($apiPath !== '' && ! Str::startsWith($route->uri, $apiPath)) {
            return false;
        }

        if ($expectedDomain && $route->getDomain() !== $expectedDomain) {
            return false;
        }

        $path = ltrim(Str::replaceStart($apiPath, '', $route->uri), '/');

        foreach ($this->apiDocsHiddenPatterns() as $pattern) {
            if (Str::is($pattern, $path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Маски путей, скрытых из документации на этом стенде.
     *
     * @return list<string>
     */
    private function apiDocsHiddenPatterns(): array
    {
        return array_merge(
            array_values((array) config('api_docs.internal_paths', [])),
            array_values((array) config('api_docs.hidden_paths', [])),
            $this->apiDocsDisabledModulePatterns(),
        );
    }

    /**
     * Маски путей модулей, выключенных в project_modules.
     *
     * При недоступных настройках считаем модули выключенными: документация
     * скорее не покажет существующую ручку, чем покажет отсутствующую фичу.
     *
     * @return list<string>
     */
    private function apiDocsDisabledModulePatterns(): array
    {
        $modulePaths = (array) config('api_docs.module_paths', []);
        $marketplacePaths = array_values((array) config('api_docs.marketplace_paths', []));

        try {
            $modules = app(ModuleConfigService::class);

            $hidden = [];

            foreach ($modulePaths as $moduleKey => $paths) {
                if (! $modules->isModuleEnabled((string) $moduleKey)) {
                    $hidden = array_merge($hidden, array_values((array) $paths));
                }
            }

            if (! $modules->isMarketplaceMode()) {
                $hidden = array_merge($hidden, $marketplacePaths);
            }

            return $hidden;
        } catch (\Throwable) {
            $hidden = $marketplacePaths;

            foreach ($modulePaths as $paths) {
                $hidden = array_merge($hidden, array_values((array) $paths));
            }

            return $hidden;
        }
    }

    /**
     * Убирает из схем поля выключенных модулей.
     *
     * Такое поле отсутствует в ответе (ресурс отдаёт его через `when`),
     * поэтому в документации ему тоже не место. Список — `api_docs.module_properties`
     * в формате «Схема.поле».
     */
    private function removeDisabledModuleProperties(OpenApi $openApi): void
    {
        $moduleProperties = (array) config('api_docs.module_properties', []);

        if ($moduleProperties === []) {
            return;
        }

        foreach ($moduleProperties as $moduleKey => $properties) {
            if ($this->isApiDocsModuleEnabled((string) $moduleKey)) {
                continue;
            }

            foreach ((array) $properties as $reference) {
                [$schemaName, $property] = array_pad(explode('.', (string) $reference, 2), 2, null);

                if ($property === null) {
                    continue;
                }

                $schema = $openApi->components->schemas[$schemaName] ?? null;

                if ($schema === null) {
                    continue;
                }

                unset($schema->type->properties[$property]);

                $schema->type->required = array_values(array_diff($schema->type->required, [$property]));
            }
        }
    }

    /**
     * Включён ли модуль с точки зрения документации.
     *
     * Настройки могут быть недоступны (консоль, отсутствующая таблица) —
     * тогда считаем модуль выключенным: лучше не показать существующую
     * ручку, чем показать отсутствующую фичу.
     */
    private function isApiDocsModuleEnabled(string $moduleKey): bool
    {
        try {
            return app(ModuleConfigService::class)->isModuleEnabled($moduleKey);
        } catch (\Throwable) {
            return false;
        }
    }
}
