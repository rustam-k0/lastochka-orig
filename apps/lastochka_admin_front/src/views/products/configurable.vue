<template>
  <main class="page configurable-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="configurable-page__header">
      <button @click="$router.go(-1)" class="btn btn--sm btn--white">
        <IconComponent name="arrow-left-line" />
      </button>
      <div class="configurable-page__header-title">
        <span v-if="shop?.title" class="configurable-page__shop-label">Магазин: {{ shop.title }}</span>
        <span>{{ id ? form.title.value : "Новый вариативный товар" }}</span>
        <span v-if="id" class="configurable-page__badge">Вариативный</span>
      </div>
      <div class="configurable-page__header-actions">
        <button v-if="!loading" class="btn btn--sm" @click="submit">
          <IconComponent name="checkbox-circle-fill" />
          <span>{{ id ? 'Сохранить изменения' : 'Создать' }}</span>
        </button>
      </div>
    </div>

    <div v-if="loading" class="configurable-page__loading">Загрузка...</div>

    <div v-else class="configurable-page__main">
      <form @submit.prevent="submit" class="configurable-page__form">
        <!-- Left column -->
        <div class="configurable-page__form-part">
          <div class="configurable-page__form-block">
            <div class="configurable-page__form-header">Товар</div>
            <InputComponent
              title="Название"
              placeholder="Название"
              required
              v-model="form.title.value"
              :errors="form.title.errors"
            />
            <CategoryTreeSelect
              title="Категория"
              placeholder="Категория"
              v-model="form.category.value"
              :errors="form.category.errors"
              :options="categoriesTree"
              leaf-only
              clearable
              required
            />
            <div v-if="moduleStore.isModuleEnabled('brands')" class="configurable-page__form-field">
              <SelectComponent
                title="Бренд"
                placeholder="Выберите бренд"
                v-model="form.brand_id.value"
                :options="brands"
                clearable
              />
            </div>
          </div>

          <div class="configurable-page__form-block">
            <div class="configurable-page__form-header">Главная фотография</div>
            <div class="configurable-page__form-photo">
              <ImageUploadComponent text="Загрузить фото" v-model="form.img.value" />
            </div>
          </div>

          <div class="configurable-page__form-block">
            <div class="configurable-page__form-header">Фотографии</div>
            <ArrayComponent
              ref="images"
              class="configurable-page__form-images-list"
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

          <div v-if="moduleStore.isModuleEnabled('product_video')" class="configurable-page__form-block">
            <div class="configurable-page__form-header">Видео</div>
            <FileUploadComponent
              title="Видео товара"
              text="Загрузить видео (MP4, WebM)"
              path="products/video"
              v-model="form.video.value"
            />
          </div>

          <div class="configurable-page__form-block">
            <div class="configurable-page__form-header">Описание</div>
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
            />
            <TextareaComponent
              title="Мета описание"
              placeholder="Мета описание"
              v-model="form.meta_description.value"
            />
            <TextareaComponent
              title="Ключевые слова"
              placeholder="Ключевые слова"
              v-model="form.meta_keywords.value"
            />
          </div>
        </div>

        <!-- Right column -->
        <div class="configurable-page__form-part">
          <!-- Aggregated data (only edit mode) -->
          <div v-if="id" class="configurable-page__form-block">
            <div class="configurable-page__form-header">Сводные данные</div>
            <div class="configurable-page__aggregated">
              <div class="configurable-page__aggregated-row">
                <span class="configurable-page__aggregated-label">Цена:</span>
                <span class="configurable-page__aggregated-value">
                  {{ computedPriceMin !== null ? `${computedPriceMin} - ${computedPriceMax} ₽` : '—' }}
                </span>
              </div>
              <div class="configurable-page__aggregated-row">
                <span class="configurable-page__aggregated-label">Общий остаток:</span>
                <span class="configurable-page__aggregated-value">{{ computedQuantity ?? '—' }}</span>
              </div>
              <div class="configurable-page__aggregated-row">
                <span class="configurable-page__aggregated-label">Вариантов:</span>
                <span class="configurable-page__aggregated-value">{{ children.length }}</span>
              </div>
            </div>
          </div>

          <!-- Configurable attributes -->
          <div v-if="configurableAttributes.length" class="configurable-page__form-block">
            <div class="configurable-page__form-header">Варьируемые атрибуты</div>
            <div class="configurable-page__config-attrs">
              <div
                v-for="ca in configurableAttributes"
                :key="ca.id"
                class="configurable-page__config-attr-item"
              >
                <span class="configurable-page__config-attr-name">{{ ca.attribute?.title || '—' }}</span>
                <span class="configurable-page__config-attr-values">
                  {{ (ca.attribute?.values || []).map(v => v.title).join(', ') || 'нет значений' }}
                </span>
              </div>
            </div>
          </div>

          <!-- Variants table -->
          <div class="configurable-page__form-block">
            <div class="configurable-page__form-header">
              <span>Варианты</span>
            </div>
            <div class="configurable-page__variants-actions">
              <button
                class="btn btn--sm"
                type="button"
                @click="showAddExistingSearch = true"
              >
                Добавить существующий
              </button>
              <button
                v-if="configurableAttributes.length"
                class="btn btn--sm btn--outline"
                type="button"
                @click="showGenerateDialog = true"
              >
                Генерировать варианты
              </button>
              <button
                v-if="id && configurableAttributes.length"
                class="btn btn--sm btn--outline"
                type="button"
                @click="showAddVariantDialog = true"
              >
                Добавить вариант вручную
              </button>
              <button
                v-if="id && configurableAttributes.length"
                class="btn btn--sm btn--outline"
                type="button"
                @click="showSyncVariantsDialog = true"
              >
                Добавить значение атрибута
              </button>
              <button
                v-if="id && configurableAttributes.length && children.length"
                class="btn btn--sm btn--outline"
                type="button"
                @click="showRemoveAttrValueDialog = true"
              >
                Удалить значение атрибута
              </button>
            </div>

            <!-- Search modal for adding existing product -->
            <div v-if="showAddExistingSearch" class="configurable-page__search-overlay">
              <div class="configurable-page__search-box">
                <InputComponent
                  title="Поиск товара"
                  placeholder="Введите название..."
                  v-model="addSearchQuery"
                  @update:model-value="searchProducts"
                />
                <div class="configurable-page__search-results">
                  <div
                    v-for="item in searchResults"
                    :key="item.id"
                    class="configurable-page__search-item"
                    @click="addChildProduct(item)"
                  >
                    {{ item.title }}
                  </div>
                  <div v-if="!searchResults.length && addSearchQuery" class="configurable-page__search-empty">
                    Ничего не найдено
                  </div>
                </div>
                <button
                  class="btn btn--sm btn--outline"
                  type="button"
                  @click="showAddExistingSearch = false"
                >
                  Закрыть
                </button>
              </div>
            </div>

            <div v-if="!children.length" class="configurable-page__empty-variants">
              Варианты не добавлены
            </div>
            <div v-else class="configurable-page__variants-table-wrapper">
              <table class="configurable-page__variants-table">
                <thead>
                  <tr>
                    <th style="width: 50px">Фото</th>
                    <th>Артикул</th>
                    <th>Название</th>
                    <th v-for="ca in configurableAttributes" :key="ca.id">{{ ca.attribute?.title }}</th>
                    <th>Цена</th>
                    <th>Остаток</th>
                    <th style="width: 60px">Статус</th>
                    <th style="width: 50px"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="child in children"
                    :key="child.id"
                    class="configurable-page__variant-row"
                    @click="navigateToChild(child)"
                  >
                    <td>
                      <img
                        v-if="child.img?.path"
                        :src="mediaEndpoint + child.img.path"
                        class="configurable-page__variant-img"
                      />
                      <div v-else class="configurable-page__variant-img-placeholder">—</div>
                    </td>
                    <td>{{ child.articul || '—' }}</td>
                    <td>{{ child.title }}</td>
                    <td v-for="ca in configurableAttributes" :key="ca.id">
                      {{ getChildAttrValue(child, ca.attribute?.id) }}
                    </td>
                    <td>{{ child.default_price?.value || '—' }}</td>
                    <td>{{ getChildQuantity(child) }}</td>
                    <td>
                      <span
                        class="configurable-page__status-dot"
                        :class="{
                          'configurable-page__status-dot--active': child.status_id === 1,
                          'configurable-page__status-dot--inactive': child.status_id === 3,
                        }"
                      ></span>
                    </td>
                    <td>
                      <div class="configurable-page__row-actions">
                        <button
                          class="btn btn--sm btn--outline"
                          type="button"
                          @click.stop="openEdit(child)"
                          title="Редактировать"
                        >
                          ✎
                        </button>
                        <button
                          class="btn btn--sm btn--outline"
                          type="button"
                          @click.stop="openDelete(child)"
                          title="Удалить"
                        >
                          🗑
                        </button>
                        <button
                          class="btn btn--sm btn--outline configurable-page__detach-btn"
                          type="button"
                          @click.stop="removeChild(child)"
                          title="Открепить"
                        >
                          &times;
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Detach (disassemble) button -->
          <div v-if="id" class="configurable-page__form-block">
            <button
              class="btn btn--md configurable-page__detach-all-btn"
              type="button"
              @click="detachAll"
            >
              Расформировать
            </button>
          </div>
        </div>
      </form>
    </div>

    <GenerateVariantsDialog
      v-if="showGenerateDialog && id"
      :visible="showGenerateDialog"
      :parent-id="id"
      :parent-articul="parentArticul"
      :configurable-attributes="configurableAttributes"
      :existing-children="children"
      @close="showGenerateDialog = false"
      @generated="onVariantsGenerated"
    />

    <div v-if="showAddVariantDialog && id" class="configurable-page__modal-backdrop">
      <AddVariantDialog
        :parent-id="id"
        :parent-articul="parentArticul"
        :configurable-attributes="configurableAttributes"
        @close="showAddVariantDialog = false"
        @success="onVariantsGenerated(); showAddVariantDialog = false"
      />
    </div>

    <div v-if="showSyncVariantsDialog && id" class="configurable-page__modal-backdrop">
      <SyncVariantsDialog
        :parent-id="id"
        :parent-articul="parentArticul"
        :configurable-attributes="configurableAttributes"
        :existing-children="children"
        @close="showSyncVariantsDialog = false"
        @success="onVariantsGenerated(); showSyncVariantsDialog = false"
      />
    </div>

    <div v-if="showRemoveAttrValueDialog && id" class="configurable-page__modal-backdrop">
      <RemoveAttributeValueDialog
        :parent-id="id"
        :configurable-attributes="configurableAttributes"
        :existing-children="children"
        @close="showRemoveAttrValueDialog = false"
        @success="onVariantsGenerated(); showRemoveAttrValueDialog = false"
      />
    </div>

    <div v-if="editingVariant" class="configurable-page__modal-backdrop">
      <EditVariantDialog
        :variant="editingVariant"
        :configurable-attributes="configurableAttributes"
        @close="editingVariant = null"
        @success="onVariantsGenerated(); editingVariant = null"
      />
    </div>

    <div v-if="deletingVariant" class="configurable-page__modal-backdrop">
      <DeleteVariantDialog
        :variant="deletingVariant"
        @close="deletingVariant = null"
        @success="onVariantsGenerated(); deletingVariant = null"
      />
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
import ArrayComponent from "@/components/inputs/ArrayComponent.vue";
import ImageUploadComponent from "@/components/ImageUpload.vue";
import FileUploadComponent from "@/components/inputs/FileUploadComponent.vue";
import GenerateVariantsDialog from "@/views/products/components/GenerateVariantsDialog.vue";
import AddVariantDialog from "@/views/products/components/AddVariantDialog.vue";
import SyncVariantsDialog from "@/views/products/components/SyncVariantsDialog.vue";
import RemoveAttributeValueDialog from "@/views/products/components/RemoveAttributeValueDialog.vue";
import EditVariantDialog from "@/views/products/components/EditVariantDialog.vue";
import DeleteVariantDialog from "@/views/products/components/DeleteVariantDialog.vue";
import CONFIGURABLE_PRODUCT_ITEM from "@/graphql/queries/admin_configurable_product_item.graphql";
import CONFIGURABLE_PRODUCT_CREATE from "@/graphql/mutations/ConfigurableProductCreate.graphql";
import CONFIGURABLE_PRODUCT_UPDATE from "@/graphql/mutations/ConfigurableProductUpdate.graphql";
import CONFIGURABLE_PRODUCT_DETACH from "@/graphql/mutations/ConfigurableProductDetach.graphql";
import PRODUCTS_SEARCH from "@/graphql/queries/admin_products_search.graphql";
import PRODUCT_BRIEF from "@/graphql/queries/admin_product_brief.graphql";
import OPTIONS from "@/graphql/pages/Product/options.graphql";
import GLOBAL_CATEGORIES from "@/graphql/pages/Product/global_categories.graphql";
import { NO_SHOP_ROUTE_PARAM } from "@/constants/productRoutes.js";
import BRANDS from "@/graphql/queries/admin_brands.graphql";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";

