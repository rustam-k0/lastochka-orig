<template>
  <form class="brand-form" @submit.prevent="submit">
    <div class="brand-form__grid">
      <!-- Левая колонка -->
      <div class="brand-form__main">
        <!-- Название -->
        <div class="brand-form__field">
          <label class="brand-form__label">Название <span class="required">*</span></label>
          <input
            type="text"
            v-model="form.title"
            placeholder="Введите название бренда"
            class="brand-form__input"
            :class="{ 'brand-form__input--error': errors.title }"
            maxlength="255"
          />
          <span v-if="errors.title" class="brand-form__error">{{ errors.title }}</span>
        </div>

        <!-- Ссылка -->
        <div class="brand-form__field">
          <label class="brand-form__label">Ссылка</label>
          <input
            type="url"
            v-model="form.link"
            placeholder="https://example.com"
            class="brand-form__input"
            maxlength="500"
          />
        </div>

        <!-- Страна происхождения -->
        <div class="brand-form__field">
          <SelectComponent
            title="Страна происхождения (родина бренда)"
            placeholder="Начните вводить название"
            label-name="title"
            v-model="form.country_origin"
            :options="countries"
            :loading="countriesLoading"
            @update:model-value="onCountryChange('country_origin')"
            clearable
          />
          <span v-if="legacyCountries.country_origin" class="brand-form__hint">
            Сейчас сохранено «{{ legacyCountries.country_origin }}», в справочнике такой страны нет. Выберите
            страну из списка, иначе на витрине не будет флага.
          </span>
        </div>

        <!-- Страна производства -->
        <div class="brand-form__field">
          <SelectComponent
            title="Страна производства"
            placeholder="Начните вводить название"
            label-name="title"
            v-model="form.country_production"
            :options="countries"
            :loading="countriesLoading"
            @update:model-value="onCountryChange('country_production')"
            clearable
          />
          <span v-if="legacyCountries.country_production" class="brand-form__hint">
            Сейчас сохранено «{{ legacyCountries.country_production }}», в справочнике такой страны нет.
            Выберите страну из списка, иначе на витрине не будет флага.
          </span>
        </div>

        <!-- Позиция -->
        <div class="brand-form__field">
          <label class="brand-form__label">Позиция</label>
          <input
            type="number"
            v-model.number="form.position"
            placeholder="Позиция сортировки"
            class="brand-form__input"
            min="0"
          />
        </div>

        <!-- Активность -->
        <div class="brand-form__field brand-form__field--toggle">
          <label class="brand-form__label">Активность</label>
          <label class="brand-form__toggle">
            <input type="checkbox" v-model="form.is_active" />
            <span class="brand-form__toggle-slider"></span>
            <span class="brand-form__toggle-text">{{ form.is_active ? "Активен" : "Неактивен" }}</span>
          </label>
        </div>

        <div class="brand-form__field">
          <CustomCheckboxesField entity="Brand" v-model="form.custom_checkboxes" />
        </div>
      </div>

      <!-- Правая колонка - логотип и сертификат -->
      <div class="brand-form__media">
        <div class="brand-form__field">
          <label class="brand-form__label">Логотип <span class="required">*</span></label>
          <div class="brand-form__logo">
            <ImageUpload v-model="form.logo_image" text="Загрузить логотип" @uploadImage="onLogoUpload" />
          </div>
          <span v-if="errors.logo_image" class="brand-form__error">{{ errors.logo_image }}</span>
        </div>

        <div class="brand-form__field">
          <FileUploadComponent
            title="Сертификат официального дилера"
            text="Загрузить сертификат (PDF)"
            path="brands"
            v-model="form.certificate"
          />
        </div>
      </div>
    </div>

    <!-- Кнопки -->
    <div class="brand-form__actions">
      <button type="button" class="btn btn--white btn--md" @click="$emit('cancel')">Отмена</button>
      <button type="submit" class="btn btn--md" :disabled="loading">
        <span v-if="loading">Сохранение...</span>
        <span v-else>{{ isEdit ? "Сохранить" : "Создать" }}</span>
      </button>
    </div>
  </form>
