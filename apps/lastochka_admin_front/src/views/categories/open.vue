<template>
  <div class="page category-page">
    <h2>{{ id ? "Редактирование категории" : "Новая категория" }}</h2>
    <!--    <ModalHeader title="Новая категория" />-->
    <div class="default-modal__body category-page__body">
      <InputComponent
        v-model="form.title.value"
        :errors="form.title.errors"
        title="Название категории"
        placeholder="Название категории"
        required
      />
      <CheckboxComponent
        v-model="form.is_active.value"
        green
        class="category-page__important"
      >Активна (выводится на витрине)</CheckboxComponent>

      <CheckboxComponent
        v-if="!isChild && checkboxStore.isEnabled('Category', 'important')"
        v-model="form.important.value"
        green
        class="category-page__important"
      >{{ checkboxStore.getLabel('Category', 'important') }}</CheckboxComponent>

      <CheckboxComponent
        v-if="checkboxStore.isEnabled('Category', 'is_cooking')"
        v-model="form.is_cooking.value"
        green
        class="category-page__important"
      >{{ checkboxStore.getLabel('Category', 'is_cooking') }}</CheckboxComponent>

      <CheckboxComponent
        v-if="checkboxStore.isEnabled('Category', 'is_perishable')"
        v-model="form.is_perishable.value"
        green
        class="category-page__important"
      >{{ checkboxStore.getLabel('Category', 'is_perishable') }}</CheckboxComponent>

      <CheckboxComponent
        v-if="checkboxStore.isEnabled('Category', 'show_on_main')"
        v-model="form.show_on_main.value"
        green
        class="category-page__important"
      >{{ checkboxStore.getLabel('Category', 'show_on_main') }}</CheckboxComponent>

      <CustomCheckboxesField
        entity="Category"
        v-model="form.custom_checkboxes.value"
        class="category-page__important"
      />
      <div class="category-page__tag">
        <InputComponent
          v-model="form.tag_text.value"
          :errors="form.tag_text.errors"
          title="Текст тега"
          placeholder="Например: Готовим свежие каждый день"
          :messages="['Тег отображается только у конечных категорий, к которым привязаны товары']"
        />
        <InputComponent
          v-model="form.tag_color.value"
          :errors="form.tag_color.errors"
          title="Цвет тега"
          type="color"
          class="category-page__tag-color"
        />
      </div>
      <!--      <div class="category-page__wrapper">-->
      <!--        <p>Фото категории для каталога</p>-->
      <!--        <div class="category-page__images">-->
      <!--          <ImageUploadComponent-->
      <!--            v-model="form.site_catalog_img.value"-->
      <!--            text="Загрузить фото"-->
      <!--            class="image-catalog"-->
      <!--          />-->
      <!--        </div>-->
      <!--      </div>-->
      <p class="category-page_msg">
        <IconComponent name="information-line" />
        Вы можете загрузить лишь один формат фото из представленных
      </p>
      <div class="category-page__wrapper">
        <p>Фото категории</p>
        <div class="category-page__images">
          <ImageUploadComponent
            v-model="form.site_square_img.value"
            text="Загрузить фото"
            class="category-square-site"
          />
          <!--          <ImageUploadComponent-->
          <!--            v-model="form.site_rect_img.value"-->
          <!--            text="Загрузить фото"-->
          <!--            class="category-rect-site"-->
          <!--          />-->
        </div>
      </div>
      <div
        v-if="fieldsStore.isEnabled('Category', 'background_img')"
        class="category-page__wrapper"
      >
        <p>Фоновое изображение</p>
        <div class="category-page__images">
          <ImageUploadComponent
            v-model="form.background_img.value"
            text="Загрузить фото"
            class="category-background"
          />
        </div>
      </div>
      <!--      <div class="category-page__wrapper">-->
      <!--        <p>Фото категории для приложения</p>-->
      <!--        <div class="category-page__images">-->
      <!--          <ImageUploadComponent-->
      <!--            v-model="form.mobile_column_img.value"-->
      <!--            text="Загрузить фото"-->
      <!--            class="category-rect-column"-->
      <!--          />-->
      <!--          <ImageUploadComponent-->
      <!--            v-model="form.mobile_rect_img.value"-->
      <!--            text="Загрузить фото"-->
      <!--            class="category-square-mob"-->
      <!--          />-->
      <!--          <ImageUploadComponent-->
      <!--            v-model="form.mobile_square_img.value"-->
      <!--            text="Загрузить фото"-->
      <!--            class="category-rect-mob"-->
      <!--          />-->
      <!--        </div>-->
      <!--      </div>-->

      <div v-if="id" class="category-page__quantum">
        <InputComponent
          v-model="form.quantum_step.value"
          :errors="form.quantum_step.errors"
          title="Шаг кванта (продавать порциями по)"
          placeholder="—"
          :messages="['Цена пересчитывается из цены 1С автоматически. Пусто — продажа за базовую единицу. Применяется к товарам совпадающего семейства (масса/объём/штуки).']"
        />
        <SelectComponent
          title="Единица"
          placeholder="Единица"
          label-name="title"
          v-model="form.quantum_unit_type_id.value"
          :errors="form.quantum_unit_type_id.errors"
          :options="quantumUnits"
          clearable
        />
      </div>
      <div v-if="id" class="category-page__stock">
        <SelectComponent
          title="Учёт остатков"
          placeholder="Режим"
          label-name="title"
          v-model="form.stock_mode.value"
          :errors="form.stock_mode.errors"
          :options="stockModes"
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
      </div>
      <SelectComponent
        v-model="form.attribute_family_id.value"
        :errors="form.attribute_family_id.errors"
        :options="attributeFamilies"
        label-name="title"
        title="Семейство атрибутов"
        placeholder="Выберите семейство атрибутов"
        clearable
      />
      <SelectComponent
        v-model="form.parent.value"
        :errors="form.parent.errors"
        :options="parentCategories"
        label-name="title"
        title="Родительская подкатегория"
        placeholder="Родительская подкатегория"
        clearable
      />
      <SelectComponent
        v-if="assemblyZonesEnabled"
        v-model="form.assembly_zone.value"
        :errors="form.assembly_zone.errors"
        :options="assemblyZones"
        label-name="title"
        title="Зона сборки"
        placeholder="Не выбрана"
        clearable
      />
    </div>
    <div class="category-page__footer">
      <button @click.prevent="remove" v-if="id" class="btn btn--danger btn--md">Удалить</button>
      <button @click.prevent="submit" class="btn btn--main btn--md btn--save">Сохранить</button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import GET from "@/graphql/pages/Category/admin_categories_item.graphql";