export default {
  name: "ConfigurableProductPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    InputComponent,
    SelectComponent,
    CategoryTreeSelect,
    TextareaComponent,
    ArrayComponent,
    ImageUploadComponent,
    FileUploadComponent,
    GenerateVariantsDialog,
    AddVariantDialog,
    SyncVariantsDialog,
    RemoveAttributeValueDialog,
    EditVariantDialog,
    DeleteVariantDialog,
  },
  data() {
    return {
      loading: false,
      categoriesTree: [],
      shop: null,
      brands: [],
      parentArticul: "",
      children: [],
      configurableAttributes: [],
      computedPriceMin: null,
      computedPriceMax: null,
      computedQuantity: null,
      showGenerateDialog: false,
      showAddVariantDialog: false,
      showSyncVariantsDialog: false,
      showRemoveAttrValueDialog: false,
      editingVariant: null,
      deletingVariant: null,
      showAddExistingSearch: false,
      addSearchQuery: "",
      searchResults: [],
      searchDebounce: null,
      childrenToRemove: [],
      childrenToAdd: [],
      pendingChildrenIds: [],
      imagesTemplate: {
        value: null,
      },
      form: {
        title: { value: null, errors: [] },
        description: { value: null, errors: [] },
        category: { value: null, errors: [] },
        brand_id: { value: null, errors: [] },
        img: { value: null, errors: [] },
        images: { value: [], errors: [] },
        video: { value: null, errors: [] },
        meta_title: { value: null, errors: [] },
        meta_description: { value: null, errors: [] },
        meta_keywords: { value: null, errors: [] },
      },
    };
  },
  computed: {
    moduleStore() {
      return useModuleConfigStore();
    },
    shopId() {
      return parseInt(this.$route.params.shop_id);
    },
    id() {
      return this.$route.params.id ? parseInt(this.$route.params.id) : null;
    },
    mediaEndpoint() {
      return import.meta.env.VITE_MEDIA_ENDPOINT || "";
    },
    breadcrumbs() {
      return [
        { to: { name: "products" }, title: "Товары" },
        { title: this.id ? (this.form.title.value || "Вариативный товар") : "Новый вариативный товар" },
      ];
    },
  },
  mounted() {
    this.getOptions();
    if (this.id) {
      this.get();
    } else {
      // Режим создания — загрузить children из query params
      const childrenParam = this.$route.query.children;
      if (childrenParam) {
        this.pendingChildrenIds = childrenParam.split(",").map((id) => parseInt(id));
        this.loadPendingChildren();
      }
      this.$refs.images?.add();
    }
  },
  methods: {
    uploadImage(i) {
      if (this.form.images.value.length - 1 === i) {
        // Колбэк cropper-модалки переживает уход со страницы: refs могут быть
        // null (тот же класс бага, что GlitchTip ADMIN_FRONT-H/L в open.vue).
        this.$refs.images?.add();
      }
    },
    removeImage(i) {
      if (this.form.images.value.length > 1) {
        if (confirm("Вы уверены?")) {
          this.form.images.value.splice(i, 1);
        }
      }
    },
    getOptions() {
      // Без выбранного магазина (сетевой каталог) options.graphql непригоден:
      // shop(id:) без аргумента — это @find без фильтра, и при более чем одном
      // магазине Lighthouse отдаёт «Query returned more than one result», а
      // shop_categories(shop_id: null) возвращает null. Берём глобальное дерево.
      if (!this.shopId) {
        this.apollo()
          .admin.query({ query: GLOBAL_CATEGORIES })
          .then(({ data }) => {
            this.shop = null;
            this.categoriesTree = data.parent_categories ?? [];
          });
      } else {
        this.apollo()
          .admin.query({
            query: OPTIONS,
            variables: { shop_id: this.shopId },
          })
          .then(({ data }) => {
            this.shop = data.shop ?? null;
            this.categoriesTree = data.shop_categories ?? [];
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
    },
    async get() {
      if (!this.id) return;
      this.loading = true;
      try {
        const { data } = await this.apollo().admin.query({
          query: CONFIGURABLE_PRODUCT_ITEM,
          variables: { id: this.id },
          fetchPolicy: "network-only",
        });
        const item = data.admin_configurable_product_item;
        if (!item) {
          this.loading = false;
          return;
        }

        this.form.title.value = item.title;
        this.form.description.value = item.description;
        this.form.meta_title.value = item.meta_title;
        this.form.meta_description.value = item.meta_description;
        this.form.meta_keywords.value = item.meta_keywords;
        this.form.category.value = item.categories?.[0] || null;
        this.form.img.value = item.img || null;
        this.form.images.value = (item.images || []).map((img) => ({ value: img }));
        this.form.brand_id.value = item.brand || null;
        this.form.video.value = item.video_path
          ? { path: item.video_path, origin_name: "video" }
          : null;

        this.parentArticul = item.articul || "";
        this.configurableAttributes = item.configurable_attributes || [];
        this.children = item.children || [];
        this.computedPriceMin = item.computed_price_min;
        this.computedPriceMax = item.computed_price_max;
        this.computedQuantity = item.computed_quantity;
      } catch (err) {
        console.error("Error loading configurable product:", err);
      } finally {
        this.loading = false;
        this.$nextTick(() => {
          this.$refs.images?.add();
        });
      }
    },
    getChildAttrValue(child, attrId) {
      if (!child.attributes || !attrId) return "—";
      const attr = child.attributes.find(
        (a) => parseInt(a.attribute_id) === parseInt(attrId)
      );
      if (!attr) return "—";
      // Try to find value title from configurable attributes
      for (const ca of this.configurableAttributes) {
        if (parseInt(ca.attribute?.id) === parseInt(attrId)) {
          const val = (ca.attribute?.values || []).find(
            (v) => parseInt(v.id) === parseInt(attr.attribute_value_id)
          );
          if (val) return val.title;
        }
      }
      return attr.string_value || "—";
    },
    getChildQuantity(child) {
      if (!child.remains || !child.remains.length) return "—";
      return child.remains.reduce((sum, r) => sum + (r.quantity || 0), 0);
    },
    navigateToChild(child) {
      this.$router.push({
        name: "product",
        // shop_id — позиционный сегмент роута, без плейсхолдера ссылка без
        // магазина разберётся обратно неверно (constants/productRoutes.js).
        params: { shop_id: this.shopId || NO_SHOP_ROUTE_PARAM, id: child.id },
      });
    },
    searchProducts() {
      if (this.searchDebounce) clearTimeout(this.searchDebounce);
      this.searchDebounce = setTimeout(() => {
        if (!this.addSearchQuery || this.addSearchQuery.length < 2) {
          this.searchResults = [];
          return;
        }
        this.apollo()
          .admin.query({
            query: PRODUCTS_SEARCH,
            variables: { title: this.addSearchQuery, first: 10 },
            fetchPolicy: "network-only",
          })
          .then(({ data }) => {
            const childIds = this.children.map((c) => parseInt(c.id));
            // Вариативные товары и чужие варианты вариантом стать не могут —
            // бэкенд ответит 422, не предлагаем их в поиске.
            this.searchResults = (data.admin_products_paginate?.data || []).filter(
              (p) =>
                !childIds.includes(parseInt(p.id)) &&
                parseInt(p.id) !== this.id &&
                !p.is_configurable &&
                !p.parent_id
            );
          })
          .catch(() => {
            this.searchResults = [];
          });
      }, 300);
    },
    addChildProduct(product) {
      this.childrenToAdd.push(parseInt(product.id));
      this.children.push(product);
      this.showAddExistingSearch = false;
      this.addSearchQuery = "";
      this.searchResults = [];
    },
    removeChild(child) {
      if (!confirm(`Открепить "${child.title}" от вариативного товара?`)) return;
      this.childrenToRemove.push(parseInt(child.id));
      this.children = this.children.filter((c) => c.id !== child.id);
    },
    openEdit(child) {
      this.editingVariant = child;
    },
    openDelete(child) {
      this.deletingVariant = child;
    },
    async loadPendingChildren() {
      if (!this.pendingChildrenIds.length) return;
      try {
        // Тянем каждый товар по id: страница пагинации не гарантирует, что
        // выбранные товары попадут в выдачу (терялись все не с первой страницы).
        const results = await Promise.all(
          this.pendingChildrenIds.map((id) =>
            this.apollo()
              .admin.query({
                query: PRODUCT_BRIEF,
                variables: { id },
                fetchPolicy: "network-only",
              })
              .then(({ data }) => data.admin_products_item)
              .catch(() => null)
          )
        );
        this.children = results.filter(Boolean);
        // Подставить общую часть названий
        if (this.children.length && !this.form.title.value) {
          const titles = this.children.map((c) => c.title);
          this.form.title.value = this.findCommonPrefix(titles) || titles[0];
        }
        // Подставить категорию первого child
        if (this.children.length && !this.form.category.value) {
          this.form.category.value = this.children[0].categories?.[0] || null;
        }
      } catch (err) {
        console.error("Error loading pending children:", err);
      }
    },
    findCommonPrefix(strings) {
      if (!strings.length) return "";
      const first = strings[0];
      let prefix = "";
      for (let i = 0; i < first.length; i++) {
        if (strings.every((s) => s[i] === first[i])) {
          prefix += first[i];
        } else break;
      }
      return prefix.trim();
    },
    submit() {
      // Режим создания — ConfigurableProductCreate. Гейт строго по отсутствию
      // id: раньше он зависел от pendingChildrenIds (заполняется только из
      // URL ?children= merge-флоу), и вход через «Создать вариативный» уводил
      // сабмит в ConfigurableProductUpdate с id=null (ELEKTRO-KHAB-11773).
      if (!this.id) {
        return this.createConfigurable();
      }

      const variables = {
        id: this.id,
        title: this.form.title.value || undefined,
        description: this.form.description.value || undefined,
        category_id: this.form.category.value?.id
          ? parseInt(this.form.category.value.id)
          : undefined,
        meta_title: this.form.meta_title.value || undefined,
        meta_description: this.form.meta_description.value || undefined,
        meta_keywords: this.form.meta_keywords.value || undefined,
        brand_id: this.form.brand_id.value?.id
          ? parseInt(this.form.brand_id.value.id)
          : null,
        video_path: this.form.video.value?.path || null,
        img: this.form.img.value || undefined,
        images:
          !this.form.images.value.length ||
          this.form.images.value[0].value === null
            ? undefined
            : this.form.images.value
                .filter((item) => item.value)
                .map((item) => item.value),
      };

      if (this.childrenToAdd.length) {
        variables.children_add = this.childrenToAdd;
      }
      if (this.childrenToRemove.length) {
        variables.children_remove = this.childrenToRemove;
      }

      this.apollo()
        .admin.mutate({
          mutation: CONFIGURABLE_PRODUCT_UPDATE,
          variables,
        })
        .then(() => {
          this.childrenToAdd = [];
          this.childrenToRemove = [];
          alert("Изменения сохранены");
          this.get();
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) {
            graphQLErrors.forEach((err) => {
              if (err.extensions?.category === "validation") {
                Object.entries(err.extensions.validation).forEach(([key, value]) => {
                  if (this.form[key]) {
                    this.form[key].errors = value;
                  } else {
                    alert(value.join("\n"));
                  }
                });
              } else {
                alert(err.message);
              }
            });
          }
        });
    },
    createConfigurable() {
      if (!this.form.title.value) {
        this.form.title.errors = ["Укажите название"];
        return;
      }
      // Варианты берём из таблицы: туда попадают и товары из merge-флоу
      // (?children=), и добавленные через поиск на этом экране.
      const childrenIds = [...new Set(this.children.map((c) => parseInt(c.id)))];
      if (!childrenIds.length) {
        alert("Добавьте хотя бы один товар в варианты");
        return;
      }
      const variables = {
        title: this.form.title.value,
        description: this.form.description.value || undefined,
        // В сетевом каталоге магазин не выбирается — аргумент опускаем,
        // бэкенд подставит якорный магазин сети.
        shop_id: this.shopId || undefined,
        category_id: this.form.category.value?.id
          ? parseInt(this.form.category.value.id)
          : undefined,
        configurable_attribute_ids: [],
        children_ids: childrenIds,
        img: this.form.img.value || undefined,
        images:
          !this.form.images.value.length || this.form.images.value[0].value === null
            ? undefined
            : this.form.images.value.filter((item) => item.value).map((item) => item.value),
      };

      this.apollo()
        .admin.mutate({
          mutation: CONFIGURABLE_PRODUCT_CREATE,
          variables,
        })
        .then(({ data }) => {
          // Бэкенд отдаёт бизнес-ошибки payload'ом {code, message}, а не
          // GraphQL-ошибкой — без проверки кода фронт рапортовал бы об успехе.
          const result = data?.ConfigurableProductCreate;
          if (result && parseInt(result.code) !== 200) {
            alert(result.message || "Не удалось создать вариативный товар");
            return;
          }
          alert("Вариативный товар создан");
          this.$router.push({ name: "products" });
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) {
            graphQLErrors.forEach((err) => {
              alert(err.message);
            });
          }
        });
    },
    detachAll() {
      if (!confirm("Расформировать вариативный товар? Все варианты станут обычными товарами.")) return;
      this.apollo()
        .admin.mutate({
          mutation: CONFIGURABLE_PRODUCT_DETACH,
          variables: { id: this.id },
        })
        .then(() => {
          alert("Вариативный товар расформирован");
          this.$router.push({ name: "products" });
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) {
            alert(graphQLErrors.map((e) => e.message).join("\n"));
          }
        });
    },
    onVariantsGenerated() {
      this.showGenerateDialog = false;
      this.get();
    },
  },
};
</script>

