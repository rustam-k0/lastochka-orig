### Как запустить проект

- git clone https://gitlab.com/daib.elbuzarov/backend-template.git template
- cd template
- composer install
- php -r "file_exists('.env') || copy('.env.example', '.env');"
- создать базу прописать доступы в .env
- php artisan key:generate -\-ansi
- php artisan migrate:fresh -\-seed

### Команды для ide-helper

- php artisan ide-helper:generate
- php artisan ide:models -M
- php artisan ide-helper:meta
## Не редактируйте страницы админки созданные дескриптором вручную! 
## Редактируйте дескриптор
## Создание страниц для админки c помощью дескриптора

`php artisan page:create ${путь к дескриптору}`

### Правила описания json файла дескриптора
- Название `name` должно соответствовать папке в которой лежат её `Update` и `Create` мутации qraphql файлы (регистр не важен). Эти страницы должны быть в папке `resources/src/graphql/mutations/{name}`

Например:
```
  "tags": {
    "gqlLink": "@/graphql/queries/admin_tags.graphql"
  },
  form: {
    "tags": {
      "title": "Теги",
      "type": "select",
      "multiple": true,
      "labelName": "title",
      "idName": "id",
      "options": "tags"
    }
  }
```
- В `form` вписываются поля для заполнения в середине экрана`
- В `aside` вписываются поля для заполнения в сайдбаре

## Список полей `form` и `aside`
- string - обычное текстовое поле
- number - поле для ввода номера принимает `min` и `max`
- textarea - поле для ввода длинного текста принимает `rows`      
- article - поле для editorjs
- image - поле для загрузки изображения
- date - поле для даты
- file - поле для файла
- array - массив полей принимает все описанные здесь поля кроме себя
- select - выпадающий список с выбором нескольких вариантов 
- checkbox

## Backend генератор

### Название дескриптора должно совпадать с названием модели в нижнем регистре

### Дескриптор должен быть расположен по пути resources/descriptors/backend/

## Поля дескриптора:

name - название будущей модели, например:
```
{
  "name": "Tag",
  ...
}

```
columns - массив с полями сущности, например:
```
{
  "name": "Tag",
  "columns":[
    {
      "name":"title",
      "type":"string"
    },
    {
      "name":"description",
      "type":"text"
    }
  ]
  ...
}
```

Поле сущности состоит из следующих атрибутов:

- name - название поля (писать строго в snake case стиле, например: category_id)
- type - тип поля (список возможных типов см. ниже)
- comment - комментарий к полю (комментарий будет отображаться в graphql схеме)
- required - обязательное ли поле для заполнения (строго true или false)

#### Список стандартных типов полей:
- string (VARCHAR max - 255 символов)
- integer (INT)
- text (TEXT)
- boolean (BOOLEAN)
- timestamp (TIMESTAMP)

## Тип slug
- slug

Данный тип отвечает за создание ЧПУ (человекопонятный URL)

При создании поля с данным типом необходимо добавить дополнительный атрибут
related с названием поля, относительно которого будет создаваться slug

Например:
```
{
  "name": "News",
  "columns":[
    {
      "name":"title",
      "type":"string"
    },
    {
      "name":"link",
      "type":"slug",
      "related":"title"
    }
  ]
  ...
}
```

## Поля связи

Возможны следующие связи:
- hasOne
- hasMany
- morphToMany (morph связь)

Для создания связи в атрибуте type нужно указать необходимую
связь (например:"hasOne").

Так же необходимо добавить дополнительный атрибут "related", значение 
которого будет название модели, к которой осуществляется привязка
Например:
```
{
  "name": "News",
  "columns":[
    {
      "name":"category_id",
      "type":"hasOne",
      "related":"Category"
    },
    {
      "name":"tags",
      "type":"morphToMany",
      "related":"Tag"
    },
    {
      "name":"authors",
      "type":"hasMany",
      "related":"User"
    },
  ]
  ...
}
```

### Генерация модуля
Так же присутствует возможность генерации модуля.

Модуль - добавление связи не с помощью массива с id, а добавление
создание связанной сущности при создании основной в одном запросе.

Например, это необходимо в случае когда при создании новости требуется
не прикреплять теги, а добавлять их прямо на странице создания новости

Генерация модуля возможно при hasMany и morphToMany связях

Для генерации модуля необходимо добавить атрибут "is_module" со значением 
true

Например:
```
{
  "name": "News",
  "columns":[
    {
      "name":"title",
      "type":"string"
    },
    {
      "name":"tags",
      "type":"morphToMany",
      "is_module":true
    }
  ]
  ...
}
```

## Генерация изображений

Для генерации изображений необходимо в атрибуте "type" указать 
значение "image"

Так же необходимо добавить дополнительный атрибут "head_code" и в значении
указать код статуса изображения, который будет присваиваться при сохранении 
изображения

Например:
```
 "name": "News",
  "columns":[
    {
      "name":"title",
      "type":"string"
    },
    {
      "name":"img",
      "type":"image",
      "head_code":"CODE_HEAD_IMG"
    }
  ]
  ...