import GET_WITHOUT_ID from "@/graphql/queries/categories.graphql";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import CustomCheckboxesField from "@/components/inputs/CustomCheckboxesField.vue";
import UPDATE from "@/graphql/pages/Category/CategoryUpdate.graphql";
import CREATE from "@/graphql/pages/Category/CategoryCreate.graphql";
import REMOVE from "@/graphql/mutations/EntityDelete.graphql";
import SET_QUANTUM from "@/graphql/mutations/SetCategoryQuantum.graphql";
import SET_CATEGORY_STOCK_MODE from "@/graphql/mutations/SetCategoryStockMode.graphql";
import { useDefaultStore } from "@/stores/default.js";
import { useCheckboxConfigStore } from "@/stores/checkboxConfig.js";
import { useFieldVisibilityConfigStore } from "@/stores/fieldVisibilityConfig.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import ImageUploadComponent from "@/components/ImageUpload.vue";
import { parseGqlErrors, resetErrors, parseCustomCheckboxes } from "@/utils.js";
// import ModalHeader from "@/components/modals/components/ModalHeader.vue";

const DEFAULT_TAG_COLOR = "#3B82F6";

export default {
  name: "CategoryPage",
  inject: ["apollo"],
  // props: {
  //   id: {
  //     type: Number,
  //   },
  //   callback: Function,
  // },
  components: {
    ImageUploadComponent,
    CheckboxComponent,
    CustomCheckboxesField,
    // ModalHeader,
    SelectComponent,
    InputComponent,
    IconComponent,
  },
  data() {
    return {
      parentCategories: [],
      attributeFamilies: [],
      assemblyZones: [],
      quantumUnits: [
        { id: 2, title: "Грамм" },
        { id: 1, title: "Штука" },
      ],
      stockModes: [
        { id: "normal", title: "Стандартный учёт" },
        { id: "ignore", title: "Не учитывать остатки" },
        { id: "fixed", title: "Фиксированный дневной лимит" },
      ],
      stockUnits: [
        { id: 2, title: "Грамм" },
        { id: 1, title: "Штука" },
      ],
      form: {
        title: {
          value: null,
          errors: [],
        },
        parent: {
          value: null,
          errors: [],
        },
        attribute_family_id: {
          value: null,
          errors: [],
        },
        without_blocking_price: {
          value: false,
          errors: [],
        },
        important: {
          value: false,
          errors: [],
        },
        quantum_step: {
          value: null,
          errors: [],
        },
        quantum_unit_type_id: {
          value: null,
          errors: [],
        },
        is_cooking: {
          value: null,
          errors: null,
        },
        is_perishable: {
          value: null,
          errors: null,
        },
        show_on_main: {
          value: false,
          errors: [],
        },
        tag_text: {
          value: null,
          errors: [],
        },
        tag_color: {
          value: DEFAULT_TAG_COLOR,
          errors: [],
        },
        is_active: {
          value: true,
          errors: [],
        },
        custom_checkboxes: {
          value: {},
          errors: [],
        },
        site_catalog_img: {
          value: null,
          errors: [],
        },
        site_square_img: {
          value: null,
          errors: [],
        },
        site_rect_img: {
          value: null,
          errors: [],
        },
        mobile_column_img: {
          value: null,
          errors: [],
        },
        mobile_square_img: {
          value: null,
          errors: [],
        },
        mobile_rect_img: {
          value: null,
          errors: [],
        },
        background_img: {
          value: null,
          errors: [],
        },
        assembly_zone: {
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
      },
    };
  },
  mounted() {
    this.get();
  },
  methods: {
    parseGqlErrors,
    resetErrors,
    get() {
      let variables = {
        id: parseInt(this.id),
      };
      this.apollo()
        .admin.query({
          query: this.id ? GET : GET_WITHOUT_ID,
          variables: variables,
        })
        .then(({ data }) => {
          if (data.admin_categories_item && this.id) {
            let info = data.admin_categories_item;
            Object.keys(this.form).forEach((key) => {
              this.form[key].value = info[key];
            });
            this.form.custom_checkboxes.value = parseCustomCheckboxes(info.custom_checkboxes);
            if (info.attribute_family) {
              this.form.attribute_family_id.value = info.attribute_family;
            }
            // Единица кванта (int) → опция SelectComponent {id, title}
            this.form.quantum_unit_type_id.value =
              this.quantumUnits.find((u) => u.id === info.quantum_unit_type_id) ?? null;
            // Режим учёта остатков
            this.form.stock_mode.value =
              this.stockModes.find((m) => m.id === (info.stock_mode || "normal")) ?? this.stockModes[0];
            this.form.fixed_stock_quantity.value = info.fixed_stock_quantity ?? null;
            this.form.fixed_stock_unit_type_id.value =
              this.stockUnits.find((u) => u.id === info.fixed_stock_unit_type_id) ?? null;
            this.form.tag_color.value = info.tag_color || DEFAULT_TAG_COLOR;
          }
          this.parentCategories = data.categories;
          this.attributeFamilies = data.attribute_families || [];
          this.assemblyZones = data.assembly_zones || [];
        })
        .catch(() => {});
    },
    submit() {
      this.resetErrors();
      let variables = {};
      Object.keys(this.form).forEach((key) => {
        variables[key] = this.form[key].value ?? undefined;
      });
      variables.id = parseInt(this.id) || undefined;
      variables.parent = this.form.parent.value ? this.form.parent.value.id : null;
      variables.attribute_family_id = this.form.attribute_family_id.value
        ? this.form.attribute_family_id.value.id
        : null;
      variables.important = this.isChild ? false : this.form.important.value;
      variables.assembly_zone_id = this.form.assembly_zone.value ? this.form.assembly_zone.value.id : null;
      delete variables.assembly_zone;
      // Квант сохраняется отдельной мутацией (с пересчётом цен товаров) — не в CategoryUpdate.
      delete variables.quantum_step;
      delete variables.quantum_unit_type_id;
      // Учёт остатков сохраняется отдельной мутацией — не в CategoryUpdate.
      delete variables.stock_mode;
      delete variables.fixed_stock_quantity;
      delete variables.fixed_stock_unit_type_id;
      variables.custom_checkboxes = JSON.stringify(this.form.custom_checkboxes.value || {});
      // Тег: без текста не сохраняем и цвет (тег не задан)
      const tagText = (this.form.tag_text.value || "").trim();
      variables.tag_text = tagText || null;
      variables.tag_color = tagText ? this.form.tag_color.value || DEFAULT_TAG_COLOR : null;

      this.apollo()
        .admin.mutate({
          mutation: this.id ? UPDATE : CREATE,
          variables: variables,
        })
        .then(async () => {
          if (this.id) {
            await this.saveQuantum();
            await this.saveStockMode();
          }
          this.$router.push({ name: "categories" });
        })
        .catch(({ graphQLErrors }) => {
          this.parseGqlErrors(graphQLErrors);
        });
    },
    async saveQuantum() {
      const raw = this.form.quantum_step.value;
      const step = raw === null || raw === "" ? null : parseInt(raw);
      const unitOpt = this.form.quantum_unit_type_id.value;
      const unit = step === null ? null : unitOpt?.id ?? unitOpt ?? null;
      try {
        await this.apollo().admin.mutate({
          mutation: SET_QUANTUM,
          variables: { category_id: parseInt(this.id), quantum_step: step, quantum_unit_type_id: unit },
        });
      } catch (e) {
        // листовая валидация на бэке — показать, но не блокировать сохранение остального
        console.error("SetCategoryQuantum", e);
      }
    },
    async saveStockMode() {
      const mode = this.form.stock_mode.value?.id ?? "normal";
      const variables = {
        category_id: parseInt(this.id),
        stock_mode: mode,
        fixed_stock_quantity: mode === "fixed" ? parseInt(this.form.fixed_stock_quantity.value) || null : null,
        fixed_stock_unit_type_id: mode === "fixed" ? (this.form.fixed_stock_unit_type_id.value?.id ?? null) : null,
      };
      try {
        await this.apollo().admin.mutate({
          mutation: SET_CATEGORY_STOCK_MODE,
          variables,
        });
      } catch (e) {
        console.error("SetCategoryStockMode", e);
      }
    },
    remove() {
      if (confirm("Вы уверены?")) {
        this.apollo()
          .admin.mutate({
            mutation: REMOVE,
            variables: {
              id: parseInt(this.id),
              table: "categories",
            },
          })
          .then(() => {
            if (this.callback) {
              this.callback();
            }
            this.$router.push({ name: "categories" });
            useDefaultStore().modals = [];
          });
      }
    },
  },
  computed: {
    checkboxStore() {
      return useCheckboxConfigStore();
    },
    fieldsStore() {
      return useFieldVisibilityConfigStore();
    },
    assemblyZonesEnabled() {
      return useModuleConfigStore().isModuleEnabled("assembly_zones");
    },
    isChild() {
      return !!this.form.parent.value;
    },
    id() {
      return this.$route.params.id;
    },
  },
};
</script>

<style lang="stylus">
.category-page {
  display flex
  flex-direction column
  background-color var(--white)
  //border-radius: 16px;
  //max-width 608px

  &__body {
    gap 16px
    max-width 700px
  }

  p {
    font-weight: 400;
    font-size: 0.8125em;
    line-height: 20px;
    color: var(--dark);
    margin 0
  }
  &_msg {
    display: flex;
    align-items: center;
    gap 8px
    padding 16px 11px
    width: 100%;
    background: var(--blue-light);
    border-radius: 16px;
    svg{
      max-height: 24px;
    }
  }

  & .btn--save {
    margin-left auto
  }

  .textarea__field-label {
    border 1px solid var(--white-2)
  }

  &__footer {
    display flex
    justify-content space-between
    width 100%
    max-width 700px
  }

  &__wrapper {
    display flex
    flex-direction column
    gap 16px
  }

  &__images {
    display flex
    gap 16px
  }

  &__tag {
    display flex
    gap 16px
    align-items flex-start

    & > *:first-child {
      flex 1
    }
  }

  &__tag-color {
    max-width 120px
  }

  & .image-catalog {
    width 80px
    height 80px
  }

  & .category-square-site, .category-square-mob {
    width 117px
    height 117px
  }

  & .category-background {
    width 226px
    height 117px
  }

  & .category-rect-site {
    width 226px
    height 117px
  }

  & .category-rect-mob {
    width 160px
    height 117px
  }

  & .category-rect-column {
    width 73px
    height 117px
  }

  &__important {

    & .checkbox__field {
      font-style: normal;
      font-weight: 400;
      font-size: 0.813rem
      line-height: 140%;
      color: var(--black-2)
    }
  }
}
</style>