<style lang="stylus">
.configurable-page
  &__header
    display flex
    justify-content space-between
    align-items center
    gap 16px

  &__header-title
    width 100%
    font-size 24px
    line-height 33px
    font-weight 500
    color #222128
    display flex
    align-items center
    gap 8px

  &__header-actions
    display flex
    gap 16px
    justify-content flex-end
    white-space nowrap

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

  &__loading
    text-align center
    padding 48px
    color #999
    font-size 16px

  &__main
    width 100%

  &__form
    display grid
    grid-template-columns repeat(2, 1fr)
    grid-gap 16px

  &__form-part
    height fit-content
    display flex
    flex-direction column
    gap 32px
    padding 16px
    background white
    border 1px solid #F2F2F2
    border-radius 16px

  &__form-block
    display flex
    flex-direction column
    gap 16px

  &__form-header
    font-family var(--font-bold)
    padding 4px 0 21px 0
    font-weight 500
    font-size 1.125em
    color #222128
    border-bottom 1px solid #F2F2F2
    display flex
    justify-content space-between
    align-items center

  &__form-field
    display grid
    grid-template-columns 1fr
    grid-gap 16px

  &__form-photo
    display flex
    gap 16px

    .file-upload
      width 169px
      height 169px

  &__form-images-list
    & .array__list
      display flex
      min-width 788px
      overflow hidden
      overflow-x auto

    & .array__item-actions
      display none

  &__aggregated
    display flex
    flex-direction column
    gap 8px
    padding 12px
    background #f8f8f8
    border-radius 8px

  &__aggregated-row
    display flex
    justify-content space-between
    align-items center

  &__aggregated-label
    color #666
    font-size 14px

  &__aggregated-value
    font-weight 600
    font-size 14px
    color #222128

  &__config-attrs
    display flex
    flex-direction column
    gap 8px

  &__config-attr-item
    padding 8px 12px
    background #f8f8f8
    border-radius 8px
    display flex
    flex-direction column
    gap 4px

  &__config-attr-name
    font-weight 600
    font-size 13px
    color #222128

  &__config-attr-values
    font-size 12px
    color #666

  &__variants-actions
    display flex
    gap 8px

  &__search-overlay
    background #f8f8f8
    border 1px solid #eee
    border-radius 8px
    padding 16px
    display flex
    flex-direction column
    gap 12px

  &__search-box
    display flex
    flex-direction column
    gap 8px

  &__search-results
    max-height 200px
    overflow-y auto

  &__search-item
    padding 8px 12px
    cursor pointer
    border-radius 4px
    transition background 0.15s

    &:hover
      background #eee

  &__search-empty
    text-align center
    padding 16px
    color #999

  &__empty-variants
    text-align center
    padding 32px
    color #999
    background #fafafa
    border-radius 8px
    border 1px dashed #ddd

  &__variants-table-wrapper
    overflow-x auto
    background #fafafa
    border-radius 8px
    border 1px solid #eee

  &__variants-table
    width 100%
    border-collapse collapse

    th, td
      padding 8px 12px
      text-align left
      border-bottom 1px solid #eee
      font-size 13px

    th
      font-weight 600
      color #666
      background #f5f5f5

  &__variant-row
    cursor pointer
    transition background 0.15s

    &:hover td
      background #f0f0f0

  &__variant-img
    width 36px
    height 36px
    object-fit cover
    border-radius 4px

  &__variant-img-placeholder
    width 36px
    height 36px
    background #eee
    border-radius 4px
    display flex
    align-items center
    justify-content center
    color #ccc

  &__status-dot
    width 10px
    height 10px
    border-radius 50%
    display inline-block

    &--active
      background #5AB033

    &--inactive
      background #ccc

  &__detach-btn
    width 28px
    height 28px
    padding 0
    display flex
    align-items center
    justify-content center
    font-size 16px
    color #999

    &:hover
      color #e55

  &__detach-all-btn
    width 100%
    background #fff5f5
    color #e55
    border 1px solid #fcc

    &:hover
      background #fee
      border-color #e55

  &__row-actions
    display flex
    gap 4px

  &__modal-backdrop
    position fixed
    inset 0
    background rgba(0, 0, 0, 0.4)
    display flex
    align-items center
    justify-content center
    z-index 999
</style>
