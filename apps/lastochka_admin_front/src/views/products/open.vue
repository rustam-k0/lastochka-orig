<template>
  <main class="page product-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="product-page__header">
      <button @click="$router.go(-1)" class="btn btn--sm btn--white">
        <IconComponent name="arrow-left-line" />
      </button>
      <div class="product-page__header-title">
        <span v-if="shop?.title" class="product-page__shop-label">Магазин: {{ shop.title }}</span>
        <span>{{ id ? this.form.title.value : "Новый товар" }}</span>
      </div>
      <div v-if="id" class="product-page__header-actions">
        <!--        <button v-if="!loading" class="btn btn&#45;&#45;sm btn&#45;&#45;white" @click="openPreviewModal">-->
        <!--          <IconComponent name="checkbox-circle-fill" />-->
        <!--          <span>Предосмотр</span>-->
        <!--        </button>-->
        <button v-if="!loading" class="btn btn--sm" @click="submit">
          <IconComponent name="checkbox-circle-fill" />
          <span>Сохранить изменения</span>
        </button>
      </div>
      <button v-else @click="submit" class="btn btn--sm">
        <IconComponent name="checkbox-circle-fill" />
        <span>Сохранить</span>
      </button>
    </div>
    <!-- Баннер карантина (FOODLINK-11458): причина, по которой товар попал в карантин. -->
    <div v-if="quarantineHighlight" class="product-page__quarantine-banner">
      <IconComponent name="error-warning-line" />
      <span>Товар в карантине: {{ quarantineReasonLabel }}. Проверьте выделенное поле.</span>
    </div>
    <div v-if="id && product_parent_id" class="product-page__parent-info">
      <span class="product-page__parent-info-label">Вариант товара</span>
      <router-link
        :to="{
          name: 'configurable-product',
          params: { shop_id: shop_id || NO_SHOP_ROUTE_PARAM, id: product_parent_id },
        }"
        class="product-page__parent-info-link"
      >
        &laquo; Перейти к вариативному товару &raquo;
      </router-link>
    </div>
    <div v-if="id" class="product-page__info">
      <div class="product-page__status">
        <div class="product-page__status-title">Статус товара</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            :model-value="form.status_id.value === 1"
            green
            :disabled="loading"
            @update:model-value="togglePublish"
            >{{ form.status_id.value === 1 ? "Активный" : "Неактивный" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'is_culinary')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'is_culinary') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.is_culinary.value"
            :disabled="loading"
            >{{ form.is_culinary.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'sugar_free')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'sugar_free') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.sugar_free.value"
            :disabled="loading"
            >{{ form.sugar_free.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'on_search_page')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'on_search_page') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.on_search_page.value"
            :disabled="loading"
            >{{ form.on_search_page.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'served_warm')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'served_warm') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.served_warm.value"
            :disabled="loading"
            >{{ form.served_warm.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'pickup_only')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'pickup_only') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.pickup_only.value"
            :disabled="loading"
            >{{ form.pickup_only.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'adult_only')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'adult_only') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.adult_only.value"
            :disabled="loading"
            >{{ form.adult_only.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'is_marked')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'is_marked') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.is_marked.value"
            :disabled="loading"
            >{{ form.is_marked.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'is_new')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'is_new') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.is_new.value"
            :disabled="loading"
            >{{ form.is_new.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'is_hurry_up')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'is_hurry_up') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.is_hurry_up.value"
            :disabled="loading"
            >{{ form.is_hurry_up.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.customCheckboxes('Product').length" class="product-page__status">
        <CustomCheckboxesField
          entity="Product"
          v-model="form.custom_checkboxes.value"
          :disabled="loading"
        />
      </div>
      <div v-if="form.fill_percent.value !== null" class="product-page__status">
        <div class="product-page__status-title">Заполненность</div>
        <div class="product-page__status-action">
          <b>{{ form.fill_percent.value }}%</b>
        </div>
      </div>
    </div>
    <div v-if="!id" class="product-page__info">
      <div v-if="checkboxStore.isEnabled('Product', 'is_culinary')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'is_culinary') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.is_culinary.value"
            >{{ form.is_culinary.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'sugar_free')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'sugar_free') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.sugar_free.value"
            >{{ form.sugar_free.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'on_search_page')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'on_search_page') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.on_search_page.value"
            >{{ form.on_search_page.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'served_warm')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'served_warm') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.served_warm.value"
            >{{ form.served_warm.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'pickup_only')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'pickup_only') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.pickup_only.value"
            >{{ form.pickup_only.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'adult_only')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'adult_only') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.adult_only.value"
            >{{ form.adult_only.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'is_marked')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'is_marked') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.is_marked.value"
            >{{ form.is_marked.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'is_new')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'is_new') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.is_new.value"
            >{{ form.is_new.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.isEnabled('Product', 'is_hurry_up')" class="product-page__status">
        <div class="product-page__status-title">{{ checkboxStore.getLabel('Product', 'is_hurry_up') }}</div>
        <div class="product-page__status-action">
          <FlagCheckboxComponent
            v-model="form.is_hurry_up.value"
            >{{ form.is_hurry_up.value ? "Да" : "Нет" }}
          </FlagCheckboxComponent>
        </div>
      </div>
      <div v-if="checkboxStore.customCheckboxes('Product').length" class="product-page__status">
        <CustomCheckboxesField
          entity="Product"
          v-model="form.custom_checkboxes.value"
          :disabled="loading"
        />
      </div>
    </div>
    <div class="product-page__main">
      <form @submit.prevent="submit" class="product-page__form">
        <div class="product-page__form-part">
          <div class="product-page__form-block">
            <div class="product-page__form-header">Товар</div>
            <div class="product-page__form-product">
              <InputComponent
                title="Артикул"
                placeholder="Артикул"
                v-model="form.articul.value"
                :errors="form.articul.errors"
                :class="{ 'product-page__field--quarantine': isQuarantineField('sku') }"
              />
              <InputComponent
                title="Название"
                placeholder="Название"
                :errors="form.title.errors"
                required
                v-model="form.title.value"
                :class="{ 'product-page__field--quarantine': isQuarantineField('name') }"
              />
            </div>
            <div v-if="id" class="product-page__form-category">
              <template v-if="form.barcodes.value.length">
                <InputComponent
                  v-for="(code, idx) in form.barcodes.value"
                  :key="idx"
                  :model-value="code"
                  disabled
                  :title="form.barcodes.value.length > 1 ? `Штрих код ${idx + 1}` : 'Штрих код'"
                  placeholder="Штрих код"
                  :class="{ 'product-page__field--quarantine': isQuarantineField('barcode') }"
                >
                  <template v-slot:after>
                    <button
                      type="button"
                      title="Показать штрихкод для сканирования"
                      @click="openBarcodeModal(code)"
                    >
                      <IconComponent name="barcode-line" />
                    </button>
                  </template>
                </InputComponent>
              </template>
              <InputComponent
                v-else
                :model-value="null"
                disabled
                title="Штрих код"
                placeholder="Штрихкоды отсутствуют"
                :class="{ 'product-page__field--quarantine': isQuarantineField('barcode') }"
              />
              <InputComponent
                :model-value="form.code.value"
                disabled
                title="Код товара"
                placeholder="Код отсутствует"
              />
              <InputComponent
                :model-value="form.plu.value"
                disabled
                title="PLU"
                placeholder="PLU отсутствует"
              />
            </div>
            <div class="product-page__form-category">
              <CategoryTreeSelect
                title="Категории"
                placeholder="Выберите категории"
                v-model="form.categories.value"
                :errors="form.categories.errors"
                :options="categoriesTree"
                leaf-only
                multiple
                clearable
              />
            </div>
            <div v-if="moduleStore.isModuleEnabled('brands')" class="product-page__form-category">
              <SelectComponent
                title="Бренд"
                placeholder="Выберите бренд"
                v-model="form.brand_id.value"
                :errors="form.brand_id.errors"
                :options="brands"
                clearable
              />
            </div>
            <div
              class="product-page__form-weight-info"
              :class="{ 'product-page__field--quarantine': isQuarantineField('unit') }"
            >
              <SelectComponent
                title="Ед. измерения"
                placeholder="Ед. измерения"
                v-model="form.info_weight_type_id.value"
                :errors="form.info_weight_type_id.errors"
                :options="units"
                :disabled="isUnitFromOneC"
                :clearable="!isUnitFromOneC"
                :messages="isUnitFromOneC ? ['Единица измерения задаётся выгрузкой из 1С'] : []"
                required
              />
            </div>
            <div class="product-page__form-weight-info">
              <SelectComponent
                title="Ставка НДС"
                placeholder="Не задан"
                v-model="form.vat_code.value"
                :errors="form.vat_code.errors"
                :options="vatCodeOptions"
                clearable
              />
            </div>
            <div v-if="quantumApplicable" class="product-page__quantum">
              <div class="product-page__status">
                <div class="product-page__status-title">Шаг кванта</div>
                <FlagCheckboxComponent v-model="manualQuantumEnabled">
                  {{ manualQuantumEnabled ? "Из товара (исключение)" : "Из категории" }}
                </FlagCheckboxComponent>
              </div>

              <template v-if="!manualQuantumEnabled">
                <p class="product-page__quantum-line" v-if="hasCategoryQuantum">
                  Продаётся по:
                  <strong>{{ category_quantum_step }} {{ unitLabel(category_quantum_unit_type_id) }}</strong>
                  <span class="product-page__quantum-source"> — из категории</span>
                </p>
                <p class="product-page__quantum-line" v-else>
                  Продаётся за базовую единицу: <strong>{{ unitLabel(baseUnitId) }}</strong>
                </p>
                <p class="product-page__quantum-line" v-if="hasCategoryQuantum && portionPrice">
                  Цена за порцию: <strong>{{ portionPrice }} ₽</strong>
                </p>
              </template>

              <template v-else>
                <InputComponent
                  v-model="form.manual_quantum_step.value"
                  :errors="form.manual_quantum_step.errors"
                  :title="`Продавать порциями по (${smallestUnitLabel})`"
                  placeholder="—"
                  :messages="['Индивидуальный шаг товара. Переопределяет квант категории. Цена за порцию пересчитывается автоматически.']"
                />
                <p class="product-page__quantum-line" v-if="manualPortionPrice">
                  → Цена за порцию: <strong>{{ manualPortionPrice }} ₽</strong>
                </p>
              </template>

              <p class="product-page__quantum-line" v-if="basePrice">
                Базовая цена 1С: {{ basePrice }} ₽ / {{ unitLabel(baseUnitId) }}
              </p>
            </div>
            <div
              class="product-page__stock"
              :class="{ 'product-page__field--quarantine': isQuarantineField('stock') }"
            >
              <div class="product-page__status">
                <div class="product-page__status-title">Учёт остатков</div>
                <FlagCheckboxComponent v-model="stockOverrideEnabled">
                  {{ stockOverrideEnabled ? "Задать у товара" : "Из категории" }}
                </FlagCheckboxComponent>
              </div>

              <template v-if="!stockOverrideEnabled">
                <p class="product-page__quantum-line">{{ effectiveStockLabel }}</p>
              </template>

              <template v-else>
                <SelectComponent
                  title="Режим"
                  placeholder="Режим"
                  label-name="title"
                  v-model="form.stock_mode.value"
                  :errors="form.stock_mode.errors"
                  :options="productStockModes"
                />
                <template v-if="form.stock_mode.value && form.stock_mode.value.id === 'fixed'">
                  <InputComponent
                    v-model="form.fixed_stock_quantity.value"
                    :errors="form.fixed_stock_quantity.errors"
                    title="Дневной лимит"
                    placeholder="Например, 100"
                  />
                  <SelectComponent
                    title="Единица"
                    placeholder="Единица"
                    label-name="title"
                    v-model="form.fixed_stock_unit_type_id.value"
                    :errors="form.fixed_stock_unit_type_id.errors"
                    :options="stockUnits"
                  />
                </template>
              </template>
            </div>
            <!--
              В сетевом каталоге остаток пер-магазинный (таблица «Цены и остатки
              по магазинам»), единое поле остатка показало бы остаток случайного
              магазина и записало бы его в якорь — поэтому скрыто.
            -->
            <InputComponent
              v-if="!shopPricesTableVisible"
              v-model="form.quantity.value"
              :errors="form.quantity.errors"
              :title="`Остатки товара, ${stockUnitLabel}`"
              placeholder="Количество на складе"
              :class="{ 'product-page__field--quarantine': isQuarantineField('stock') }"
            />
          </div>
          <div
            class="product-page__form-block"
            :class="{ 'product-page__field--quarantine': isQuarantineField('image') }"
          >
            <div class="product-page__form-header">Главная фотография</div>
            <div class="product-page__form-photo-list">
              <ImageUploadComponent text="Загрузить фото" required v-model="form.img.value" />
            </div>
          </div>
          <div class="product-page__form-block">
            <div class="product-page__form-header">Фотографии</div>
            <ArrayComponent
              ref="images"
              class="product-page__form-images-list"
              :template="imagesTemplate"
              v-model="form.images.value"
            >
              <template v-slot="{ item, i }">
                <ImageUploadComponent
                  text="Загрузить фото"
                  v-model="item.value"
                  @removeItem="removeImage(i)"
                  @uploadImage="uploadImage(i)"
                />
              </template>
            </ArrayComponent>
          </div>
          <div v-if="moduleStore.isModuleEnabled('product_video')" class="product-page__form-block">
            <div class="product-page__form-header">Видео</div>
            <FileUploadComponent
              title="Видео товара"
              text="Загрузить видео (MP4, WebM)"
              path="products/video"
              v-model="form.video.value"
            />
          </div>
          <div class="product-page__form-block">
            <div class="product-page__form-header">Описание</div>
            <div class="product-page__form-description">
              <TextareaComponent
                title="Общее описание"
                placeholder="Общее описание"
                v-model="form.description.value"
                :errors="form.description.errors"
              />
              <InputComponent
                title="Мета заголовок"
                placeholder="Мета заголовок"
                v-model="form.meta_title.value"
                :errors="form.meta_title.errors"
              />
              <TextareaComponent
                title="Мета описание"
                placeholder="Мета описание"
                v-model="form.meta_description.value"
                :errors="form.meta_description.errors"
              />
              <TextareaComponent
                title="Ключевые слова"
                placeholder="Ключевые слова"
                v-model="form.meta_keywords.value"
                :errors="form.meta_keywords.errors"
              />
              <InputComponent
                title="Дополнительная информация"
                placeholder="Дополнительная информация"
                v-model="form.additional_info.value"
                :errors="form.additional_info.errors"
              />
            </div>
          </div>
          <div v-if="moduleStore.isModuleEnabled('product_documents')" class="product-page__form-block">
            <div class="product-page__form-header">Документы</div>
            <div v-for="(doc, index) in documents" :key="index" style="margin-bottom: 16px; padding: 12px; border: 1px solid #eee; border-radius: 8px;">
              <div style="display: flex; gap: 12px; align-items: flex-start;">
                <div style="flex: 1;">
                  <InputComponent
                    title="Название"
                    placeholder="Например: Сертификат соответствия"
                    v-model="doc.title"
                  />
                </div>
                <div style="flex: 1;">
                  <SelectComponent
                    title="Группа"
                    placeholder="Выберите или введите группу"
                    :model-value="doc.group"
                    :options="getDocGroupOptions(index)"
                    clearable
                    @update:search="onDocGroupSearch($event, index)"
                    @update:model-value="onDocGroupSelect($event, index)"
                  />
                </div>
                <button
                  class="btn btn--sm btn--danger"
                  style="margin-top: 24px;"
                  @click.prevent="removeDocument(index)"
                >
                  Удалить
                </button>
              </div>
              <FileUploadComponent
                title="Файл"
                text="Загрузить документ (PDF)"
                path="documents"
                v-model="doc.file"
              />
            </div>
            <div style="width: 100%">
              <button class="btn btn--sm" style="width: 100%" @click.prevent="addDocument()">
                Добавить документ
              </button>
            </div>
          </div>
        </div>
        <div class="product-page__form-part">
          <div class="product-page__form-block">
            <!--
              Сетевой каталог: карточка одна на всю сеть, а цена и остаток —
              пер-магазинные и ведутся 1С. Поэтому вместо единственного поля
              «Цена продажи» показываем таблицу по магазинам с построчным
              редактированием (ProductShopPriceSave/Delete, MID-226).
            -->
            <template v-if="shopPricesTableVisible">
              <div class="product-page__form-header">Цены и остатки по магазинам</div>
              <!--
                Признак товара из 1С — заполненный products.code («Код товара
                из 1С»). ProductCreate/ProductUpdate это поле не принимают,
                проставляется только выгрузкой (ProductEventTransformer::fromEvent).
              -->
              <div v-if="isOneCProduct" class="product-page__shop-prices-warning">
                <span class="product-page__shop-prices-warning-icon">⚠</span>
                {{ priceTiersEnabled ? "Цены, цены от количества и остатки" : "Цены и остатки" }}
                этого товара ведёт 1С. Ручные правки будут перезаписаны следующей выгрузкой.
              </div>
              <div class="product-page__shop-prices-search">
                <InputComponent placeholder="Поиск магазина" v-model="shopPricesSearch" />
              </div>
              <table class="product-page__shop-prices">
                <thead>
                  <tr>
                    <th>Магазин</th>
                    <th>Цена</th>
                    <th>Старая цена</th>
                    <th>Остаток, {{ stockUnitLabel }}</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="row in shopPricesRows" :key="row.shopId">
                    <tr>
                      <td>
                        {{ row.shopTitle }}
                        <button
                          v-if="priceTiersEnabled"
                          type="button"
                          class="product-page__shop-prices-tiers-toggle"
                          :class="{
                            'product-page__shop-prices-tiers-toggle--filled': shopTierCount(row) > 0,
                          }"
                          @click="toggleShopTiers(row)"
                        >
                          <span>{{ shopTiersToggleLabel(row) }}</span>
                          <IconComponent :name="shopTiersToggleIcon(row)" />
                        </button>
                      </td>
                      <td>
                        <InputComponent
                          placeholder="Цена"
                          :model-value="shopPriceEdits[row.shopId]?.price ?? row.price"
                          @update:model-value="setShopPriceEdit(row, 'price', $event)"
                        />
                      </td>
                      <td>{{ row.priceOld ?? "нет" }}</td>
                      <td>
                        <InputComponent
                          placeholder="Остаток"
                          :model-value="shopPriceEdits[row.shopId]?.quantity ?? row.quantity ?? 0"
                          @update:model-value="setShopPriceEdit(row, 'quantity', $event)"
                        />
                      </td>
                      <td class="product-page__shop-prices-actions">
                        <button
                          type="button"
                          class="btn btn--sm"
                          :disabled="
                            shopPriceSaving.includes(row.shopId) ||
                            (!shopPriceEdits[row.shopId] && !shopTierEdits[row.shopId]?.dirty)
                          "
                          @click="saveShopPriceRow(row)"
                        >
                          Сохранить
                        </button>
                        <button
                          type="button"
                          class="product-page__shop-prices-delete"
                          title="Убрать товар из магазина"
                          :disabled="shopPriceSaving.includes(row.shopId)"
                          @click="deleteShopPriceRow(row)"
                        >
                          <IconComponent name="delete-bin-line" />
                        </button>
                      </td>
                    </tr>
                    <tr
                      v-if="priceTiersEnabled && shopTiersExpanded.includes(row.shopId)"
                      class="product-page__shop-prices-tiers-row"
                    >
                      <td colspan="5">
                        <div class="product-page__shop-prices-tiers">
                          <div class="product-page__shop-prices-tiers-title">
                            Цены от количества: {{ row.shopTitle }}
                          </div>
                          <PriceTiersEditor
                            :model-value="shopTierRows(row)"
                            :errors="shopTierErrors(row)"
                            :unit-label="priceTiersUnitLabel"
                            :disabled="shopPriceSaving.includes(row.shopId)"
                            @update:model-value="setShopTierRows(row, $event)"
                          />
                          <div class="product-page__shop-prices-tiers-note">
                            Лесенка сохраняется кнопкой «Сохранить» в строке магазина вместе с ценой и
                            остатком.
                          </div>
                        </div>
                      </td>
                    </tr>
                  </template>
                  <tr v-if="!shopPricesRows.length">
                    <td colspan="5">
                      {{ shopPricesSearch ? "Магазины не найдены" : "Цен и остатков нет" }}
                    </td>
                  </tr>
                </tbody>
              </table>
              <div class="product-page__shop-prices-add">
                <button
                  v-if="!addShopMode"
                  type="button"
                  class="btn btn--sm"
                  @click="openAddShop"
                >
                  Добавить магазин
                </button>
                <div v-else class="product-page__shop-prices-add-form">
                  <SelectComponent
                    placeholder="Магазин"
                    label-name="title"
                    v-model="addShopSelected"
                    :options="addShopOptions"
                    :loading="addShopOptionsLoading"
                    clearable
                    @update:search="searchAddShopOptions"
                  />
                  <InputComponent placeholder="Цена" v-model="addShopPrice" />
                  <InputComponent :placeholder="`Остаток, ${stockUnitLabel}`" v-model="addShopQuantity" />
                  <button
                    type="button"
                    class="btn btn--sm"
                    :disabled="!addShopSelected || addShopSaving"
                    @click="addShopPriceRow"
                  >
                    Сохранить
                  </button>
                  <button type="button" class="btn btn--sm" @click="addShopMode = false">
                    Отмена
                  </button>
                </div>
              </div>
              <div class="product-page__shop-prices-add">
                <button
                  v-if="!applyAllMode"
                  type="button"
                  class="btn btn--sm"
                  @click="openApplyAll"
                >
                  Применить ко всем магазинам
                </button>
                <div v-else class="product-page__shop-prices-add-form">
                  <InputComponent placeholder="Цена" v-model="applyAllPrice" />
                  <InputComponent :placeholder="`Остаток, ${stockUnitLabel}`" v-model="applyAllQuantity" />
                  <template v-if="priceTiersEnabled">
                    <CheckboxComponent
                      class="product-page__shop-prices-add-form-wide"
                      v-model="applyAllTiersEnabled"
                    >
                      <span>Заменить цены от количества во всех магазинах</span>
                    </CheckboxComponent>
                    <div v-if="applyAllTiersEnabled" class="product-page__shop-prices-add-form-wide">
                      <PriceTiersEditor
                        v-model="applyAllTiers.form.price_tiers.value"
                        :errors="applyAllTiers.form.price_tiers.errors"
                        :unit-label="priceTiersUnitLabel"
                        :disabled="applyAllRunning"
                      />
                      <div class="product-page__shop-prices-tiers-note">
                        Пустой список удалит цены от количества во всех магазинах сети.
                      </div>
                    </div>
                  </template>
                  <button
                    type="button"
                    class="btn btn--sm"
                    :disabled="applyAllRunning"
                    @click="applyPriceToAllShops"
                  >
                    Применить
                  </button>
                  <button type="button" class="btn btn--sm" @click="applyAllMode = false">
                    Отмена
                  </button>
                </div>
              </div>
            </template>
            <template v-else>
              <div class="product-page__form-header">Стоимость</div>
              <div class="product-page__form-price">
                <InputComponent
                  title="Цена продажи, ₽"
                  placeholder="Цена продажи, ₽"
                  required
                  v-model="form.price.value"
                  :errors="form.price.errors"
                  :messages="['Допустимы копейки, например: 136.50']"
                  :class="{ 'product-page__field--quarantine': isQuarantineField('price') }"
                />
                <!--              <InputComponent title="Скидка, %" placeholder="Скидка, %" />-->
              </div>
              <div v-if="priceTiersEnabled" class="product-page__price-tiers">
                <div class="product-page__price-tiers-title">Цены от количества</div>
                <div v-if="isOneCProduct" class="product-page__shop-prices-warning">
                  <span class="product-page__shop-prices-warning-icon">⚠</span>
                  Цены от количества этого товара ведёт 1С. Ручные правки будут перезаписаны следующей
                  выгрузкой.
                </div>
                <PriceTiersEditor
                  v-model="form.price_tiers.value"
                  :errors="form.price_tiers.errors"
                  :unit-label="priceTiersUnitLabel"
                  @update:model-value="priceTiersDirty = true"
                />
              </div>
            </template>
          </div>
          <div
            v-if="moduleStore.isModuleEnabled('product_ready_time')"
            class="product-page__form-block"
          >
            <div class="product-page__form-header">Время готовности</div>
            <div class="col col-2">
              <InputComponent
                title="Готов с (ЧЧ:ММ)"
                placeholder="12:00"
                mask="##:##"
                v-model="form.ready_fixed_time.value"
                :errors="form.ready_fixed_time.errors"
                :messages="['Ежедневное время готовности. Пусто — без ограничения']"
              />
            </div>
          </div>
          <div v-if="moduleStore.isModuleEnabled('product_composition')" class="product-page__form-block">
            <div class="product-page__form-header">Состав</div>
            <div class="product-page__form-composition">
              <template
                v-for="(item, i) in form.attributes.value.filter(
                  (prev) => prev.attribute.category_type_id === 100
                )"
                :key="i"
              >
                <InputComponent
                  v-if="item.attribute.type_id === 1"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  v-model="item.string_value"
                />
                <TextareaComponent
                  v-else-if="item.attribute.type_id === 2"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  v-model="item.text_value"
                />
                <InputComponent
                  v-if="item.attribute.type_id === 3"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  v-model="item.int_value"
                />
                <InputComponent
                  v-if="item.attribute.type_id === 4"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  v-model="item.float_value"
                />
                <SelectComponent
                  v-if="item.attribute.type_id === 5"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  :options="item.attribute.values"
                  label-name="title"
                  v-model="item.attribute_value"
                  clearable
                />
              </template>
            </div>
          </div>
          <div v-if="moduleStore.isModuleEnabled('product_params')" class="product-page__form-block">
            <div class="product-page__form-header">Параметры</div>
            <div class="product-page__form-params-row-2">
              <template
                v-for="(item, i) in form.attributes.value.filter(
                  (prev) => prev.attribute.category_type_id === 101
                )"
                :key="i"
              >
                <InputComponent
                  v-if="item.attribute.type_id === 1"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  v-model="item.string_value"
                />
                <TextareaComponent
                  v-else-if="item.attribute.type_id === 2"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  v-model="item.text_value"
                />
                <InputComponent
                  v-if="item.attribute.type_id === 3"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  v-model="item.int_value"
                />
                <InputComponent
                  v-if="item.attribute.type_id === 4"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  v-model="item.float_value"
                />
                <SelectComponent
                  v-if="item.attribute.type_id === 5"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  :options="item.attribute.values"
                  label-name="title"
                  v-model="item.attribute_value"
                  clearable
                />
              </template>
              <InputComponent
                v-if="info_weight_type_id === 1"
                title="Вес"
                placeholder="Вес"
                mask="##########"
                v-model="form.weight.value"
                :errors="form.weight.errors"
              />
            </div>
          </div>
          <div v-if="categoryAttributes.length" class="product-page__form-block">
            <div class="product-page__form-header">Характеристики</div>
            <div class="product-page__form-params-row-2">
              <template v-for="item in categoryAttributes" :key="item.id">
                <InputComponent
                  v-if="item.attribute.type_id === 1"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  v-model="getCategoryAttrValue(item.attribute).string_value"
                  :required="item.is_required"
                />
                <TextareaComponent
                  v-else-if="item.attribute.type_id === 2"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  v-model="getCategoryAttrValue(item.attribute).text_value"
                  :required="item.is_required"
                />
                <InputComponent
                  v-else-if="item.attribute.type_id === 3"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  v-model="getCategoryAttrValue(item.attribute).int_value"
                  :required="item.is_required"
                />
                <InputComponent
                  v-else-if="item.attribute.type_id === 4"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  v-model="getCategoryAttrValue(item.attribute).float_value"
                  :required="item.is_required"
                />
                <SelectComponent
                  v-else-if="item.attribute.type_id === 5"
                  :title="item.attribute.title"
                  :placeholder="item.attribute.title"
                  :options="item.attribute.values"
                  label-name="title"
                  v-model="getCategoryAttrValue(item.attribute).attribute_value"
                  :required="item.is_required"
                  clearable
                />
              </template>
            </div>
          </div>
          <div v-if="moduleStore.isModuleEnabled('supplements')" class="product-page__form-block">
            <div class="product-page__form-header">Дополнительные добавки</div>
            <ArrayComponent
              just-remove-btn
              class="product-page__supplements"
              hide-add-button
              ref="supplements"
              :template="supplementsTemplate"
              v-model="form.supplements.value"
            >
              <template v-slot="{ item }">
                <div class="product-page__form-supplements">
                  <InputComponent
                    placeholder="Название добавки"
                    v-model="item.title.value"
                    :errors="item.title.errors"
                  />
                  <SelectComponent
                    placeholder="Тип"
                    label-name="value"
                    :options="item.options"
                    v-model="item.type.value"
                    :errors="item.type.errors"
                    clearable
                  />
                  <CheckboxComponent v-model="item.is_required.value">
                    <span>Сделать добавку обязательной</span>
                  </CheckboxComponent>
                </div>
                <ArrayComponent
                  ref="supplementsValues"
                  :template="supplementsValuesTemplate"
                  v-model="item.values"
                >
                  <template v-slot="{ item }">
                    <div class="product-page__form-supplements-value">
                      <div class="product-page__supplement-value-type">
                        <label class="product-page__supplement-value-type-label">
                          <input type="radio" value="manual" v-model="item.value_type" />
                          Ручная
                        </label>
                        <label class="product-page__supplement-value-type-label">
                          <input type="radio" value="catalog" v-model="item.value_type" />
                          Из каталога
                        </label>
                      </div>
                      <div v-if="item.value_type === 'catalog'" class="product-page__supplement-catalog">
                        <div class="product-page__supplement-catalog-search">
                          <CategoryTreeSelect
                            class="product-page__supplement-catalog-category"
                            placeholder="Фильтр по категории"
                            v-model="item.productSearchCategory"
                            :options="categoriesTree"
                            leaf-only
                            clearable
                            @update:model-value="() => { if (item.productSearch && item.productSearch.length >= 2) searchSupplementProduct(item, item.productSearch); }"
                          />
                          <InputComponent
                            placeholder="Поиск товара..."
                            :model-value="item.productSearch"
                            @update:model-value="(v) => { item.productSearch = v; searchSupplementProduct(item, v); }"
                          />
                          <div v-if="item.productSearchResults && item.productSearchResults.length" class="product-page__supplement-catalog-results">
                            <div
                              v-for="p in item.productSearchResults"
                              :key="p.id"
                              class="product-page__supplement-catalog-result-item"
                              @click="selectSupplementProduct(item, p)"
                            >
                              {{ p.title }}
                            </div>
                          </div>
                          <div v-if="item.product" class="product-page__supplement-catalog-selected">
                            Выбрано: <strong>{{ item.product.title }}</strong>
                            <button type="button" class="product-page__supplement-catalog-clear" @click="clearSupplementProduct(item)">✕</button>
                          </div>
                        </div>
                      </div>
                      <InputComponent
                        placeholder="Название параметра"
                        v-model="item.title.value"
                        :errors="item.title.errors"
                      />
                      <InputComponent
                        :placeholder="item.value_type === 'catalog' ? 'Цена (необязательно, пусто = цена товара)' : 'Цена'"
                        type="number"
                        v-model="item.price.value"
                        :errors="item.price.errors"
                      />
                      <InputComponent
                        placeholder="Макс. количество"
                        type="number"
                        v-model="item.max_quantity.value"
                        :errors="item.max_quantity.errors"
                      />
                      <InputComponent
                        placeholder="Позиция"
                        type="number"
                        v-model="item.position.value"
                        :errors="item.position.errors"
                      />
                      <SelectComponent
                        v-if="item.value_type === 'catalog'"
                        title="Учёт остатка"
                        placeholder="По умолчанию (наследовать)"
                        :options="trackStockOptions"
                        v-model="item.track_stock.value"
                        :errors="item.track_stock.errors"
                        clearable
                      />
                    </div>
                  </template>
                </ArrayComponent>
              </template>
            </ArrayComponent>
            <div style="width: 100%">
              <button class="btn btn--sm" style="width: 100%" @click.prevent="$refs.supplements.add()">
                Добавить
              </button>
            </div>
          </div>
        </div>
      </form>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import CategoryTreeSelect from "@/components/inputs/CategoryTreeSelect.vue";
import TextareaComponent from "@/components/inputs/TextareaComponent.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import ArrayComponent from "@/components/inputs/ArrayComponent.vue";
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import CustomCheckboxesField from "@/components/inputs/CustomCheckboxesField.vue";
import ImageUploadComponent from "@/components/ImageUpload.vue";
import FileUploadComponent from "@/components/inputs/FileUploadComponent.vue";
import OPTIONS from "@/graphql/pages/Product/options.graphql";
import GLOBAL_CATEGORIES from "@/graphql/pages/Product/global_categories.graphql";
import DEFAULT_ATTRIBUTES from "@/graphql/queries/default_attributes.graphql";
import CATEGORY_FAMILY_ATTRIBUTES from "@/graphql/queries/category_family_attributes.graphql";
import EFFECTIVE_STOCK_PREVIEW from "@/graphql/pages/Product/effective_stock_preview.graphql";
import PRODUCT from "@/graphql/queries/admin_products_item.graphql";
import BRANDS from "@/graphql/queries/admin_brands.graphql";
import PRODUCT_UPDATE from "@/graphql/mutations/ProductUpdate.graphql";
import PRODUCT_CREATE from "@/graphql/mutations/ProductCreate.graphql";
import PRODUCT_HIDE from "@/graphql/mutations/ProductHide.graphql";
import PRODUCT_PUBLISH from "@/graphql/mutations/ProductPubish.graphql";
import PRODUCT_DOCUMENT_CREATE from "@/graphql/mutations/ProductDocumentCreate.graphql";
import PRODUCT_DOCUMENT_DELETE from "@/graphql/mutations/ProductDocumentDelete.graphql";
import PRODUCT_SHOP_PRICE_SAVE from "@/graphql/mutations/ProductShopPriceSave.graphql";
import PRODUCT_SHOP_PRICE_DELETE from "@/graphql/mutations/ProductShopPriceDelete.graphql";
import PRODUCT_SHOP_PRICES_APPLY_TO_ALL from "@/graphql/mutations/ProductShopPricesApplyToAll.graphql";
import ADMIN_SHOPS_SEARCH from "@/graphql/queries/admin_shops_search.graphql";
import DOCUMENT_GROUPS from "@/graphql/queries/product_document_groups.graphql";
import PRODUCTS_SEARCH from "@/graphql/queries/admin_products_search.graphql";
import { useDefaultStore } from "@/stores/default.js";
import { useCheckboxConfigStore } from "@/stores/checkboxConfig.js";
import {
  parseCustomCheckboxes,
  parseGqlErrors as parseFormGqlErrors,
  resetErrors as resetFormErrors,
} from "@/utils.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { NO_SHOP_ROUTE_PARAM } from "@/constants/productRoutes.js";
import { shallowRef } from "vue";
import PreviewModal from "@/views/products/modals/PreviewModal.vue";
import BarcodeModal from "@/views/products/modals/BarcodeModal.vue";
import PriceTiersEditor from "@/views/products/components/PriceTiersEditor.vue";
import customParseFormat from "dayjs/plugin/customParseFormat";
import dayjs from "dayjs";
dayjs.extend(customParseFormat);

export default {
  name: "ProductPage",
  inject: ["apollo"],
  components: {
    ImageUploadComponent,
    FileUploadComponent,
    InputComponent,
    IconComponent,
    BreadcrumbsComponent,
    SelectComponent,
    CategoryTreeSelect,
    CheckboxComponent,
    TextareaComponent,
    ArrayComponent,
    FlagCheckboxComponent,
    CustomCheckboxesField,
    PriceTiersEditor,
  },
  mounted() {
    this.get();
    this.getOptions();
    this.refreshEffectiveStockPreview();

    if (!this.id) {
      // this.$refs.supplements.add();
      this.$refs.images?.add();
    }
  },
  data() {
    return {
      // Плейсхолдер позиционного сегмента shop_id в роуте карточки —
      // см. constants/productRoutes.js.
      NO_SHOP_ROUTE_PARAM,
      product_parent_id: null,
      categories: [],
      categoriesTree: [],
      shop: null,
      brands: [],
      documentGroups: [],
      documents: [],
      categoryAttributes: [],
      categoryAttributesLoading: false,
      originalDocumentIds: [],
      info_weight_type_id: null,
      // Квант витринной категории (read-only). step не null → квант задан
      // категорией, ручной ввод недоступен, блок показывается информативно.
      category_quantum_step: null,
      category_quantum_unit_type_id: null,
      // Сырая единица товара из 1С + цена за наименьшую единицу (для отображения
      // базовой цены и пересчёта цены за порцию в карточке).
      base_unit_type_id: null,
      manualQuantumEnabled: false,
      // Защита от перезатирания цены при гидрации: синхронизация form.price.value
      // по сигнатуре кванта включается только после полной загрузки товара.
      priceSyncReady: false,
      stockOverrideEnabled: false,
      effective_stock_mode: null,
      effective_fixed_stock: null,
      effective_fixed_stock_unit_type_id: null,
      price_per_small_unit: null,
      // Сетевой каталог: пер-магазинные цены и остатки карточки (только чтение,
      // источник — 1С) + строка поиска по названию магазина в таблице.
      productPrices: [],
      productRemains: [],
      shopPricesSearch: "",
      // Правки строк таблицы «Цены и остатки по магазинам»: {[shopId]: {price, quantity}}
      shopPriceEdits: {},
      // shopId строк, у которых идёт сохранение/удаление
      shopPriceSaving: [],
      // Форма «Добавить магазин»: выбор ещё не привязанного магазина + цена/остаток.
      addShopMode: false,
      addShopSelected: null,
      addShopPrice: "",
      addShopQuantity: "",
      addShopOptions: [],
      addShopOptionsLoading: false,
      addShopSaving: false,
      addShopSearchDebounce: null,
      // Форма «Применить ко всем магазинам»: одна цена/остаток для всех
      // строк таблицы разом (ProductShopPricesApplyToAll).
      applyAllMode: false,
      applyAllPrice: "",
      applyAllQuantity: "",
      applyAllRunning: false,
      // Цены от количества (MID-308). Ступени товара по всем магазинам из
      // admin_products_item.price_tiers: [{shop_id, min_quantity, value}].
      productPriceTiers: [],
      // shopId строк таблицы с раскрытым редактором лесенки
      shopTiersExpanded: [],
      // Буфер правок лесенки строки магазина:
      // {[shopId]: {dirty, form: {price_tiers: {value: [строки], errors: []}}}}.
      // Вложенный form нужен, чтобы ошибки сервера раскладывал системный parseGqlErrors из utils.js.
      shopTierEdits: {},
      // Форма «Применить ко всем магазинам»: лесенка передаётся только при включённой галочке.
      applyAllTiersEnabled: false,
      applyAllTiers: { form: { price_tiers: { value: [], errors: [] } } },
      // Обычный режим: лесенку правили в этой сессии (без правок аргумент price_tiers не шлём).
      priceTiersDirty: false,
      loading: false,
      productStockModes: [
        { id: "ignore", title: "Не учитывать" },
        { id: "fixed", title: "Фиксированный лимит" },
      ],
      stockUnits: [
        { id: 2, title: "грамм" },
        { id: 1, title: "штука" },
      ],
      // is_cooking: false,
      imagesTemplate: {
        value: null,
      },
      units: [
        { id: 1, title: "шт" },
        { id: 2, title: "грамм" },
        { id: 3, title: "килограмм" },
        { id: 4, title: "литр" },
        { id: 5, title: "миллилитр" },
      ],
      // Коды ЮKassa. 11/12 — базовая ставка РФ с 01.01.2026 (22% и расчётная
      // 22/122), их присылает 1С через 1c_parser. Без них селект не находил
      // значение товара и при сохранении карточки отправлял vat_code: null.
      vatCodeOptions: [
        { id: 1, title: "Без НДС" },
        { id: 2, title: "НДС 0%" },
        { id: 3, title: "НДС 10%" },
        { id: 4, title: "НДС 20%" },
        { id: 11, title: "НДС 22%" },
        { id: 5, title: "НДС 10/110" },
        { id: 6, title: "НДС 20/120" },
        { id: 12, title: "НДС 22/122" },
      ],
      trackStockOptions: [
        { id: null, title: "По умолчанию (наследовать)" },
        { id: true, title: "Учитывать остаток" },
        { id: false, title: "Не учитывать" },
      ],
      supplementsValuesTemplate: {
        value_type: "manual",
        title: {
          value: null,
          errors: [],
        },
        price: {
          value: null,
          errors: [],
        },
        product_id: null,
        product: null,
        productSearch: "",
        productSearchCategory: null,
        productSearchResults: [],
        productSearchLoading: false,
        max_quantity: {
          value: null,
          errors: [],
        },
        track_stock: {
          value: null,
          errors: [],
        },
        position: {
          value: null,
          errors: [],
        },
      },
      supplementsTemplate: {
        title: {
          value: null,
          errors: [],
        },
        type: {
          value: null,
          errors: [],
        },
        is_required: {
          value: null,
          errors: [],
        },
        options: [
          { id: 1, title: "one", value: "Выбор одного" },
          { id: 2, title: "many", value: "Выбор многих" },
        ],
        values: [
          {
            value_type: "manual",
            title: {
              value: null,
              errors: [],
            },
            price: {
              value: null,
              errors: [],
            },
            product_id: null,
            product: null,
            productSearch: "",
            productSearchCategory: null,
            productSearchResults: [],
            productSearchLoading: false,
            max_quantity: {
              value: null,
              errors: [],
            },
            track_stock: {
              value: null,
              errors: [],
            },
            position: {
              value: null,
              errors: [],
            },
          },
        ],
      },
      form: {
        weight: {
          value: null,
          errors: [],
        },
        articul: {
          value: null,
          errors: [],
        },
        code: {
          value: null,
          errors: [],
        },
        plu: {
          value: null,
          errors: [],
        },
        attributes: {
          value: [],
          errors: [],
        },
        categories: {
          value: [],
          errors: [],
        },
        info_weight: {
          value: null,
          errors: [],
        },
        info_weight_type_id: {
          value: null,
          errors: [],
        },
        manual_quantum_step: {
          value: null,
          errors: [],
        },
        stock_mode: {
          value: null,
          errors: [],
        },
        fixed_stock_quantity: {
          value: null,
          errors: [],
        },
        fixed_stock_unit_type_id: {
          value: null,
          errors: [],
        },
        quantity: {
          value: null,
          errors: [],
        },
        ready_fixed_time: {
          value: null,
          errors: [],
        },
        description: {
          value: null,
          errors: [],
        },
        additional_info: {
          value: null,
          errors: [],
        },
        fill_percent: {
          value: null,
          errors: [],
        },
        barcode: {
          value: null,
          errors: [],
        },
        barcodes: {
          value: [],
          errors: [],
        },
        img: {
          value: null,
          errors: [],
        },
        images: {
          value: [],
          errors: [],
        },
        meta_title: {
          value: null,
          error: [],
        },
        meta_description: {
          value: null,
          errors: [],
        },
        meta_keywords: {
          value: null,
          errors: [],
        },
        supplements: {
          value: [],
          errors: [],
        },
        price: {
          value: null,
          errors: [],
        },
        // Цены от количества (MID-308), обычный режим: строки редактора лесенки
        price_tiers: {
          value: [],
          errors: [],
        },
        quantity: {
          value: null,
          errors: [],
        },
        status_id: {
          value: null,
          errors: [],
        },
        is_culinary: {
          value: false,
          errors: [],
        },
        sugar_free: {
          value: false,
          errors: [],
        },
        on_search_page: {
          value: false,
          errors: [],
        },
        served_warm: {
          value: false,
          errors: [],
        },
        pickup_only: {
          value: false,
          errors: [],
        },
        adult_only: {
          value: false,
          errors: [],
        },
        // Маркируемый товар (Честный знак, MID-266)
        is_marked: {
          value: false,
          errors: [],
        },
        // Галочки автоподборок товарных групп «Новинки» / «Успей купить»
        is_new: {
          value: false,
          errors: [],
        },
        is_hurry_up: {
          value: false,
          errors: [],
        },
        custom_checkboxes: {
          value: {},
          errors: [],
        },
        vat_code: {
          value: null,
          errors: [],
        },
        brand_id: {
          value: null,
          errors: [],
        },
        video: {
          value: null,
          errors: [],
        },
        supplements: {
          value: [],
          errors: [],
        },
        title: {
          value: null,
          errors: [],
        },
      },
    };
  },
  watch: {
    'form.categories.value': {
      handler(newVal) {
        const primaryId = (newVal ?? [])[0]?.id;
        if (primaryId) {
          this.loadCategoryAttributes(primaryId);
        } else {
          this.categoryAttributes = [];
        }
        this.refreshEffectiveStockPreview();
      },
      deep: true,
    },
    baseUnitId() {
      this.refreshEffectiveStockPreview();
    },
    // При смене шага/единицы/тумблера кванта «Цена продажи» = цена за актуальную
    // порцию (perSmall × шаг). Без этого в мутацию уходит цена за прежний шаг, и
    // бэкенд реконструирует базовую цену 1С делением на новый шаг → удвоение.
    quantumPriceSignature() {
      if (!this.priceSyncReady) return;
      const v = this.effectiveSellPrice;
      if (v != null) this.form.price.value = v;
    },
  },
  methods: {
    // Поле подсвечивается красным, если оно — причина карантина (FOODLINK-11458).
    isQuarantineField(key) {
      return this.quarantineHighlight === key;
    },
    searchSupplementProduct(val, query) {
      if (val._searchDebounce) clearTimeout(val._searchDebounce);
      if (!query || query.length < 2) {
        val.productSearchResults = [];
        return;
      }
      val._searchDebounce = setTimeout(() => {
        val.productSearchLoading = true;
        const categories = val.productSearchCategory ? [val.productSearchCategory.id] : undefined;
        this.apollo()
          .admin.query({
            query: PRODUCTS_SEARCH,
            variables: { title: query, first: 10, categories },
            fetchPolicy: "network-only",
          })
          .then(({ data }) => {
            val.productSearchResults = data.admin_products_paginate?.data || [];
          })
          .catch(() => {
            val.productSearchResults = [];
          })
          .finally(() => {
            val.productSearchLoading = false;
          });
      }, 300);
    },
    selectSupplementProduct(val, product) {
      val.product_id = parseInt(product.id);
      val.product = { id: parseInt(product.id), title: product.title };
      if (!val.title.value) {
        val.title.value = product.title;
      }
      if (!val.price.value && product.default_price?.value != null) {
        val.price.value = product.default_price.value;
      }
      val.productSearch = "";
      val.productSearchResults = [];
    },
    clearSupplementProduct(val) {
      val.product_id = null;
      val.product = null;
      val.productSearch = "";
      val.productSearchResults = [];
    },
    loadCategoryAttributes(categoryId) {
      this.categoryAttributesLoading = true;
      this.apollo()
        .admin.query({
          query: CATEGORY_FAMILY_ATTRIBUTES,
          variables: { category_id: categoryId },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.categoryAttributes = (data.category_family_attributes || [])
            .filter((item) => item.attribute.category_type_id === 103);
        })
        .catch((err) => {
          console.error("Error loading category attributes:", err);
          this.categoryAttributes = [];
        })
        .finally(() => {
          this.categoryAttributesLoading = false;
        });
    },
    // Превью эффективного учёта остатков (категория/магазин/единица) — работает
    // и при создании (нет ещё сохранённого товара), и при редактировании (живой
    // пересчёт при смене категории/единицы до сохранения).
    refreshEffectiveStockPreview() {
      this.apollo()
        .admin.query({
          query: EFFECTIVE_STOCK_PREVIEW,
          variables: {
            category_ids: (this.form.categories.value ?? []).map((c) => c.id),
            shop_id: this.shop_id || undefined,
            unit_type_id: this.baseUnitId || undefined,
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const preview = data.effective_stock_preview;
          this.effective_stock_mode = preview?.mode ?? null;
          this.effective_fixed_stock = preview?.fixed ?? null;
          this.effective_fixed_stock_unit_type_id = preview?.fixed_unit_type_id ?? null;
        })
        .catch(() => {
          this.effective_stock_mode = null;
          this.effective_fixed_stock = null;
          this.effective_fixed_stock_unit_type_id = null;
        });
    },
    // Принимает объект атрибута (с type_id) либо голый id. Без type_id
    // collectData не может определить колонку значения и молча выбрасывает
    // характеристику из мутации — поэтому заглушка всегда обогащается.
    getCategoryAttrValue(attribute) {
      const attributeId = attribute?.id ?? attribute;
      let existing = this.form.attributes.value.find(
        (a) => a.attribute?.id === attributeId || a.attribute_id === attributeId
      );
      if (!existing) {
        existing = {
          id: null,
          attribute_id: attributeId,
          attribute: attribute?.id ? { ...attribute } : { id: attributeId },
          string_value: null,
          text_value: null,
          int_value: null,
          float_value: null,
          attribute_value: null,
          attribute_value_id: null,
        };
        this.form.attributes.value.push(existing);
      }
      if (attribute?.id && existing.attribute?.type_id == null) {
        existing.attribute = { ...attribute };
      }
      return existing;
    },
    uploadImage(i) {
      if (this.form.images.value.length - 1 === i) {
        // Колбэк cropper-модалки переживает уход со страницы: refs могут быть null.
        this.$refs.images?.add();
      }
    },
    onDocGroupSearch(query, index) {
      this.documents[index]._groupSearch = query;
    },
    onDocGroupSelect(value, index) {
      if (value && value.title?.startsWith("Создать: ")) {
        // Нормализуем: title = id (без "Создать: " префикса)
        const normalValue = { id: value.id, title: value.id };
        this.documents[index].group = normalValue;
        // Добавляем в список опций для других документов
        if (!this.documentGroups.find((g) => g.id === value.id)) {
          this.documentGroups.push(normalValue);
        }
      } else {
        this.documents[index].group = value;
      }
    },
    getDocGroupOptions(index) {
      const query = this.documents[index]?._groupSearch?.trim();
      if (!query) return this.documentGroups;

      const exists = this.documentGroups.some(
        (g) => g.title.toLowerCase() === query.toLowerCase()
      );
      if (exists) return this.documentGroups;

      return [...this.documentGroups, { id: query, title: `Создать: ${query}` }];
    },
    addDocument() {
      this.documents.push({
        id: null,
        title: "",
        file: null,
        group: null,
        position: this.documents.length,
        _groupSearch: "",
      });
    },
    removeDocument(index) {
      this.documents.splice(index, 1);
    },
    async syncDocuments() {
      // Удалить документы, которых больше нет
      const currentIds = this.documents.filter((d) => d.id).map((d) => d.id);
      const toDelete = this.originalDocumentIds.filter((id) => !currentIds.includes(id));

      for (const id of toDelete) {
        await this.apollo().admin.mutate({
          mutation: PRODUCT_DOCUMENT_DELETE,
          variables: { id },
        });
      }

      // Создать новые документы (без id)
      for (const doc of this.documents) {
        if (!doc.title) continue;

        if (!doc.id) {
          await this.apollo().admin.mutate({
            mutation: PRODUCT_DOCUMENT_CREATE,
            variables: {
              product_id: parseInt(this.id),
              title: doc.title,
              file_path: doc.file?.path || "",
              original_name: doc.file?.origin_name || null,
              mime_type: doc.file?.type || null,
              size: doc.file?.size || null,
              group: doc.group?.id || null,
              position: doc.position || 0,
            },
          });
        }
      }
    },
    openPreviewModal() {
      useDefaultStore().modals.push({
        component: shallowRef(PreviewModal),
      });
    },
    openBarcodeModal(code) {
      useDefaultStore().modals.push({
        component: shallowRef(BarcodeModal),
        options: {
          code,
          title: this.form.title.value,
        },
      });
    },
    removeImage(i) {
      if (this.form.images.value.length > 1) {
        if (confirm("Вы уверены?")) {
          this.form.images.value.splice(i, 1);
        }
      }
    },
    getUnitType(id) {
      return this.units.find((item) => item.id === id);
    },
    unitLabel(id) {
      return (this.units.find((item) => item.id === id) || {}).title || "";
    },
    // Остаток из поля ввода: дробный («12,806» / «12.806»), не меньше нуля,
    // до грамма (DECIMAL(14,3)). Пусто или не число — null.
    parseStockQuantity(value) {
      const parsed = parseFloat(String(value ?? "").replace(/\s/g, "").replace(",", "."));
      if (!Number.isFinite(parsed)) return null;
      return Math.round(Math.max(0, parsed) * 1000) / 1000;
    },
    attributeValueKey(typeId) {
      switch (typeId) {
        case 1:
          return "string_value";
        case 2:
          return "text_value";
        case 3:
          return "int_value";
        case 4:
          return "float_value";
        case 5:
          return "attribute_value_id";
        default:
          return null;
      }
    },
    pickAttributeScalarValue(item) {
      for (const key of ["string_value", "text_value", "int_value", "float_value"]) {
        const v = item[key];
        if (v !== null && v !== undefined && v !== "") return v;
      }
      return null;
    },
    assignAttributeValueByType(target, source) {
      const targetKey = this.attributeValueKey(target.attribute?.type_id);
      target.string_value = null;
      target.text_value = null;
      target.int_value = null;
      target.float_value = null;
      target.attribute_value = null;
      target.attribute_value_id = null;

      if (!targetKey) return;

      if (targetKey === "attribute_value_id") {
        target.attribute_value = source.attribute_value ?? null;
        target.attribute_value_id = source.attribute_value?.id ?? source.attribute_value_id ?? null;
        return;
      }

      const value = this.pickAttributeScalarValue(source);
      if (value !== null) target[targetKey] = value;
    },
    collectData() {
      let variables = {};
      variables.shop_id = this.shop_id || undefined;
      variables.id = this.id || undefined;
      variables.articul = this.form.articul.value ? this.form.articul.value.trim() : undefined;
      variables.title = this.form.title.value || undefined;
      variables.description = this.form.description.value || undefined;
      variables.additional_info = this.form.additional_info.value || undefined;
      variables.category_ids = (this.form.categories.value ?? []).map((c) => c.id);
      variables.img = this.form.img.value ? this.form.img.value : undefined;
      variables.weight = parseInt(this.form.weight.value) ? parseInt(this.form.weight.value) : undefined;
      // В сетевом каталоге у карточки нет «своей» цены: цены и остатки ведёт 1С
      // пер-магазин. Редактирование карточки не должно их трогать — не шлём.
      variables.price = this.shopPricesTableVisible
        ? undefined
        : parseFloat(String(this.form.price.value).replace(/\s/g, "").replace(",", ".")) ||
          undefined;
      // Цены от количества (MID-308). Аргумент уходит только при включённом модуле (при
      // выключенном бэкенд его отклоняет), вне сетевого каталога (там лесенка пер-магазинная
      // и сохраняется строкой таблицы) и только если лесенку правили: «не передан» = не трогать.
      // Иначе любая правка карточки гоняла бы лесенку из 1С через строгую валидацию ручного ввода.
      if (this.priceTiersEnabled && !this.shopPricesTableVisible && this.priceTiersDirty) {
        variables.price_tiers = this.buildPriceTiersInput(this.form.price_tiers);
      }
      variables.info_weight_type_id = this.form.info_weight_type_id.value
        ? this.form.info_weight_type_id.value.id
        : undefined;
      // Источник кванта определяется тумблером manualQuantumEnabled, а не наличием
      // категорийного кванта. Из товара → шлём шаг + единицу семейства; из категории → null.
      const wantManualQuantum = this.manualQuantumEnabled && this.smallestUnitId != null;
      variables.manual_quantum_step = wantManualQuantum
        ? parseInt(this.form.manual_quantum_step.value) || null
        : null;
      variables.manual_quantum_unit_type_id =
        wantManualQuantum && parseInt(this.form.manual_quantum_step.value)
          ? this.smallestUnitId
          : null;
      // Учёт остатков: тумблер stockOverrideEnabled + форма stock_mode/fixed_*.
      const wantStockOverride = this.stockOverrideEnabled && this.form.stock_mode.value;
      variables.stock_mode = wantStockOverride ? this.form.stock_mode.value.id : null;
      variables.fixed_stock_quantity =
        wantStockOverride && this.form.stock_mode.value.id === "fixed"
          ? parseInt(this.form.fixed_stock_quantity.value) || null
          : null;
      variables.fixed_stock_unit_type_id =
        wantStockOverride && this.form.stock_mode.value.id === "fixed"
          ? (this.form.fixed_stock_unit_type_id.value?.id ?? null)
          : null;
      // Остаток в базовой единице 1С (кг/л/шт) с дробью: parseInt обрезал
      // 12.806 кг до 12 при каждом сохранении карточки.
      variables.stock_quantity = this.shopPricesTableVisible
        ? undefined
        : this.parseStockQuantity(this.form.quantity.value) ?? undefined;
      variables.is_culinary = this.form.is_culinary.value;
      variables.sugar_free = this.form.sugar_free.value;
      variables.on_search_page = this.form.on_search_page.value;
      variables.served_warm = this.form.served_warm.value;
      variables.pickup_only = this.form.pickup_only.value;
      variables.adult_only = this.form.adult_only.value;
      variables.is_marked = this.form.is_marked.value;
      variables.is_new = this.form.is_new.value;
      variables.is_hurry_up = this.form.is_hurry_up.value;
      variables.custom_checkboxes = JSON.stringify(this.form.custom_checkboxes.value || {});
      variables.vat_code = this.form.vat_code.value ? this.form.vat_code.value.id : null;
      variables.brand_id = this.form.brand_id.value ? this.form.brand_id.value.id : null;
      variables.video_path = this.form.video.value?.path || null;

      variables.meta_description = this.form.meta_description.value
        ? this.form.meta_description.value
        : undefined;
      variables.meta_title = this.form.meta_title.value ? this.form.meta_title.value : undefined;
      variables.meta_keywords = this.form.meta_keywords.value ? this.form.meta_keywords.value : undefined;

      variables.ready_fixed_time = this.form.ready_fixed_time.value || null;

      variables.images =
        !this.form.images.value.length || this.form.images.value[0].value === null
          ? undefined
          : this.form.images.value.map((item) => item.value);

      // variables.prices = this.form.prices.value.map((item) => {
      //   return {
      //     shop_id: item.shop.id,
      //     value: parseInt(item.value),
      //   };
      // });

      variables.attributes = this.form.attributes.value
        .map((item) => {
          const key = this.attributeValueKey(item.attribute?.type_id);
          if (!key) return null;

          let raw =
            key === "attribute_value_id"
              ? item.attribute_value?.id ?? item.attribute_value_id
              : this.pickAttributeScalarValue(item);

          if (raw === null || raw === undefined || raw === "") return null;

          // Инпуты отдают строки, а int_value/float_value/attribute_value_id
          // в AttributeInput типизированы числами — без каста мутация падает
          // на GraphQL-коэрции.
          if (key === "int_value" || key === "attribute_value_id") {
            raw = parseInt(raw);
          } else if (key === "float_value") {
            raw = parseFloat(String(raw).replace(",", "."));
          }
          if (typeof raw === "number" && Number.isNaN(raw)) return null;

          return {
            id: item.id || undefined,
            attribute_id: item.attribute?.id ?? item.attribute_id ?? undefined,
            [key]: raw,
          };
        })
        .filter((item) => item);
      variables.supplements = this.form.supplements.value.map((item) => {
        return {
          id: item?.id || undefined,
          title: item?.title?.value || undefined,
          is_required: item?.is_required?.value ?? undefined,
          type: item?.type?.value?.title || undefined,
          values: item.values.map((val) => {
            const isCatalog = val.value_type === "catalog";
            const newVal = {
              id: val?.id || undefined,
              title: val.title?.value || undefined,
              price: val.price?.value != null && val.price?.value !== "" ? parseInt(val.price?.value) : undefined,
              product_id: isCatalog && val.product_id ? val.product_id : undefined,
              max_quantity: val.max_quantity?.value != null && val.max_quantity?.value !== "" ? parseInt(val.max_quantity?.value) : undefined,
              track_stock: isCatalog && val.track_stock?.value != null ? val.track_stock.value.id : undefined,
              position: val.position?.value != null && val.position?.value !== "" ? parseInt(val.position?.value) : undefined,
            };
            return newVal;
          }),
        };
      });

      return variables;
    },
    validateRequiredAttributes() {
      const missing = [];
      for (const item of this.categoryAttributes) {
        if (!item.is_required) continue;
        const val = this.form.attributes.value.find(
          (a) => a.attribute?.id === item.attribute.id || a.attribute_id === item.attribute.id
        );
        if (!val) {
          missing.push(item.attribute.title);
          continue;
        }
        const hasValue =
          val.string_value || val.text_value || val.int_value || val.float_value ||
          val.attribute_value || val.attribute_value_id;
        if (!hasValue) {
          missing.push(item.attribute.title);
        }
      }
      return missing;
    },
    submit() {
      this.resetErrors();

      const missingAttrs = this.validateRequiredAttributes();
      if (missingAttrs.length) {
        alert('Заполните обязательные характеристики: ' + missingAttrs.join(', '));
        return;
      }

      const variables = this.collectData();

      // Лесенка с нечисловыми значениями: ошибки уже показаны в строках редактора.
      if (variables.price_tiers === null) return;

      this.apollo()
        .admin.mutate({
          mutation: this.id ? PRODUCT_UPDATE : PRODUCT_CREATE,
          variables,
        })
        .then(async () => {
          if (this.id) {
            await this.syncDocuments();
            alert("Изменения сохранены");
            this.get();
          } else {
            this.$router.push({ name: "products" });
          }
        })
        .catch(({ graphQLErrors }) => {
          const errors = graphQLErrors ?? [];
          this.parseGqlErrors(errors);

          // Ошибки, не относящиеся к валидации полей (например, брошенные из
          // резолвера), parseGqlErrors не раскладывает по полям — иначе сохранение
          // молча падает без обратной связи. Показываем их текст пользователю.
          const generic = errors.filter((err) => err.extensions?.category !== "validation");
          if (generic.length) {
            alert(generic.map((err) => err.message).join("\n"));
          }
        });
    },
    /**
     * Тут parseGqlErrors и resetErrors не импортируется с utils.js потому что уникальный функционал обработки ошибок
     **/
    resetErrors() {
      Object.values(this.form.supplements.value).forEach(({ values }) => {
        values.forEach((val) => {
          if (val.price) val.price.errors = [];
          if (val.title) val.title.errors = [];
          if (val.max_quantity) val.max_quantity.errors = [];
          if (val.track_stock) val.track_stock.errors = [];
          if (val.position) val.position.errors = [];
        });
      });

      Object.keys(this.form).forEach((key) => {
        this.form[key].errors = [];
        if (Array.isArray(this.form[key].value)) {
          this.form[key].value.forEach((k) => {
            if (k.errors) {
              k.errors = [];
            }
            Object.values(k).forEach((item) => {
              if (item && item.errors) {
                item.errors = [];
              }
            });
          });
        }
      });
    },
    parseGqlErrors(graphQLErrors) {
      /**
       * Закоментированнае потом удалить если логика поменяется
       */
      graphQLErrors.forEach((err) => {
        if (err.extensions.category === "validation") {
          Object.keys(err.extensions.validation).forEach((key) => {
            let arr = key.split(".");
            if (key === "category_ids" || key === "category_id") {
              this.form.categories.errors = err.extensions.validation[key];
            }
            if (arr.length <= 1) {
              if (this.form[key]) {
                this.form[key].errors = err.extensions.validation[key];
              }
            } else {
              let [parent, index, child, ch_idx, ch_p] = arr;

              if (parent === "supplements") {
                this.form[parent].value[index][child].errors = ["поле обязательно для заполнения"];
                if (child === "values") {
                  this.form[parent].value[index][child][ch_idx][ch_p].errors = [
                    "поле обязательно для заполнения",
                  ];
                }
              }
              // Цены от количества (MID-308): price_tiers.N.min_quantity / price_tiers.N.value
              if (parent === "price_tiers" && this.form.price_tiers.value[index]?.[child]) {
                this.form.price_tiers.value[index][child].errors = [...err.extensions.validation[key]];
              }
              // if (parent === "prices") {
              //   // this.form[parent];
              //   this.form[parent].value[index].errors = ["Поле обязательно для заполнения"];
              // }
            }
          });
        }
      });
    },
    togglePublish() {
      // 1 Активный
      // 3 Не активный
      let status = this.form.status_id.value === 1;

      this.apollo()
        .admin.mutate({
          mutation: status ? PRODUCT_HIDE : PRODUCT_PUBLISH,
          variables: {
            id: this.id,
          },
        })
        .then(() => {
          this.get();
        })
        .catch(({ graphQLErrors }) => {
          graphQLErrors.forEach((err) => {
            if (err.extensions.category === "validation") {
              Object.entries(err.extensions.validation).forEach(([key, value]) => {
                let result = "";
                value.forEach((key) => {
                  result = ` ${result} ${key}`;
                });

                alert(result);
              });
            }
          });
        });
    },
    flattenCategories(categories) {
      const result = [];
      for (const cat of categories) {
        result.push(cat);
        if (cat.child_categories && cat.child_categories.length) {
          result.push(...this.flattenCategories(cat.child_categories));
        }
      }
      return result;
    },
    getOptions() {
      // Без выбранного магазина (сетевой каталог) запрос options.graphql
      // непригоден: shop(id:) без аргумента — это @find без фильтра, и при
      // более чем одном магазине Lighthouse отдаёт ошибку «Query returned more
      // than one result» (весь ответ с категориями теряется), а
      // shop_categories(shop_id: null) и так возвращает null. Берём глобальное
      // дерево категорий — в сети категории общие для всех магазинов.
      if (!this.shop_id) {
        this.apollo()
          .admin.query({ query: GLOBAL_CATEGORIES })
          .then(({ data }) => {
            this.shop = null;
            this.categoriesTree = data.parent_categories ?? [];
            this.categories = this.flattenCategories(this.categoriesTree);
          });
      } else {
        this.apollo()
          .admin.query({
            query: OPTIONS,
            variables: {
              shop_id: this.shop_id,
            },
          })
          .then(({ data }) => {
            this.shop = data.shop ?? null;
            this.categoriesTree = data.shop_categories ?? [];
            this.categories = this.flattenCategories(this.categoriesTree);
          });
      }

      if (this.moduleStore.isModuleEnabled("brands")) {
        this.apollo()
          .admin.query({
            query: BRANDS,
            variables: { is_active: true },
          })
          .then(({ data }) => {
            this.brands = (data.admin_brands ?? []).map((b) => ({
              id: b.id,
              title: b.title,
            }));
          });
      }

      this.apollo()
        .admin.query({ query: DOCUMENT_GROUPS })
        .then(({ data }) => {
          this.documentGroups = (data.product_document_groups ?? []).map((g) => ({
            id: g,
            title: g,
          }));
        });
    },
    async get() {
      /**
        Получаем дефолтные атрибуты и отрисовываем их это на будущее если потребуется делать создание товара
        пока выводятся пустые формы для аттрибутов если нужно будет сделать добавление товара не с 1С надо будет сделать
        вывод для добавления стоимости через ArrayComponent (если продукт привязывается к нескольким магазинам)
      **/
      if (!this.loading) {
        // Получение дефолтных атрибутов выводятся справа в колонках
        await this.apollo()
          .admin.query({
            query: DEFAULT_ATTRIBUTES,
          })
          .then(({ data }) => {
            if (data && data.default_attributes) {
              this.form.attributes.value = data.default_attributes.map((item) => {
                return {
                  attribute: {
                    id: item.id,
                    category_type_id: item.category_type_id,
                    title: item.title,
                    type_id: item.type_id,
                    values: item.values ?? [],
                  },
                  string_value: null,
                  text_value: null,
                  int_value: null,
                  float_value: null,
                  attribute_value: null,
                  attribute_value_id: null,
                };
              });
            }
          });
      }
      // Заполнение всех полей
      if (!this.loading && this.id) {
        this.loading = true;
        let variables = {
          id: this.id,
        };
        this.product = null;
        await this.apollo()
          .admin.query({
            query: PRODUCT,
            variables,
          })
          .then(async ({ data }) => {
            /**
                Сделал Асихронно для того что бы атрибуты категорий успевали отрисоваться
                перед тем как будет заполнение значением
            **/
            if (data && data.admin_products_item) {
              this.form.categories.value = data.admin_products_item.categories ?? [];

              this.form.attributes.value.forEach((item) => {
                data.admin_products_item.attributes.forEach((atr) => {
                  if (atr.attribute.id === item.attribute.id) {
                    item.id = atr.id ?? null;
                    this.assignAttributeValueByType(item, atr);
                  }
                });
              });

              /**
               заполнение динамических атрибутов категорий (category_type_id === 103)
              **/
              data.admin_products_item.attributes
                .filter((f) => f.attribute.category_type_id === 103)
                .forEach((item) => {
                  let existing = this.getCategoryAttrValue(item.attribute);
                  existing.id = item.id ?? null;
                  existing.string_value = item.string_value;
                  existing.text_value = item.text_value;
                  existing.int_value = item.int_value;
                  existing.float_value = item.float_value;
                  existing.attribute_value = item.attribute_value;
                  existing.attribute_value_id = item.attribute_value?.id ?? null;
                });

              this.form.ready_fixed_time.value = data.admin_products_item.ready_fixed_time
                ? dayjs(data.admin_products_item.ready_fixed_time, "HH:mm:ss").format("HH:mm")
                : null;

              this.form.barcode.value = data.admin_products_item.barcode;
              this.form.barcodes.value = data.admin_products_item.barcodes ?? [];
              this.form.articul.value = data.admin_products_item.articul;
              this.form.code.value = data.admin_products_item.code;
              this.form.plu.value = data.admin_products_item.plu;
              this.form.title.value = data.admin_products_item.title;
              this.form.weight.value = data.admin_products_item.weight;
              this.form.info_weight.value = data.admin_products_item.info_weight;
              // Ед. измерения показываем по сырой базе (квант перетирает info_weight_type_id).
              const rawUnitId = data.admin_products_item.base_unit_type_id
                ?? data.admin_products_item.info_weight_type_id;
              this.form.info_weight_type_id.value = rawUnitId ? this.getUnitType(rawUnitId) : null;
              // Квант: категорийный (read-only) + ручной (редактируемый fallback) + база для отображения.
              this.base_unit_type_id = data.admin_products_item.base_unit_type_id ?? null;
              this.category_quantum_step = data.admin_products_item.category_quantum_step ?? null;
              this.category_quantum_unit_type_id = data.admin_products_item.category_quantum_unit_type_id ?? null;
              this.price_per_small_unit = data.admin_products_item.default_price?.price_per_small_unit ?? null;
              this.form.manual_quantum_step.value = data.admin_products_item.manual_quantum_step ?? null;
              // Тумблер «из товара» включён, если у товара задан ручной квант-исключение.
              this.manualQuantumEnabled = !!data.admin_products_item.manual_quantum_step;
              // Учёт остатков: гидрация тумблера + формы из данных товара.
              this.stockOverrideEnabled = !!data.admin_products_item.stock_mode;
              if (data.admin_products_item.stock_mode) {
                this.form.stock_mode.value =
                  this.productStockModes.find((m) => m.id === data.admin_products_item.stock_mode) ?? null;
                this.form.fixed_stock_quantity.value = data.admin_products_item.fixed_stock_quantity ?? null;
                this.form.fixed_stock_unit_type_id.value =
                  data.admin_products_item.fixed_stock_unit_type_id != null
                    ? this.stockUnits.find((u) => u.id === data.admin_products_item.fixed_stock_unit_type_id) ?? null
                    : null;
              }
              // Пер-магазинные цены и остатки — источник таблицы «Цены и остатки
              // по магазинам» в сетевом каталоге.
              this.productPrices = data.admin_products_item.prices ?? [];
              this.productRemains = data.admin_products_item.remains ?? [];
              // Цены от количества (MID-308): ступени по всем магазинам. В сетевом режиме
              // группируются по shop_id в priceTiersByShop, в обычном берётся лесенка магазина товара.
              this.productPriceTiers = data.admin_products_item.price_tiers ?? [];
              this.form.price_tiers.value = this.priceTierRowsFromApi(this.ownShopPriceTiers);
              this.priceTiersDirty = false;
              // Единое поле остатка осмысленно только вне сетевого каталога:
              // там у товара один магазин. В сети remains[0] — случайный магазин.
              this.form.quantity.value =
                !this.shopPricesTableVisible && data.admin_products_item.remains?.length
                  ? data.admin_products_item.remains[0].quantity
                  : null;
              this.form.meta_description.value = data.admin_products_item.meta_description;
              this.form.meta_title.value = data.admin_products_item.meta_title;
              this.form.meta_keywords.value = data.admin_products_item.meta_keywords;
              this.form.fill_percent.value = data.admin_products_item.fill_percent;
              this.form.images.value = data.admin_products_item.images.map((item) => {
                return {
                  value: item,
                };
              });
              this.form.img.value = data.admin_products_item.img;
              // default_price не скоупится по магазину: в сетевом каталоге это
              // цена случайного магазина, подставлять её в форму нельзя —
              // сохранение карточки записало бы её в якорь.
              if (!this.shopPricesTableVisible) {
                this.form.price.value = data.admin_products_item.default_price.value;
              }
              // Включаем синхронизацию цены по кванту только после гидрации,
              // чтобы не перезатереть загруженную цену при открытии карточки.
              this.$nextTick(() => {
                this.priceSyncReady = true;
              });
              // this.form.prices.value = data.admin_products_item.prices.map((item) => ({
              //   value: item.value,
              //   shop: item.shop,
              //   errors: [],
              // }));
              this.form.supplements.value = data.admin_products_item.supplements.map((item) => {
                const options = [
                  { id: 1, title: "one", value: "Выбор одного" },
                  { id: 2, title: "many", value: "Выбор многих" },
                ];
                return {
                  id: item.id,
                  title: {
                    value: item.title,
                    errors: [],
                  },
                  is_required: {
                    value: item.is_required,
                    errors: [],
                  },
                  type: {
                    value: options.find((it) => it.title === item.type),
                    errors: [],
                  },
                  options,
                  values: item.values.map((val) => {
                    return {
                      id: val?.id,
                      value_type: val.product_id ? "catalog" : "manual",
                      title: {
                        value: val.title,
                        errors: [],
                      },
                      price: {
                        value: val.price,
                        errors: [],
                      },
                      product_id: val.product_id || null,
                      product: val.product ? { id: val.product.id, title: val.product.title } : null,
                      productSearch: "",
                      productSearchResults: [],
                      productSearchLoading: false,
                      max_quantity: {
                        value: val.max_quantity ?? null,
                        errors: [],
                      },
                      track_stock: {
                        value: val.track_stock != null
                          ? (val.track_stock
                            ? { id: true, title: "Учитывать остаток" }
                            : { id: false, title: "Не учитывать" })
                          : null,
                        errors: [],
                      },
                      position: {
                        value: val.position ?? null,
                        errors: [],
                      },
                    };
                  }),
                };
              });

              this.form.status_id.value = data.admin_products_item.status_id;
              this.product_parent_id = data.admin_products_item.parent_id || null;
              this.form.is_culinary.value = data.admin_products_item.is_culinary || false;
              this.form.sugar_free.value = data.admin_products_item.sugar_free || false;
              this.form.on_search_page.value = data.admin_products_item.on_search_page || false;
              this.form.served_warm.value = data.admin_products_item.served_warm || false;
              this.form.pickup_only.value = data.admin_products_item.pickup_only || false;
              this.form.adult_only.value = data.admin_products_item.adult_only || false;
              this.form.is_marked.value = data.admin_products_item.is_marked || false;
              this.form.is_new.value = data.admin_products_item.is_new || false;
              this.form.is_hurry_up.value = data.admin_products_item.is_hurry_up || false;
              this.form.custom_checkboxes.value = parseCustomCheckboxes(
                data.admin_products_item.custom_checkboxes,
              );
              this.form.vat_code.value =
                this.vatCodeOptions.find((o) => o.id === data.admin_products_item.vat_code) || null;
              this.form.brand_id.value = data.admin_products_item.brand || null;
              this.form.video.value = data.admin_products_item.video_path
                ? { path: data.admin_products_item.video_path, origin_name: "video" }
                : null;

              // Документы товара
              this.documents = (data.admin_products_item.documents || []).map((doc) => ({
                id: doc.id,
                title: doc.title,
                file: doc.file_path ? { path: doc.file_path, origin_name: doc.original_name, size: doc.size, type: doc.mime_type } : null,
                group: doc.group ? { id: doc.group, title: doc.group } : null,
                position: doc.position || 0,
                _groupSearch: "",
              }));
              this.originalDocumentIds = this.documents.map((d) => d.id);

              this.form.description.value = data.admin_products_item.description;
              this.form.additional_info.value = data.admin_products_item.additional_info;
              this.info_weight_type_id =
                data.admin_products_item.info_weight_type_id;

              this.loading = false;

              // this.removing = null;
            }
          })
          .catch(() => {
            this.loading = false;
            // this.removing = null;
          })
          .finally(() => {
            // Компонент мог размонтироваться, пока запрос был в полёте
            // (сохранение → мгновенный уход со страницы): refs уже null.
            this.$refs.images?.add();
          });
      }
    },
    // ===== Цены от количества (MID-308) =====
    // Ступени из API → строки редактора в паттерне {value, errors}.
    priceTierRowsFromApi(tiers) {
      return [...(tiers ?? [])]
        .sort((a, b) => Number(a.min_quantity) - Number(b.min_quantity))
        .map((tier) => ({
          min_quantity: { value: String(tier.min_quantity), errors: [] },
          value: { value: String(tier.value), errors: [] },
        }));
    },
    parseDecimalInput(raw) {
      return parseFloat(
        String(raw ?? "")
          .replace(/\s/g, "")
          .replace(",", ".")
      );
    },
    // Собирает аргумент price_tiers из поля {value: [строки], errors}. Полностью пустые строки
    // отбрасываются из самого поля, поэтому индексы отправленных ступеней совпадают с индексами
    // ошибок сервера price_tiers.N.*. Нечисловое значение: ошибка в строке и null (запрос не уходит,
    // иначе сервер ответил бы ошибкой приведения типа Float!, которую не разложить по полям).
    buildPriceTiersInput(field) {
      field.value = field.value.filter(
        (row) =>
          String(row.min_quantity.value ?? "").trim() !== "" || String(row.value.value ?? "").trim() !== ""
      );

      let valid = true;
      const input = field.value.map((row) => {
        const minQuantity = this.parseDecimalInput(row.min_quantity.value);
        const value = this.parseDecimalInput(row.value.value);

        row.min_quantity.errors =
          Number.isFinite(minQuantity) && minQuantity > 0 ? [] : ["Укажите число больше нуля"];
        row.value.errors = Number.isFinite(value) && value > 0 ? [] : ["Укажите число больше нуля"];
        if (row.min_quantity.errors.length || row.value.errors.length) valid = false;

        return { min_quantity: minQuantity, value };
      });

      return valid ? input : null;
    },
    isPriceTierErrorKey(key) {
      return key === "price_tiers" || key.startsWith("price_tiers.");
    },
    hasPriceTierErrors(graphQLErrors) {
      return graphQLErrors.some(
        (err) =>
          err.extensions?.category === "validation" &&
          Object.keys(err.extensions.validation).some((key) => this.isPriceTierErrorKey(key))
      );
    },
    // Строки редактора лесенки магазина: буфер правок, а пока его нет, ступени из API.
    shopTierRows(row) {
      return (
        this.shopTierEdits[row.shopId]?.form.price_tiers.value ??
        this.priceTierRowsFromApi(this.priceTiersByShop[row.shopId])
      );
    },
    shopTierErrors(row) {
      return this.shopTierEdits[row.shopId]?.form.price_tiers.errors ?? [];
    },
    shopTierCount(row) {
      const edit = this.shopTierEdits[row.shopId];
      return edit ? edit.form.price_tiers.value.length : this.priceTiersByShop[row.shopId]?.length ?? 0;
    },
    shopTiersToggleLabel(row) {
      const count = this.shopTierCount(row);
      return count ? `Цены от количества: ${count}` : "Цены от количества";
    },
    shopTiersToggleIcon(row) {
      return this.shopTiersExpanded.includes(row.shopId) ? "arrow-up-s-line" : "arrow-down-s-line";
    },
    setShopTierRows(row, rows) {
      this.shopTierEdits = {
        ...this.shopTierEdits,
        [row.shopId]: { dirty: true, form: { price_tiers: { value: rows, errors: [] } } },
      };
    },
    expandShopTiers(shopId) {
      if (!this.shopTiersExpanded.includes(shopId)) this.shopTiersExpanded.push(shopId);
    },
    toggleShopTiers(row) {
      if (this.shopTiersExpanded.includes(row.shopId)) {
        this.shopTiersExpanded = this.shopTiersExpanded.filter((id) => id !== row.shopId);
      } else {
        this.shopTiersExpanded.push(row.shopId);
      }
    },
    // Правка ячейки строки таблицы «Цены и остатки по магазинам»: копим
    // несохранённые значения отдельно от исходных row.price/row.quantity,
    // чтобы кнопка «Сохранить» знала, что именно отправлять в мутацию.
    setShopPriceEdit(row, field, value) {
      const current = this.shopPriceEdits[row.shopId] ?? {
        price: row.price,
        quantity: row.quantity ?? 0,
      };
      this.shopPriceEdits = {
        ...this.shopPriceEdits,
        [row.shopId]: { ...current, [field]: value },
      };
    },
    // Перечитать prices/remains карточки после сохранения/удаления строки
    // (источник таблицы) — без переинициализации остальной формы, как это
    // делает get().
    refetchShopPrices() {
      this.apollo()
        .admin.query({
          query: PRODUCT,
          variables: { id: this.id },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.productPrices = data.admin_products_item.prices ?? [];
          this.productRemains = data.admin_products_item.remains ?? [];
          this.productPriceTiers = data.admin_products_item.price_tiers ?? [];
        });
    },
    saveShopPriceRow(row) {
      // Цены от количества (MID-308): лесенка уходит, только если модуль включён и её правили.
      const tierEdit = this.priceTiersEnabled ? this.shopTierEdits[row.shopId] : null;
      const tiersDirty = Boolean(tierEdit?.dirty);
      // Правили только лесенку: цена и остаток строки уходят прежними.
      const edit =
        this.shopPriceEdits[row.shopId] ??
        (tiersDirty ? { price: row.price, quantity: row.quantity ?? 0 } : null);
      if (!edit) return;

      const price = parseFloat(String(edit.price).replace(/\s/g, "").replace(",", "."));
      const quantity = this.parseStockQuantity(edit.quantity);

      if (!price || price <= 0) {
        alert("Цена должна быть больше нуля");
        return;
      }

      const variables = {
        product_id: Number(this.id),
        shop_id: row.shopId,
        price,
        stock_quantity: quantity ?? 0,
      };

      if (tiersDirty) {
        resetFormErrors.call(tierEdit);
        const priceTiers = this.buildPriceTiersInput(tierEdit.form.price_tiers);
        if (priceTiers === null) {
          this.expandShopTiers(row.shopId);
          return;
        }
        variables.price_tiers = priceTiers;
      }

      this.shopPriceSaving.push(row.shopId);
      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_SHOP_PRICE_SAVE,
          variables,
        })
        .then(() => {
          delete this.shopPriceEdits[row.shopId];
          delete this.shopTierEdits[row.shopId];
          this.refetchShopPrices();
        })
        .catch(({ graphQLErrors }) => {
          const errors = graphQLErrors ?? [];
          // Ошибки price_tiers и price_tiers.N.* раскладываем по строкам редактора
          // системным parseGqlErrors (контекст = буфер строки с вложенным form).
          const hasTierErrors = tiersDirty && this.hasPriceTierErrors(errors);
          if (hasTierErrors) {
            parseFormGqlErrors.call(tierEdit, errors);
            this.expandShopTiers(row.shopId);
          }
          const messages = errors.flatMap((err) =>
            err.extensions?.category === "validation"
              ? Object.entries(err.extensions.validation)
                  .filter(([key]) => !this.isPriceTierErrorKey(key))
                  .flatMap(([, value]) => value)
              : [err.message]
          );
          if (messages.length || !hasTierErrors) {
            alert(messages.join("\n") || "Не удалось сохранить цену");
          }
        })
        .finally(() => {
          this.shopPriceSaving = this.shopPriceSaving.filter((id) => id !== row.shopId);
        });
    },
    deleteShopPriceRow(row) {
      if (!confirm(`Убрать товар из магазина «${row.shopTitle}»?`)) return;

      this.shopPriceSaving.push(row.shopId);
      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_SHOP_PRICE_DELETE,
          variables: { product_id: Number(this.id), shop_id: row.shopId },
        })
        .then(() => {
          delete this.shopPriceEdits[row.shopId];
          this.refetchShopPrices();
        })
        .catch(({ graphQLErrors }) => {
          const errors = graphQLErrors ?? [];
          const messages = errors.flatMap((err) =>
            err.extensions?.category === "validation"
              ? Object.values(err.extensions.validation).flat()
              : [err.message]
          );
          alert(messages.join("\n") || "Не удалось убрать товар из магазина");
        })
        .finally(() => {
          this.shopPriceSaving = this.shopPriceSaving.filter((id) => id !== row.shopId);
        });
    },
    // Форма «Добавить магазин»: список опций грузится сразу (пустой поиск),
    // дальше — по вводу через SelectComponent (@update:search), с дебаунсом
    // как у поиска магазина в orders/index.vue.
    openAddShop() {
      this.addShopMode = true;
      this.addShopSelected = null;
      this.addShopPrice = "";
      this.addShopQuantity = "";
      this.addShopOptions = [];
      this.searchAddShopOptions("");
    },
    searchAddShopOptions(query) {
      if (this.addShopSearchDebounce) clearTimeout(this.addShopSearchDebounce);
      this.addShopSearchDebounce = setTimeout(() => {
        this.addShopOptionsLoading = true;
        this.apollo()
          .admin.query({
            query: ADMIN_SHOPS_SEARCH,
            variables: { title: query || undefined, first: 20 },
            fetchPolicy: "network-only",
          })
          .then(({ data }) => {
            const usedIds = this.usedShopIds;
            this.addShopOptions = (data.shops_paginate?.data || []).filter(
              (shop) => !usedIds.has(Number(shop.id))
            );
          })
          .finally(() => {
            this.addShopOptionsLoading = false;
          });
      }, query ? 500 : 0);
    },
    addShopPriceRow() {
      if (!this.addShopSelected) return;

      const price = parseFloat(
        String(this.addShopPrice).replace(/\s/g, "").replace(",", ".")
      );
      const quantity = this.parseStockQuantity(this.addShopQuantity);

      if (!price || price <= 0) {
        alert("Цена должна быть больше нуля");
        return;
      }

      this.addShopSaving = true;
      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_SHOP_PRICE_SAVE,
          variables: {
            product_id: Number(this.id),
            shop_id: Number(this.addShopSelected.id),
            price,
            stock_quantity: quantity ?? 0,
          },
        })
        .then(() => {
          this.addShopMode = false;
          this.refetchShopPrices();
        })
        .catch(({ graphQLErrors }) => {
          const errors = graphQLErrors ?? [];
          const messages = errors.flatMap((err) =>
            err.extensions?.category === "validation"
              ? Object.values(err.extensions.validation).flat()
              : [err.message]
          );
          alert(messages.join("\n") || "Не удалось добавить магазин");
        })
        .finally(() => {
          this.addShopSaving = false;
        });
    },
    // Открытие формы «Применить ко всем магазинам»: сброс полей при каждом
    // открытии — по паттерну openAddShop() (иначе после «Отмена» и повторного
    // открытия остаются старые значения).
    openApplyAll() {
      this.applyAllMode = true;
      this.applyAllPrice = "";
      this.applyAllQuantity = "";
      this.applyAllTiersEnabled = false;
      this.applyAllTiers = { form: { price_tiers: { value: [], errors: [] } } };
    },
    // Форма «Применить ко всем магазинам»: одна цена/остаток разом во все
    // магазины сети, привязанные к товару и нет (ProductShopPricesApplyToAll).
    applyPriceToAllShops() {
      const price = parseFloat(String(this.applyAllPrice).replace(/\s/g, "").replace(",", "."));
      const quantity = this.parseStockQuantity(this.applyAllQuantity);

      if (!price || price <= 0) {
        alert("Цена должна быть больше нуля");
        return;
      }

      const variables = {
        product_id: Number(this.id),
        price,
        stock_quantity: quantity ?? 0,
      };
      let confirmText = "Цена и остаток будут установлены во ВСЕХ магазинах сети. Продолжить?";

      // Цены от количества (MID-308): лесенка уходит только при включённом модуле и галочке
      // «Заменить цены от количества во всех магазинах»; пустой список = удалить лесенки.
      const withTiers = this.priceTiersEnabled && this.applyAllTiersEnabled;
      if (withTiers) {
        resetFormErrors.call(this.applyAllTiers);
        const priceTiers = this.buildPriceTiersInput(this.applyAllTiers.form.price_tiers);
        if (priceTiers === null) return;
        variables.price_tiers = priceTiers;
        confirmText = priceTiers.length
          ? "Цена, остаток и цены от количества будут установлены во ВСЕХ магазинах сети. Продолжить?"
          : "Цена и остаток будут установлены, а цены от количества удалены во ВСЕХ магазинах сети. Продолжить?";
      }

      if (!confirm(confirmText)) return;

      this.applyAllRunning = true;
      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_SHOP_PRICES_APPLY_TO_ALL,
          variables,
        })
        .then(({ data }) => {
          alert(`Обработано магазинов: ${data.ProductShopPricesApplyToAll}`);
          this.applyAllMode = false;
          this.applyAllPrice = "";
          this.applyAllQuantity = "";
          this.applyAllTiersEnabled = false;
          this.applyAllTiers = { form: { price_tiers: { value: [], errors: [] } } };
          this.refetchShopPrices();
        })
        .catch(({ graphQLErrors }) => {
          const errors = graphQLErrors ?? [];
          const hasTierErrors = withTiers && this.hasPriceTierErrors(errors);
          if (hasTierErrors) {
            parseFormGqlErrors.call(this.applyAllTiers, errors);
          }
          const messages = errors.flatMap((err) =>
            err.extensions?.category === "validation"
              ? Object.entries(err.extensions.validation)
                  .filter(([key]) => !this.isPriceTierErrorKey(key))
                  .flatMap(([, value]) => value)
              : [err.message]
          );
          if (messages.length || !hasTierErrors) {
            alert(messages.join("\n") || "Не удалось применить цену ко всем магазинам");
          }
        })
        .finally(() => {
          this.applyAllRunning = false;
        });
    },
  },
  computed: {
    // Единица остатка (remains.quantity) — базовая единица 1С, иначе крупная
    // единица семейства: остаток весовых в кг, объёмных в литрах (как у бэкенда
    // App\Support\StockUnits::stockUnit). Подпись рядом с полями остатка.
    stockUnitLabel() {
      const sel = this.form.info_weight_type_id.value;
      const selected = sel?.id ?? sel ?? null;
      const unitId =
        this.base_unit_type_id ?? ({ 2: 3, 3: 3, 4: 4, 5: 4 }[selected] ?? 1);
      return { 1: "шт", 2: "г", 3: "кг", 4: "л", 5: "мл" }[unitId] ?? "шт";
    },
    // Базовая единица товара: сырая из 1С (base_unit_type_id), fallback —
    // выбранная в форме «Ед. измерения» (для товаров, заведённых вручную).
    baseUnitId() {
      const sel = this.form.info_weight_type_id.value;
      return this.base_unit_type_id ?? (sel?.id ?? sel ?? null);
    },
    // Единица из 1С: для таких товаров «Ед. измерения» определяется выгрузкой
    // (base_unit_type_id) и используется в расчёте кванта/цены. Ручная смена не
    // влияет на подписи/цену (baseUnitId всё равно берёт 1С-значение) и только
    // путает — поэтому поле readonly. Ручные товары (base_unit_type_id == null)
    // остаются редактируемыми.
    isUnitFromOneC() {
      return this.base_unit_type_id !== null;
    },
    // Семейство: масса (2,3) / объём (4,5) / штуки (1).
    productFamily() {
      const u = this.baseUnitId;
      if ([2, 3].includes(u)) return "mass";
      if ([4, 5].includes(u)) return "volume";
      if (u === 1) return "piece";
      return null;
    },
    // Наименьшая единица семейства (id) — в ней задаётся шаг кванта.
    smallestUnitId() {
      return { mass: 2, volume: 5, piece: 1 }[this.productFamily] ?? null;
    },
    smallestUnitLabel() {
      return this.unitLabel(this.smallestUnitId);
    },
    // Блок кванта показываем для любого товара с заданной единицей (включая штучные).
    quantumApplicable() {
      return this.baseUnitId != null;
    },
    // Квант задан витринной категорией → ручной ввод недоступен, показываем информативно.
    hasCategoryQuantum() {
      return !!this.category_quantum_step && this.category_quantum_step > 0;
    },
    // Базовая цена 1С за базовую единицу = цена за наименьшую × размер базовой (кг/литр=1000).
    basePrice() {
      if (this.price_per_small_unit == null) return null;
      const size = [3, 4].includes(this.baseUnitId) ? 1000 : 1;
      return (this.price_per_small_unit * size).toFixed(2);
    },
    // Цена за порцию (категорийный квант).
    portionPrice() {
      if (this.price_per_small_unit == null || !this.category_quantum_step) return null;
      return (this.price_per_small_unit * this.category_quantum_step).toFixed(2);
    },
    // Цена за порцию (ручной квант, предпросмотр).
    manualPortionPrice() {
      const step = parseInt(this.form.manual_quantum_step.value);
      if (this.price_per_small_unit == null || !step) return null;
      return (this.price_per_small_unit * step).toFixed(2);
    },
    // Эффективная цена продажи = цена за актуальную порцию (повторяет
    // resolveQuantum на бэке): ручной квант → категорийный → базовая единица.
    // Из неё синхронизируется form.price.value при смене шага/кванта, чтобы в
    // мутацию не уходила цена за прежний шаг (иначе базовая цена 1С удваивается).
    effectiveSellPrice() {
      if (this.price_per_small_unit == null) return null;
      if (this.manualQuantumEnabled) {
        const step = parseInt(this.form.manual_quantum_step.value);
        if (step > 0) return +(this.price_per_small_unit * step).toFixed(2);
      }
      if (this.hasCategoryQuantum) {
        return +(this.price_per_small_unit * this.category_quantum_step).toFixed(2);
      }
      const size = [3, 4].includes(this.baseUnitId) ? 1000 : 1;
      return +(this.price_per_small_unit * size).toFixed(2);
    },
    // Сигнатура параметров кванта — триггер для синхронизации цены продажи.
    quantumPriceSignature() {
      return [
        this.manualQuantumEnabled,
        parseInt(this.form.manual_quantum_step.value) || 0,
        this.category_quantum_step ?? 0,
        this.baseUnitId,
        this.price_per_small_unit,
      ].join("|");
    },
    effectiveStockLabel() {
      const mode = this.effective_stock_mode;
      if (!mode || mode === "normal") return "Стандартный учёт остатков";
      if (mode === "ignore") return "Не учитываются (из категории)";
      if (mode === "fixed") {
        const qty = this.effective_fixed_stock;
        const unitTitle = this.stockUnits.find((u) => u.id === this.effective_fixed_stock_unit_type_id)?.title ?? "";
        return qty != null
          ? `Фиксированный лимит ${qty}${unitTitle ? " " + unitTitle : ""} (из категории)`
          : "Фиксированный лимит (из категории)";
      }
      return mode;
    },
    checkboxStore() {
      return useCheckboxConfigStore();
    },
    moduleStore() {
      return useModuleConfigStore();
    },
    networkCatalogEnabled() {
      return useModuleConfigStore().isModuleEnabled("network_catalog");
    },
    // Цены от количества (MID-308): весь UI лесенки и аргумент price_tiers только под флагом.
    priceTiersEnabled() {
      return useModuleConfigStore().isModuleEnabled("price_tiers");
    },
    // Лесенка хранится и вводится в базовых единицах товара (шт, килограмм, литр),
    // а не в порциях кванта: подпись та же, что у «Базовая цена 1С».
    priceTiersUnitLabel() {
      return this.unitLabel(this.baseUnitId) || "ед.";
    },
    // {[shop_id]: [ступени]} для таблицы «Цены и остатки по магазинам».
    priceTiersByShop() {
      const byShop = {};
      for (const tier of this.productPriceTiers ?? []) {
        if (tier?.shop_id == null) continue;
        if (!byShop[tier.shop_id]) byShop[tier.shop_id] = [];
        byShop[tier.shop_id].push(tier);
      }
      return byShop;
    },
    // Обычный режим: лесенка магазина товара. Магазин берём из роута, а если в роуте его
    // нет (плейсхолдер 0) или у него нет ступеней, первый магазин из ответа: у товара вне
    // сетевого каталога магазин один.
    ownShopPriceTiers() {
      const shopIds = Object.keys(this.priceTiersByShop);
      if (!shopIds.length) return [];
      return this.priceTiersByShop[this.shop_id] ?? this.priceTiersByShop[shopIds[0]];
    },
    // Таблица цен и остатков заменяет поля «Цена продажи» и «Остатки товара»
    // только у уже существующей карточки. При создании товара таблица пустая,
    // а цену и остаток задать надо — там остаются прежние поля (уйдут в якорь).
    shopPricesTableVisible() {
      return this.networkCatalogEnabled && !!this.id;
    },
    // Строки таблицы «Цены и остатки по магазинам»: объединение prices и remains
    // по shop_id. Название магазина берём из связи price.shop, а для магазина,
    // у которого есть только остаток, — из remain.shop.
    shopPricesRows() {
      const byShop = new Map();

      for (const price of this.productPrices ?? []) {
        if (price?.shop_id == null) continue;
        byShop.set(price.shop_id, {
          shopId: price.shop_id,
          shopTitle: price.shop?.title || `Магазин #${price.shop_id}`,
          price: price.value ?? null,
          priceOld: price.price_without_discount > 0 ? price.price_without_discount : null,
          quantity: null,
        });
      }

      for (const remain of this.productRemains ?? []) {
        if (remain?.shop_id == null) continue;
        const row = byShop.get(remain.shop_id) ?? {
          shopId: remain.shop_id,
          shopTitle: remain.shop?.title || `Магазин #${remain.shop_id}`,
          price: null,
          priceOld: null,
          quantity: null,
        };
        row.quantity = remain.quantity ?? null;
        byShop.set(remain.shop_id, row);
      }

      const search = (this.shopPricesSearch ?? "").trim().toLowerCase();

      return [...byShop.values()]
        .filter((row) => !search || row.shopTitle.toLowerCase().includes(search))
        .sort((a, b) => a.shopTitle.localeCompare(b.shopTitle, "ru"));
    },
    // Все shop_id, уже привязанные к товару (цена и/или остаток), вне зависимости
    // от shopPricesSearch — источник исключения для селекта «Добавить магазин».
    usedShopIds() {
      const ids = new Set();
      for (const price of this.productPrices ?? []) {
        if (price?.shop_id != null) ids.add(price.shop_id);
      }
      for (const remain of this.productRemains ?? []) {
        if (remain?.shop_id != null) ids.add(remain.shop_id);
      }
      return ids;
    },
    // Признак товара из 1С: заполненный код 1С (products.code). Поле уже
    // тянется запросом карточки и хранится в form.code.value — отдельная
    // GraphQL-выгрузка (external_id) не нужна: этого поля нет в схеме Product
    // (products.external_id — id RetailCRM, не проброшен в GraphQL) и оно не
    // отражает признак 1С так же точно, как code.
    isOneCProduct() {
      return Boolean(this.form.code.value);
    },
    shop_id() {
      return parseInt(this.$route.params.shop_id);
    },
    id() {
      return parseInt(this.$route.params.id);
    },
    // Ключ поля, которое выделяется красным (открыто из карантина, FOODLINK-11458).
    quarantineHighlight() {
      return this.$route.query.quarantine_highlight || null;
    },
    // Человекочитаемая причина карантина для баннера.
    quarantineReasonLabel() {
      const map = {
        barcode: "нет штрихкода",
        sku: "нет артикула",
        name: "нет наименования",
        price: "нет цены",
        stock: "нет остатка",
        image: "нет изображения",
        unit: "нет единицы измерения",
      };
      return map[this.quarantineHighlight] || "проблема с обязательным полем";
    },
    breadcrumbs() {
      return this.id
        ? [
            {
              to: {
                name: "products",
              },
              title: "Товары",
            },
            {
              title: this.form.title.value,
            },
          ]
        : [
            {
              to: {
                name: "products",
              },
              title: "Товары",
            },
            {
              title: "Новый товар",
            },
          ];
    },
  },
};
</script>

