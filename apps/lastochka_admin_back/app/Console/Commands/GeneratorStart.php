<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command as CommandAlias;

class GeneratorStart extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'generator:start';

  /**
   * Папка со страницами генератора
   * @var string
   */
  protected $pagesFolder = 'generator/descriptors/frontend/pages/';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Запуск генератора';

  /**
   * Create a new command instance.
   *
   * @return void
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Execute the console command.
   * TODO сохранение в адрес-баре состояния страницы списка
   * @return int
   */
  public function handle()
  {
    $this->generateGlobalSettings();
    $this->info("<===============================================>");
    $this->info("Очистка от сгенерированых страниц");
    File::deleteDirectory("resources/src/views/generated");
    $this->info("Очистка от сгенерированых модалок");
    File::deleteDirectory("resources/src/components/modals/components/generated");
    $this->info("Очистка сгенерированых GraphQL файлов");
    File::deleteDirectory("resources/src/graphql/generated/");
    $this->info("Сканирование папки $this->pagesFolder");
    $files = scandir($this->pagesFolder);
    $files = array_diff($files, array('.', '..'));
    $this->info("<===============================================>");
    foreach($files as $file) {
      if (str_ends_with($file, '.json') && !str_contains($file, '.old.') && !str_contains($file, '.example.')) {
        $descriptor = str_replace('.json', '', $file);
        $this->info("Запуск дескриптора модалки $descriptor");
        $data = json_decode(file_get_contents(base_path("{$this->pagesFolder}{$descriptor}.json")), true);
        if ($data['type'] === 'modal') {
          $this->createModal($data);
        } else {
          $this->createPage($data);
        }
      }
    }
    $this->info("Автоформатирование проекта с помощью eslint. Подождите...");
    print shell_exec('eslint resources/src/ --ext .vue --fix');
    print shell_exec('eslint resources/src/router.js --fix');
    return CommandAlias::SUCCESS;
  }

  /**
   * Создание модалки
   * @param $data
   * @return void
   */
  public function createModal($data)
  {
    // Рендер по шаблону страницы списка
    if ($data['type'] === 'list') {
      $template = view('frontend.list.structure', ['data' => $data])->render();
    }

    // Создание graphql файлов
    foreach ($data['graphql'] as $graphql) {
      $this->createGraphqlFile($graphql, $data);
    }

    $template = view('frontend.modal.structure', ['data' => $data])->render();

    // Сохранение
    Storage::disk('resources')->put("components/modals/components/generated/{$data['name']}.vue", $template);
    $this->info("Модалка сгенерирована");
    $this->info("<===============================================>");
  }

  public function generateGlobalSettings()
  {
    $this->info("Чтение глобальных настроек");
    $data = json_decode(file_get_contents(base_path("generator/descriptors/frontend/global.json")), true);

    $this->info("Генерация роутов");
    $template = view('frontend.router', ['data' => $data])->render();
    Storage::disk('resources')->put("router.js", $template);

    $this->info("Генерация ссылок в модалке добавления контента");
    $template = view('frontend.add-modal', ['data' => $data])->render();
    Storage::disk('resources')->put("components/modals/components/AddModal.vue", $template);

    $this->info("Генерация кнопок в хедере");
    $template = view('frontend.header', ['data' => $data])->render();
    Storage::disk('resources')->put("components/header/index.vue", $template);
  }

  /**
   * Создание страницы
   * @param $data
   * @return void
   */
  public function createPage($data)
  {
    // Создание graphql файлов
    foreach ($data['graphql'] as $graphql) {
      $this->createGraphqlFile($graphql, $data);
    }

    // Рендер по шаблону страницы добавления редактирования
    if ($data['type'] === 'form') {
      $template = view('frontend.page.structure', ['data' => $data])->render();
    }
    // Рендер по шаблону страницы списка
    if ($data['type'] === 'list') {
      $template = view('frontend.list.structure', ['data' => $data])->render();
    }

    // Сохранение
    $folderName = strtolower($data['route'] ?? $data['name']);
    Storage::disk('resources')->put("views/generated/$folderName/index.vue", $template);
    $this->info("Страница сгенерирована");
    $this->info("<===============================================>");
  }

  /**
   * Создание graphql запрос файла
   * @param $graphql
   * @param $page
   * @return void
   */
  public function createGraphqlFile($graphql, $page)
  {
    $this->info("Создание graphql файла {$graphql['name']} для страницы {$page['name']}");
    $template = view('frontend.graphql.markup', ['data' => $graphql])->render();

    $folderName = strtolower($page['name']);

    if (isset($graphql['independent']) && $graphql['independent'] && $graphql['type'] === 'query') {
      Storage::disk('resources')->put("graphql/queries/{$graphql['name']}.graphql", $template);
    } else if (isset($graphql['independent']) && $graphql['independent'] && $graphql['type'] === 'mutation') {
      Storage::disk('resources')->put("graphql/mutations/{$graphql['name']}.graphql", $template);
    } else {
      Storage::disk('resources')->put("graphql/generated/{$folderName}/{$graphql['name']}.graphql", $template);
    }
  }

  /**
   * Возвращает все поля (вложенные в друг друга тоже) как один массив
   * @param $array
   * @param bool $parseArray
   * @return Collection
   */
  public static function getAllInputs($array, $parseArray = true)
  {
    $inputs = collect();
    foreach ($array as $item) {
      if ($item['type'] === 'column' || $item['type'] === 'row') {
        $inputs->push(...self::getAllInputs($item['fields']));
      } else if ($item['type'] === 'array' && $parseArray) {
        $inputs->push($item);
        $inputs->push(...self::getAllInputs($item['fields']));
      } else {
        $inputs->push($item);
      }
    }
    return $inputs;
  }

  /**
   * Отдает массив полей без дубликатов
   * @param $data
   * @return Collection
   */
  public static function getInputsUnique($data)
  {
    $inputs = collect();
    if (isset($data['fields'])) {
      $inputs->push(...self::getAllInputs($data['fields']));
    }
    if (isset($data['form'])) {
      $inputs->push(...self::getAllInputs($data['form']));
    }
    if (isset($data['aside'])) {
      $inputs->push(...self::getAllInputs($data['aside']));
    }
    return $inputs->unique('type');
  }

  /**
   * Отдает код для created apollo запроса на обработку
   * полученных данных для всех полей в массиве $data
   * @param $data
   * @param $array
   * @return string
   */
  public static function collectReceiveFixes($data, $array)
  {
    $receiveFixes = '';
    foreach (self::getAllInputs($data, false) as $item) {
      $receiveFixes .= view('frontend.inputs.' . $item['type'] . '.receive', [
        'data' => $item,
        'array' => $array,
      ])->render();
    }
    return $receiveFixes;
  }

  /**
   * Отдает код с полями для data() для полей ввода
   * из полученного масива
   * @param $data
   * @param $array
   * @return string
   */
  public static function collectDataFields($data, $array)
  {
    $dataFields = '';
    foreach (self::getAllInputs($data) as $item) {
      $dataFields .= view('frontend.inputs.' . $item['type'] . '.data', [
        'data' => $item,
        'array' => $array,
      ])->render();
    }
    return $dataFields;
  }

  /**
   * Отдает код с полями формы в data() для полей ввода из полученного массива
   * @param $data
   * @return string
   */
  public static function collectFormFields($data)
  {
    $dataFields = '';
    foreach (self::getAllInputs($data, false) as $item) {
      $dataFields .= view('frontend.inputs.' . $item['type'] . '.field', [
        'data' => $item
      ])->render();
    }
    return $dataFields;
  }

  /**
   * Отдает код для submit метода для форматирование
   * данных для отправки
   * @param $data
   * @param $array
   * @return string
   */
  public static function collectSendFixes($data, $array)
  {
    $sendFixes = '';
    foreach (self::getAllInputs($data, false) as $item) {
      $sendFixes .= view('frontend.inputs.' . $item['type'] . '.send', [
        'data' => $item,
        'array' => $array,
      ])->render();
    }
    return $sendFixes;
  }

  /**
   * Рекурсивная сборка полей для тела graphql запроса
   * создает любую вложенность
   * @param $data
   * @return string
   */
  public static function buildGraphqlBody($data)
  {
    $body = '';
    foreach ($data as $key => $item) {
      if (gettype($item) === 'boolean') {
        $body .= "$key ";
      } else {
        $body .= "{$key} { ";
        $body .= self::buildGraphqlBody($item);
        $body .= "} ";
      }
    }
    return $body;
  }
}