</template>

<script>
import ImageUpload from "@/components/ImageUpload.vue";
import FileUploadComponent from "@/components/inputs/FileUploadComponent.vue";
import CustomCheckboxesField from "@/components/inputs/CustomCheckboxesField.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import COUNTRIES from "@/graphql/queries/admin_countries.graphql";
import { parseCustomCheckboxes } from "@/utils.js";

export default {
  name: "BrandForm",
  inject: ["apollo"],
  components: {
    ImageUpload,
    FileUploadComponent,
    CustomCheckboxesField,
    SelectComponent,
  },
  props: {
    initialData: {
      type: Object,
      default: null,
    },
    loading: {
      type: Boolean,
      default: false,
    },
    isEdit: {
      type: Boolean,
      default: false,
    },
  },
  emits: ["submit", "cancel"],
  data() {
    return {
      form: {
        title: "",
        link: "",
        country_origin: null,
        country_production: null,
        certificate: null,
        is_active: true,
        position: null,
        logo_image: null,
        custom_checkboxes: {},
      },
      countries: [],
      countriesLoading: false,
      // Названия, сохранённые до появления справочника и не найденные в нём.
      legacyCountries: {
        country_origin: null,
        country_production: null,
      },
      // Поля, которые админ трогал руками: только их отправляем на сервер.
      touchedCountries: {
        country_origin: false,
        country_production: false,
      },
      errors: {},
    };
  },
  mounted() {
    this.loadCountries();
  },
  watch: {
    initialData: {
      immediate: true,
      handler(data) {
        if (data) {
          this.form = {
            title: data.title || "",
            link: data.link || "",
            country_origin: null,
            country_production: null,
            certificate: data.certificate_path
              ? {
                  path: data.certificate_path,
                  origin_name: data.certificate_name || "Сертификат.pdf",
                  size: null,
                  type: "pdf",
                }
              : null,
            is_active: data.is_active ?? true,
            position: data.position ?? null,
            logo_image: data.logoImage || null,
            custom_checkboxes: parseCustomCheckboxes(data.custom_checkboxes),
          };
          this.touchedCountries = { country_origin: false, country_production: false };
          this.applyCountries();
        }
      },
    },
  },
  methods: {
    loadCountries() {
      this.countriesLoading = true;

      this.apollo()
        .admin.query({ query: COUNTRIES })
        .then(({ data }) => {
          this.countries = data?.admin_countries || [];
          this.applyCountries();
        })
        .catch((error) => {
          console.error("Error loading countries:", error);
        })
        .finally(() => {
          this.countriesLoading = false;
        });
    },
    /**
     * Сопоставление сохранённых значений со справочником.
     * Вызывается и после загрузки бренда, и после загрузки справочника,
     * потому что порядок ответов не гарантирован.
     */
    applyCountries() {
      const data = this.initialData;

      if (!data) return;

      const fields = {
        country_origin: "country_origin_code",
        country_production: "country_production_code",
      };

      Object.entries(fields).forEach(([titleField, codeField]) => {
        if (this.touchedCountries[titleField]) return;

        const code = data[codeField];
        const title = data[titleField];

        if (code) {
          this.form[titleField] = this.countries.find((c) => c.code === code) || {
            code,
            title: title || code,
          };
          this.legacyCountries[titleField] = null;
          return;
        }

        if (!title) {
          this.form[titleField] = null;
          this.legacyCountries[titleField] = null;
          return;
        }

        // Бренд заведён до справочника: пробуем узнать страну по названию.
        const byTitle = this.countries.find((c) => c.title.toLowerCase() === title.trim().toLowerCase());

        this.form[titleField] = byTitle || null;
        this.legacyCountries[titleField] = byTitle ? null : title;
      });
    },
    onCountryChange(field) {
      this.touchedCountries[field] = true;
      this.legacyCountries[field] = null;
    },
    validate() {
      this.errors = {};

      if (!this.form.title || !this.form.title.trim()) {
        this.errors.title = "Название обязательно";
      } else if (this.form.title.length > 255) {
        this.errors.title = "Максимум 255 символов";
      }

      if (!this.form.logo_image && !this.isEdit) {
        this.errors.logo_image = "Логотип обязателен";
      }

      return Object.keys(this.errors).length === 0;
    },
    submit() {
      if (!this.validate()) return;

      const data = {
        title: this.form.title.trim(),
        link: this.form.link?.trim() || null,
        certificate_path: this.form.certificate?.path || null,
        certificate_name: this.form.certificate?.origin_name || null,
        is_active: this.form.is_active,
        position: this.form.position,
        custom_checkboxes: JSON.stringify(this.form.custom_checkboxes || {}),
      };

      // Название страны подставляет бэкенд по коду.
      // Поле, которое админ не трогал, не отправляем вовсе: иначе сохранение
      // формы стёрло бы название, заведённое до появления справочника.
      ["country_origin", "country_production"].forEach((field) => {
        const selected = this.form[field];

        if (selected) {
          data[`${field}_code`] = selected.code;
        } else if (this.touchedCountries[field] || !this.legacyCountries[field]) {
          data[`${field}_code`] = null;
        }
      });

      if (this.form.logo_image) {
        data.logo_image = this.formatImage(this.form.logo_image);
      }

      this.$emit("submit", data);
    },
    formatImage(img) {
      if (!img) return null;
      return {
        path: img.path,
        origin_name: img.origin_name,
        alt: img.alt,
        size: img.size,
        type: img.type,
      };
    },
    onLogoUpload() {
      // Callback after logo upload
    },
  },
};
</script>