```

### Генерация нескольких изображений

Так же есть возможность сгенерировать несколько изображений

Для этого необходимо добавить дополнительный атрибут "multiple" 
со значением "true"

Например:
```
"name": "News",
  "columns":[
    {
      "name":"title",
      "type":"string"
    },
    {
      "name":"images",
      "type":"image",
      "head_code":"CODE_SLIDER_IMG",
      "multiple":true
    }
  ]
  ...
```

## Сущность одиночка

Иногда в проекте встречаются сущности одиночки,
которые изначально есть в проекте, например контактная информация с полями
телефон, email и т. д.

Как правило, такая сущность существует как одна запись в таблице

Чтобы определить сущность одиночку, необходимо добавить атрибут
"single_instance" со значением true

Например:
```
{
  "name": "Info",
  "single_instance": true,
  "columns": [
    {
      "name": "phone",
      "type": "string",
      "required": true
    },
    {
      "name": "address",
      "type": "string"
    }
  ]
}

```

## Ограничение запросов

В генераторе существует возможность ограничить запросы на получение, 
 добавление и редактирование

### Ограничение добавления и редактирования

Чтобы запретить добавление и редактирование необходимо добавить
атрибуты "update" или 'create' со значением false

Например:
```
{
  "name": "Tag",
  "create":false
  "update":false
  "columns": [
    {
      "name": "title",
      "type": "string",
      "required": true
    },
    {
      "name": "status_id",
      "type": "integer"
    }
  ]
  ...
}
```

Чтобы ограничить запросы на получение, необходимо добавить атрибут queries_public
со значением false

Например:
```
{
  "name": "Tag",
  "queries_public":false
  "columns": [
    {
      "name": "title",
      "type": "string",
      "required": true
    }
  ]
  ...
}
```

Чтобы сделать мутации создания/редактирования публичной, необходимо добавить атрибут mutation_public
со значением true

Например:
```
{
  "name": "Claim",
  "mutation_public":true,
  "columns": [
    {
      "name": "title",
      "type": "string",
      "required": true
    }
  ]
  ...
}
```

Так же можно скрыть необходимые поля при мутациях добавления/редактирования

Нужно добавить к полю атрибуты create или update со значением false, тогда 
они не появятся в мутациях

Например
```
{
  "name": "Claim",
  "columns": [
    {
      "name": "admin_comment",
      "type": "text",
      "create":false,
      "update":false
    }
  ]
  ...
}
```

### Фильтрация

При генерации запросов можно добавить параметры для отправки 
для этого необходимо добавить в дескриптор массив filters со списком атрибутов

Поле фильтра состоит из след. атрибутов:
- name - название фильтра
- value - тип фильтра (grapqhl тип пример: Int, Boolean, [String])

Например:
```
{
  "name": "Tag",
  "queries_public":false
  "columns": [
    {
      "name": "title",
      "type": "string",
      "required": true
    },
    
    {
      "name": "is_main",
      "type": "boolean"
    },
  ],
  "filters":[
    {
    "name":"is_main",
    "value":"Boolean"
    },
    {
    "name":"exclude_id",
    "value":"[Int]"
    }
  ]
  ...
}
```
