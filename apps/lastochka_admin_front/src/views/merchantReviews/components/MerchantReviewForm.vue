<template>
  <form class="merchant-review-form" @submit.prevent="submit">
    <div class="merchant-review-form__grid">
      <!-- Основные поля -->
      <div class="merchant-review-form__main">
        <!-- Магазин (только для админа при создании) -->
        <div v-if="isAdmin" class="merchant-review-form__field">
          <label class="merchant-review-form__label">Магазин <span class="required">*</span></label>
          <ShopSelectComponent
            v-model="form.shop_id"
            :errors="errors.shop_id ? [errors.shop_id] : []"
            :disabled="isEdit || viewOnly"
          />
        </div>

        <!-- Оценка -->
        <div class="merchant-review-form__field">
          <label class="merchant-review-form__label">Оценка <span class="required">*</span></label>
          <StarRating
            v-model="form.rating"
            :readonly="viewOnly"
          />
          <span v-if="errors.rating" class="merchant-review-form__error">{{ errors.rating }}</span>
        </div>

        <!-- Описание -->
        <div class="merchant-review-form__field">
          <label class="merchant-review-form__label">Описание</label>
          <textarea
            v-model="form.description"
            placeholder="Введите текст отзыва..."
            class="merchant-review-form__textarea"
            :class="{ 'merchant-review-form__textarea--error': errors.description }"
            :disabled="viewOnly"
            maxlength="2000"
            rows="5"
          ></textarea>
          <div class="merchant-review-form__char-count">
            {{ form.description?.length || 0 }} / 2000 символов
          </div>
          <span v-if="errors.description" class="merchant-review-form__error">{{ errors.description }}</span>
        </div>

        <!-- Публикация -->
        <div class="merchant-review-form__field merchant-review-form__field--toggle">
          <label class="merchant-review-form__label">Опубликовать</label>
          <label class="merchant-review-form__toggle">
            <input type="checkbox" v-model="form.is_published" :disabled="viewOnly" />
            <span class="merchant-review-form__toggle-slider"></span>
            <span class="merchant-review-form__toggle-text">{{ form.is_published ? 'Опубликован' : 'Черновик' }}</span>
          </label>
        </div>
      </div>

      <!-- Информация об авторе -->
      <div class="merchant-review-form__side">
        <h3 class="merchant-review-form__section-title">Информация об авторе</h3>

        <!-- Имя автора -->
        <div class="merchant-review-form__field">
          <label class="merchant-review-form__label">Имя автора</label>
          <input
            type="text"
            v-model="form.author_name"
            placeholder="Введите имя автора"
            class="merchant-review-form__input"
            :class="{ 'merchant-review-form__input--error': errors.author_name }"
            :disabled="viewOnly"
            maxlength="255"
          />
          <span v-if="errors.author_name" class="merchant-review-form__error">{{ errors.author_name }}</span>
        </div>

        <!-- Телефон автора -->
        <div class="merchant-review-form__field">
          <label class="merchant-review-form__label">Телефон автора</label>
          <input
            type="tel"
            v-model="form.author_phone"
            v-maska="'+7 (###) ###-##-##'"
            placeholder="+7 (___) ___-__-__"
            class="merchant-review-form__input"
            :class="{ 'merchant-review-form__input--error': errors.author_phone }"
            :disabled="viewOnly"
          />
          <span v-if="errors.author_phone" class="merchant-review-form__error">{{ errors.author_phone }}</span>
        </div>
      </div>
    </div>

    <!-- Кнопки -->
    <div v-if="!viewOnly" class="merchant-review-form__actions">
      <button type="button" class="btn btn--white btn--md" @click="$emit('cancel')">
        Отмена
      </button>
      <button type="submit" class="btn btn--md" :disabled="loading">
        <span v-if="loading">Сохранение...</span>
        <span v-else>{{ isEdit ? 'Сохранить' : 'Создать' }}</span>
      </button>
    </div>
  </form>
</template>

<script>
import ShopSelectComponent from "@/components/inputs/ShopSelectComponent.vue";
import StarRating from "./StarRating.vue";
import { useAuthStore } from "@/stores/auth.js";
import { maska } from "maska";