<style lang="stylus">
.brand-form {
  &__grid {
    display grid
    grid-template-columns 1fr 300px
    gap 32px

    +below(1200px) {
      grid-template-columns 1fr
    }
  }

  &__main {
    display flex
    flex-direction column
    gap 24px
  }

  &__media {
    display flex
    flex-direction column
    gap 24px
  }

  &__field {
    &--toggle {
      display flex
      align-items center
      justify-content space-between
    }
  }

  &__label {
    display block
    font-weight 600
    font-size 0.875rem
    color var(--dark)
    margin-bottom 8px

    .required {
      color var(--red)
    }
  }

  &__input {
    width 100%
    padding 12px 16px
    border 1px solid var(--gray-dark)
    border-radius 8px
    font-size 0.875rem
    outline none
    transition border-color 0.2s

    &:focus {
      border-color var(--pink)
    }

    &--error {
      border-color var(--red)
    }
  }

  &__error {
    display block
    margin-top 4px
    font-size 0.75rem
    color var(--red)
  }

  &__hint {
    display block
    margin-top 4px
    font-size 0.75rem
    color var(--gray-dark-2)
  }

  &__toggle {
    display flex
    align-items center
    gap 12px
    cursor pointer

    input {
      display none
    }
  }

  &__toggle-slider {
    width 48px
    height 24px
    background var(--gray-dark)
    border-radius 24px
    position relative
    transition background 0.2s

    &::after {
      content ''
      position absolute
      width 20px
      height 20px
      background var(--white)
      border-radius 50%
      top 2px
      left 2px
      transition left 0.2s
    }

    input:checked + & {
      background var(--green)

      &::after {
        left 26px
      }
    }
  }

  &__toggle-text {
    font-size 0.875rem
    color var(--dark)
  }

  &__logo {
    width 128px
    height 128px

    .image-upload__container {
      width 100%
      height 100%
    }
  }

  &__actions {
    display flex
    justify-content flex-end
    gap 16px
    margin-top 32px
    padding-top 24px
    border-top 1px solid var(--gray-dark)
  }
}
</style>