<style lang="stylus">
.product-page {
  // Баннер карантина (FOODLINK-11458): причина попадания товара в карантин.
  &__quarantine-banner {
    display flex
    align-items center
    gap 8px
    margin-bottom 16px
    padding 12px 16px
    border-radius 8px
    background var(--red-50)
    border 1px solid var(--red-400)
    color var(--red-400)
    font-weight 600

    svg {
      flex-shrink 0
      width 20px
      height 20px
      fill var(--red-400)
    }
  }

  // Таблица «Цены и остатки по магазинам» (сетевой каталог): цена/остаток
  // редактируются построчно и сохраняются в 1С через ProductShopPriceSave.
  // Плашка для товаров из 1С — стиль по образцу NewOneCBanner.vue.
  &__shop-prices-warning {
    background var(--yellow-light, #fff3cd)
    color var(--yellow-dark, #856404)
    padding 8px 16px
    border-radius 4px
    display flex
    align-items center
    gap 8px
    border 1px solid var(--yellow, #ffeeba)
    font-size 0.9375em
    margin-bottom 12px
  }

  &__shop-prices-warning-icon {
    font-size 16px
    flex-shrink 0
  }

  &__shop-prices-search {
    max-width 280px
    margin-bottom 12px
  }

  &__shop-prices {
    width 100%
    border-collapse collapse

    th, td {
      text-align left
      padding 8px 12px
      border-bottom 1px solid var(--white-2)
      font-size 0.9375em
    }

    .input {
      min-width 120px
    }
  }

  &__shop-prices-actions {
    display flex
    align-items center
    gap 8px
    white-space nowrap
  }

  &__shop-prices-delete {
    background none
    border none
    padding 4px
    cursor pointer
    display inline-flex
    align-items center
    justify-content center

    .icon {
      width 20px
      height 20px

      svg path {
        fill var(--dark-light)
      }
    }

    &:hover:not(:disabled) .icon svg path {
      fill var(--red-400)
    }

    &:disabled {
      cursor default
      opacity 0.5
    }
  }

  &__shop-prices-add {
    margin-top 12px
  }

  // Цены от количества (MID-308): раскрывающийся редактор лесенки под строкой магазина.
  &__shop-prices-tiers-toggle {
    display flex
    align-items center
    gap 2px
    margin-top 4px
    padding 0
    border none
    background none
    cursor pointer
    font-size 0.8125rem
    color var(--dark-light)

    .icon {
      width 16px
      height 16px

      svg path {
        fill var(--dark-light)
      }
    }

    &:hover,
    &--filled {
      color var(--green-700)

      .icon svg path {
        fill var(--green-700)
      }
    }
  }

  &__shop-prices-tiers-row > td {
    background var(--white-2)
  }

  &__shop-prices-tiers {
    display grid
    grid-gap 8px
    max-width 560px
  }

  &__shop-prices-tiers-title {
    font-family var(--font-bold)
    font-weight 500
    font-size 1rem
  }

  &__shop-prices-tiers-note {
    font-size 0.8125rem
    line-height 140%
    color var(--dark-light)
  }

  &__shop-prices-add-form-wide {
    flex 0 0 100%
  }

  // Цены от количества (MID-308), обычный режим: блок лесенки под «Стоимость».
  &__price-tiers {
    display grid
    grid-gap 12px
  }

  &__price-tiers-title {
    font-family var(--font-bold)
    font-weight 500
    font-size 1rem
  }

  &__shop-prices-add-form {
    display flex
    align-items flex-start
    gap 8px
    flex-wrap wrap

    .select,
    .input {
      min-width 200px
      width auto
      flex 1 1 200px
    }

    .btn {
      flex-shrink 0
      margin-top 1px
    }
  }

  // Поле, из-за которого товар попал в карантин — обводим красным.
  // box-shadow не сдвигает раскладку (рисуется поверх соседей).
  &__field--quarantine {
    border-radius 8px
    box-shadow 0 0 0 2px var(--red-400)
  }

  &__header {
    display flex
    justify-content space-between
    align-items center
    gap 16px
  }

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

  &__header-actions {
    display flex
    gap 16px
    width 100%
    justify-content flex-end
  }

  &__parent-info {
    display flex
    align-items center
    gap 12px
    padding 12px 16px
    background #E3F2FD
    border-radius 12px
    border 1px solid #BBDEFB
  }

  &__parent-info-label {
    font-weight 600
    font-size 14px
    color #1565C0
  }

  &__parent-info-link {
    color #1565C0
    text-decoration none
    font-size 14px
    &:hover {
      text-decoration underline
    }
  }

  &__info {
    display grid
    grid-template-columns repeat(2, 1fr)
    grid-gap 16px
    padding 16px
    border-radius 16px
    border 1px solid #F2F2F2
    background #FFFFFF
    width 100%
  }

  &__status {
    display flex
    justify-content center
    align-items center
    flex-direction column
    background #F7FDFA
    border-radius 16px
    gap 4px
    padding 32px 0
  }

  &__status-title {
    font-weight 400
    font-size 13px
    line-height 18px
    color #373737
  }

  &__status-action {
    display flex
    padding 12px 0
    justify-content center
    align-items center
  }

  &__finish-form {
    background #FDFBF7
    border-radius 16px
    padding 32px 0
    display flex
    align-items center
    flex-direction column
    justify-content center
    gap 4px
  }

  &__finish-form-title {
    font-weight 400
    font-size 13px
    line-height 18px
    color #373737
  }

  &__finish-form-percent {
    font-weight 600
    font-size 24px
    line-height 33px
    color #FFB800
  }


  &__main {
    width 100%
  }

  &__form {
    display grid
    grid-template-columns repeat(2, 1fr)
    grid-gap 16px
  }

  &__form-part {
    height fit-content
    display flex
    flex-direction column
    gap 32px
    padding 16px
    background white
    border: 1px solid #F2F2F2;
    border-radius: 16px;
  }

  &__form-block {
    display flex
    flex-direction column
    gap 16px
  }

  &__form-header {
    font-family var(--font-bold)
    padding: 4px 0 21px 0
    font-weight: 500;
    font-size: 1.125em;
    color #222128
    border-bottom 1px solid #F2F2F2
  }

  &__form-product {
    display grid
    grid-template-columns 1fr
    grid-gap 16px

    &-update {
      grid-template-columns 97px 1fr
    }
  }

  &__form-category {
    display grid
    grid-template-columns 1fr
    grid-gap 16px

    &-update {
      grid-template-columns 150px 1fr
    }
  }

  &__form-weight-info {
    display grid
    grid-template-columns 1fr 1fr
    grid-gap 16px
    align-items start
  }

  &__form-photo-list {
    display flex
    gap 16px
    overflow-x auto

    .file-upload {
      width: 169px;
      height 169px
    }
  }

  &__form-images-list {
    & .array__list {
      display flex
      min-width 788px
      overflow hidden
      overflow-x auto
    }

    & .array__item-actions {
      display none
    }
  }

  &__form-price, &__form-description {
    display grid
    grid-template-columns repeat(1, 1fr)
    grid-gap 16px

    > span {
      font-family var(--font-bold)
      font-weight: 500;
      font-size: 1rem;
    }
  }

  &__form-composition {
    display grid
    grid-template-columns repeat(2, 1fr)
    grid-gap 16px
  }

  &__form-params {
    width 100%
  }

  &__form-params-row-1 {
    display grid
    grid-template-columns 1fr 1fr
    grid-gap 16px
  }

  &__form-params-row-2 {
    display grid
    grid-template-columns 1fr 1fr
    grid-gap 16px
  }

  &__form-maker {
    display grid
    grid-template-columns 1fr 0.5fr 0.5fr
    grid-gap 16px
  }

  &__supplements {
    //
    //&--errors {
    //  border-radius 7px
    //  border 1px solid var(--red)
    //}

    & > .array__list > .array__item > .array__item-actions {
      justify-content flex-end
      padding-bottom 16px
      border-bottom 1px solid var(--border-color)

      button {
        width: 120px;
        height: 30px;
      }
    }

    .array__list > .array__item {
      display flex
      flex-direction column
    }

    & .array .array__item {
      display flex
      flex-direction row
      align-items: flex-start;

      & .product-page__form-supplements-value {
        width: 100%;
        display grid
        grid-template-columns 1fr 1fr
        grid-gap 16px
      }
    }
  }

  &__form-supplements {
    display grid
    grid-template-columns 1fr 1fr
    gap 16px

    .array__item {
      display grid
      //grid-template-columns 1fr 48px
      gap 16px
    }
  }

  &__supplement-value-type {
    grid-column 1 / -1
    display flex
    gap 16px
    align-items center
  }

  &__supplement-value-type-label {
    display flex
    align-items center
    gap 6px
    cursor pointer
    font-size 14px
  }

  &__supplement-catalog {
    grid-column 1 / -1
  }

  &__supplement-catalog-search {
    position relative
  }

  &__supplement-catalog-results {
    position absolute
    top 100%
    left 0
    right 0
    background white
    border 1px solid var(--border-color)
    border-top none
    border-radius 0 0 4px 4px
    max-height 200px
    overflow-y auto
    z-index 10
  }

  &__supplement-catalog-result-item {
    padding 8px 12px
    cursor pointer
    font-size 14px

    &:hover {
      background var(--background-color)
    }
  }

  &__supplement-catalog-selected {
    margin-top 8px
    font-size 14px
    display flex
    align-items center
    gap 8px
  }

  &__supplement-catalog-clear {
    background none
    border none
    cursor pointer
    color var(--red)
    font-size 14px
    padding 2px 6px
  }
}

</style>
