# Отображение магазина в карточке товара — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** В карточке товара (создание и редактирование, простой и вариативный товар) показать нередактируемое название магазина, к которому относится товар.

**Architecture:** Магазин уже однозначно определяется параметром `shop_id` в URL формы товара (`/admin/product/:shop_id?/:id?`, `/admin/configurable-product/:shop_id?/:id?`) — он передаётся во всех точках входа. Расширяем уже существующий GraphQL-запрос `options.graphql` (он и так вызывается в обоих режимах — create и edit — обоими компонентами формы) полем `shop(id: $shop_id) { id title }`, которое уже есть в схеме. Изменений бэкенда не требуется.

**Tech Stack:** Vue 3 (Options API), Apollo Client, Stylus.

## Global Constraints

- Проект без TDD-инфраструктуры для Vue-компонентов: тестовых файлов `*.spec.js`/`*.test.js` в репозитории нет (`@vue/test-utils` установлен, но не используется). Верификация каждого шага — `npm run lint` / `npm run build`, финальная верификация — ручной проход в браузере на dev-домене (`alladin.comrades.dev`), согласно принятому в проекте порядку проверки UI-изменений.
- Показываем только название магазина (`shop.title`), без кода/id — поле информационное, не input, редактировать негде.
- Список товаров (`src/views/products/index.vue`) и бэкенд (`aladdin_admin_back`) не трогаем — вне scope.
- Коммитить только в feature-ветку, не в `master` напрямую (правило проекта). Ветка для этой фичи: `feat/product-card-shop-label`.

---

### Task 0: Создать feature-ветку

**Files:** нет

- [ ] **Step 1: Убедиться, что рабочая копия чистая и создать ветку**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/aladdin/aladdin_admin_front
git status
git checkout -b feat/product-card-shop-label
```

Expected: `git status` показывает "nothing to commit, working tree clean" (если есть незакоммиченные изменения не по этой задаче — остановиться и сообщить пользователю). После checkout — `git branch --show-current` печатает `feat/product-card-shop-label`.

---

### Task 1: Расширить GraphQL-запрос options.graphql полем shop

**Files:**
- Modify: `src/graphql/pages/Product/options.graphql`

**Interfaces:**
- Produces: ответ query `OPTIONS` (используемый в `open.vue` и `configurable.vue`) теперь содержит `data.shop` вида `{ id: Int, title: String } | null`, помимо уже существующего `data.shop_categories`.

- [ ] **Step 1: Изменить содержимое файла**

Текущее содержимое:
```graphql
query($shop_id: Int) {
  shop_categories(shop_id: $shop_id) {
    id
    title
    is_cooking
    child_categories {
      id
      title
      is_cooking
      child_categories {
        id
        title
        is_cooking
        child_categories {
          id
          title
          is_cooking
        }
      }
    }
  }
}
```

Новое содержимое:
```graphql
query($shop_id: Int) {
  shop(id: $shop_id) {
    id
    title
  }
  shop_categories(shop_id: $shop_id) {
    id
    title
    is_cooking
    child_categories {
      id
      title
      is_cooking
      child_categories {
        id
        title
        is_cooking
        child_categories {
          id
          title
          is_cooking
        }
      }
    }
  }
}
```

- [ ] **Step 2: Проверить, что запрос собирается без ошибок**

Run: `npm run build`
Expected: сборка завершается успешно (exit code 0), без ошибок парсинга GraphQL по `options.graphql`. Поле `shop(id: Int): Shop` уже объявлено в `src/graphql/schema.graphql:1313`, поэтому новых предупреждений о несоответствии схеме быть не должно.

- [ ] **Step 3: Commit**

```bash
git add src/graphql/pages/Product/options.graphql
git commit -m "feat: add shop to product options query"
```

---

### Task 2: Показать магазин в карточке простого товара (open.vue)

**Files:**
- Modify: `src/views/products/open.vue`

**Interfaces:**
- Consumes: `OPTIONS` query из Task 1, поле `data.shop` (`{ id, title } | null`).
- Consumes: computed `shop_id` (уже существует в файле, строка ~1996: `parseInt(this.$route.params.shop_id)`).

- [ ] **Step 1: Добавить поле `shop` в data()**

Найти в `data()` (около строки 815):
```javascript
      product_parent_id: null,
      categories: [],
      categoriesTree: [],
      brands: [],