export default {
  name: "MerchantReviewForm",
  inject: ["apollo"],
  components: {
    ShopSelectComponent,
    StarRating,
  },
  directives: {
    maska,
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
    viewOnly: {
      type: Boolean,
      default: false,
    },
  },
  emits: ["submit", "cancel"],
  data() {
    return {
      form: {
        shop_id: null,
        rating: 0,
        description: "",
        is_published: false,
        author_name: "",
        author_phone: "",
      },
      errors: {},
      isDirty: false,
    };
  },
  computed: {
    user() {
      return useAuthStore().user;
    },
    userRole() {
      return this.user?.roles?.[0]?.name;
    },
    isAdmin() {
      return this.userRole === "admin";
    },
    userShopId() {
      return this.user?.shop_id || this.user?.shop?.id || this.user?.shops?.[0]?.shop_id || this.user?.shops?.[0]?.shop?.id;
    },
  },
  watch: {
    initialData: {
      immediate: true,
      handler(data) {
        if (data) {
          const shopId = data.shop_id || data.shop?.id || null;
          this.form = {
            shop_id: shopId ? parseInt(shopId) : null,
            rating: data.rating || 0,
            description: data.description || "",
            is_published: data.is_published ?? false,
            author_name: data.author_name || "",
            author_phone: data.author_phone || "",
          };
          this.$nextTick(() => {
            this.isDirty = false;
          });
        } else if (!this.isAdmin && this.userShopId) {
          this.form.shop_id = parseInt(this.userShopId);
        }
      },
    },
    form: {
      deep: true,
      handler() {
        this.isDirty = true;
      },
    },
  },
  mounted() {
    if (this.isAdmin) {
    }

    // Предупреждение о несохранённых изменениях
    window.addEventListener("beforeunload", this.handleBeforeUnload);
  },
  beforeUnmount() {
    window.removeEventListener("beforeunload", this.handleBeforeUnload);
  },
  methods: {
    handleBeforeUnload(e) {
      if (this.isDirty && !this.viewOnly) {
        e.preventDefault();
        e.returnValue = "";
      }
    },
    validate() {
      this.errors = {};

      if (this.isAdmin && !this.form.shop_id) {
        this.errors.shop_id = "Выберите магазин";
      }

      if (!this.form.rating || this.form.rating < 1 || this.form.rating > 5) {
        this.errors.rating = "Выберите оценку от 1 до 5";
      }

      if (this.form.description && this.form.description.length > 2000) {
        this.errors.description = "Максимум 2000 символов";
      }

      if (this.form.author_name && this.form.author_name.length > 255) {
        this.errors.author_name = "Максимум 255 символов";
      }

      // Валидация телефона (если указан)
      if (this.form.author_phone) {
        const phoneDigits = this.form.author_phone.replace(/\D/g, "");
        if (phoneDigits.length > 0 && phoneDigits.length !== 11) {
          this.errors.author_phone = "Введите полный номер телефона";
        }
      }

      return Object.keys(this.errors).length === 0;
    },
    submit() {
      if (!this.validate()) return;

      const data = {
        rating: parseInt(this.form.rating),
        description: this.form.description?.trim() || null,
        is_published: this.form.is_published,
        author_name: this.form.author_name?.trim() || null,
        author_phone: this.form.author_phone?.trim() || null,
      };

      // shop_id только при создании, при редактировании нельзя менять магазин
      if (!this.isEdit) {
        data.shop_id = parseInt(this.form.shop_id);
      }

      this.isDirty = false;
      this.$emit("submit", data);
    },
    checkUnsavedChanges() {
      if (this.isDirty && !this.viewOnly) {
        return confirm("Есть несохранённые изменения. Вы уверены, что хотите уйти?");
      }
      return true;
    },
  },
};
</script>

<style lang="stylus">
.merchant-review-form {
  &__grid {
    display grid
    grid-template-columns 1fr 1fr
    gap 32px

    +below(1200px) {
      grid-template-columns 1fr
    }
  }

  &__main,
  &__side {
    display flex
    flex-direction column
    gap 24px
  }

  &__section-title {
    font-size 1rem
    font-weight 600
    color var(--dark)
    margin-bottom 0
    padding-bottom 16px
    border-bottom 1px solid var(--gray-dark)
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

  &__input,
  &__select,
  &__textarea {
    width 100%
    padding 12px 16px
    border 1px solid var(--gray-dark)
    border-radius 8px
    font-size 0.875rem
    outline none
    transition border-color 0.2s
    background var(--white)

    &:focus {
      border-color var(--pink)
    }

    &:disabled {
      background var(--gray-3)
      cursor not-allowed
    }

    &--error {
      border-color var(--red)
    }
  }

  &__textarea {
    resize vertical
    min-height 120px
  }

  &__char-count {
    margin-top 4px
    font-size 0.75rem
    color var(--dark-light)
    text-align right
  }

  &__select-wrapper {
    position relative
  }

  &__select {
    appearance none
    padding-right 40px
    cursor pointer
  }

  &__select-icon {
    position absolute
    right 12px
    top 50%
    transform translateY(-50%)
    pointer-events none

    svg {
      width 20px
      height 20px
    }
  }

  &__error {
    display block
    margin-top 4px
    font-size 0.75rem
    color var(--red)
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

    input:disabled + & {
      opacity 0.6
      cursor not-allowed
    }
  }

  &__toggle-text {
    font-size 0.875rem
    color var(--dark)
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