```

Заменить на:
```javascript
      product_parent_id: null,
      categories: [],
      categoriesTree: [],
      shop: null,
      brands: [],
```

- [ ] **Step 2: Заполнить `shop` из ответа OPTIONS**

Найти в методе `getOptions()` (около строки 1622):
```javascript
    getOptions() {
      this.apollo()
        .admin.query({
          query: OPTIONS,
          variables: {
            shop_id: this.shop_id || undefined,
          },
        })
        .then(({ data }) => {
          this.categoriesTree = data.shop_categories ?? [];
          this.categories = this.flattenCategories(this.categoriesTree);
        });
```

Заменить на:
```javascript
    getOptions() {
      this.apollo()
        .admin.query({
          query: OPTIONS,
          variables: {
            shop_id: this.shop_id || undefined,
          },
        })
        .then(({ data }) => {
          this.shop = data.shop ?? null;
          this.categoriesTree = data.shop_categories ?? [];
          this.categories = this.flattenCategories(this.categoriesTree);
        });
```

- [ ] **Step 3: Показать магазин в заголовке страницы**

Найти в шаблоне (строки 8-10):
```html
      <div class="product-page__header-title">
        <span>{{ id ? this.form.title.value : "Новый товар" }}</span>
      </div>
```

Заменить на:
```html
      <div class="product-page__header-title">
        <span v-if="shop?.title" class="product-page__shop-label">Магазин: {{ shop.title }}</span>
        <span>{{ id ? this.form.title.value : "Новый товар" }}</span>
      </div>
```

- [ ] **Step 4: Добавить стили лейбла и flex-раскладку заголовка**

Найти в `<style lang="stylus">` (около строки 2085):
```stylus
  &__header-title {
    width 100%
    font-size 24px
    line-height 33px
    font-weight 500
    color #222128
  }
```

Заменить на:
```stylus
  &__header-title {
    width 100%
    font-size 24px
    line-height 33px
    font-weight 500
    color #222128
    display flex
    align-items center
    gap 8px
  }

  &__shop-label {
    display inline-block
    font-size 12px
    font-weight 600
    padding 3px 10px
    border-radius 10px
    background #F0F1F3
    color #5C5F66
    white-space nowrap
  }
```

- [ ] **Step 5: Проверить lint и сборку**

Run: `npm run lint`
Expected: без новых ошибок в `src/views/products/open.vue`.

Run: `npm run build`
Expected: exit code 0.

- [ ] **Step 6: Commit**

```bash
git add src/views/products/open.vue
git commit -m "feat: show shop label in simple product card"
```

---

### Task 3: Показать магазин в карточке вариативного товара (configurable.vue)

**Files:**
- Modify: `src/views/products/configurable.vue`

**Interfaces:**
- Consumes: `OPTIONS` query из Task 1, поле `data.shop` (`{ id, title } | null`).
- Consumes: computed `shopId` (уже существует в файле, строка ~485: `parseInt(this.$route.params.shop_id)`) — **внимание**: в этом файле имя computed-свойства `shopId` (camelCase), не `shop_id`, как в `open.vue`.

- [ ] **Step 1: Добавить поле `shop` в data()**

Найти в `data()` (около строки 440):
```javascript
    return {
      loading: false,
      categoriesTree: [],
      brands: [],
```

Заменить на:
```javascript
    return {
      loading: false,
      categoriesTree: [],
      shop: null,
      brands: [],
```

- [ ] **Step 2: Заполнить `shop` из ответа OPTIONS**

Найти в методе `getOptions()` (около строки 528):
```javascript
    getOptions() {
      this.apollo()
        .admin.query({
          query: OPTIONS,
          variables: { shop_id: this.shopId || undefined },
        })
        .then(({ data }) => {
          this.categoriesTree = data.shop_categories ?? [];
        });
```

Заменить на:
```javascript
    getOptions() {
      this.apollo()
        .admin.query({
          query: OPTIONS,
          variables: { shop_id: this.shopId || undefined },
        })
        .then(({ data }) => {
          this.shop = data.shop ?? null;
          this.categoriesTree = data.shop_categories ?? [];
        });
```

- [ ] **Step 3: Показать магазин в заголовке страницы**

Найти в шаблоне (строки 8-11):
```html
      <div class="configurable-page__header-title">
        <span>{{ id ? form.title.value : "Новый вариативный товар" }}</span>
        <span v-if="id" class="configurable-page__badge">Вариативный</span>
      </div>
```

Заменить на:
```html
      <div class="configurable-page__header-title">
        <span v-if="shop?.title" class="configurable-page__shop-label">Магазин: {{ shop.title }}</span>
        <span>{{ id ? form.title.value : "Новый вариативный товар" }}</span>
        <span v-if="id" class="configurable-page__badge">Вариативный</span>
      </div>
```

- [ ] **Step 4: Добавить стиль лейбла**

Найти в `<style lang="stylus">` (около строки 851):
```stylus
  &__badge
    display inline-block
    font-size 12px
    font-weight 600
    padding 3px 10px
    border-radius 10px
    background #E8F5E9
    color #2E7D32
```

Заменить на:
```stylus
  &__badge
    display inline-block
    font-size 12px
    font-weight 600
    padding 3px 10px
    border-radius 10px
    background #E8F5E9
    color #2E7D32

  &__shop-label
    display inline-block
    font-size 12px
    font-weight 600
    padding 3px 10px
    border-radius 10px
    background #F0F1F3
    color #5C5F66
    white-space nowrap
```

(`&__header-title` в этом файле уже имеет `display flex; align-items center; gap 8px` — строки 835-843, менять не требуется.)

- [ ] **Step 5: Проверить lint и сборку**

Run: `npm run lint`
Expected: без новых ошибок в `src/views/products/configurable.vue`.

Run: `npm run build`
Expected: exit code 0.

- [ ] **Step 6: Commit**

```bash
git add src/views/products/configurable.vue
git commit -m "feat: show shop label in configurable product card"
```

---

### Task 4: Ручная e2e-проверка на dev-окружении

**Files:** нет (только верификация)

- [ ] **Step 1: Задеплоить ветку на dev или запустить локально**

Если есть автодеплой dev-окружения по push feature-веток — запушить ветку и дождаться сборки. Иначе — локально:

```bash
cd /Users/daibelbuzarov/PhpstormProjects/aladdin/aladdin_admin_front
npm run dev
```

- [ ] **Step 2: Простой товар — редактирование**

Через браузер (claude-in-chrome или вручную): открыть список товаров, выбрать магазин в фильтре, открыть существующий простой товар (`/admin/product/:shop_id/:id`).
Expected: в заголовке карточки виден лейбл «Магазин: <title выбранного магазина>» рядом с названием товара.

- [ ] **Step 3: Простой товар — создание**

Из списка товаров (с выбранным магазином) нажать «Добавить товар» (переход на `/admin/product/:shop_id`, без `id`).
Expected: лейбл «Магазин: <title>» виден сразу, до сохранения товара.

- [ ] **Step 4: Вариативный товар — редактирование и создание**

Повторить шаги 2-3 для `/admin/configurable-product/:shop_id?/:id?`.
Expected: лейбл «Магазин: <title>» виден в обоих режимах, рядом с бейджем «Вариативный» (в режиме редактирования).

- [ ] **Step 5: Смена магазина в фильтре списка**

В списке товаров переключить фильтр на другой магазин, открыть товар оттуда.
Expected: лейбл в карточке показывает именно этот, второй магазин — значение не «залипает» от предыдущего открытия.

- [ ] **Step 6: Отчитаться пользователю**

Показать скриншот(ы) заголовка карточки с видимым лейблом магазина (create и edit хотя бы для одного из двух типов товара).

---

## Execution Handoff

После выполнения всех задач — смёрджить `feat/product-card-shop-label` в `master` **только по явной команде пользователя** (согласно правилам проекта — не мёржить/не комитить в master без запроса).
