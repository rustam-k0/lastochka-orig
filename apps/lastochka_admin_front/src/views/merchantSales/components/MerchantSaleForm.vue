<template>
  <form class="merchant-sale-form" @submit.prevent="submit">
    <div class="merchant-sale-form__grid">
      <!-- Левая колонка - основные поля -->
      <div class="merchant-sale-form__main">
        <!-- Название -->
        <div class="merchant-sale-form__field">
          <label class="merchant-sale-form__label">Название <span class="required">*</span></label>
          <input 
            type="text"
            v-model="form.title"
            placeholder="Введите название акции"
            class="merchant-sale-form__input"
            :class="{ 'merchant-sale-form__input--error': errors.title }"
            maxlength="255"
          />
          <span v-if="errors.title" class="merchant-sale-form__error">{{ errors.title }}</span>
        </div>

        <!-- Описание -->
        <div class="merchant-sale-form__field">
          <label class="merchant-sale-form__label">Описание</label>
          <textarea
            v-model="form.description"
            placeholder="Описание акции (опционально)"
            class="merchant-sale-form__textarea"
            rows="3"
          ></textarea>
        </div>

        <!-- Магазины: в сетевом каталоге при создании — мультивыбор или вся
             сеть (MID-237); иначе прежний одиночный селект -->
        <div v-if="isNetworkCreate" class="merchant-sale-form__field">
          <ShopMultiTargetField
            v-model="form.multi_target"
            :options="shops"
            :error="errors.shop_id"
            @search="onShopSearch"
          />
        </div>
        <div v-else class="merchant-sale-form__field">
          <label class="merchant-sale-form__label">Магазин <span class="required">*</span></label>
          <ShopSelectComponent
            :model-value="form.shop_id"
            :errors="errors.shop_id ? [errors.shop_id] : []"
            @change="onShopChange"
          />
        </div>

        <!-- Процент скидки -->
        <div class="merchant-sale-form__field">
          <label class="merchant-sale-form__label">Скидка (%) <span class="required">*</span></label>
          <div class="merchant-sale-form__number-wrapper">
            <input 
              type="number"
              v-model.number="form.discount_percent"
              placeholder="Введите процент скидки"
              class="merchant-sale-form__input"
              :class="{ 'merchant-sale-form__input--error': errors.discount_percent }"
              min="1"
              max="100"
            />
            <span class="merchant-sale-form__percent">%</span>
          </div>
          <span v-if="errors.discount_percent" class="merchant-sale-form__error">{{ errors.discount_percent }}</span>
        </div>

        <!-- Период действия -->
        <div class="merchant-sale-form__field">
          <label class="merchant-sale-form__label">Период действия <span class="required">*</span></label>
          <div class="merchant-sale-form__date-range">
            <DateInput
              v-model="form.starts_at"
              placeholder="Дата начала"
              :errors="errors.starts_at ? [errors.starts_at] : []"
            />
            <span class="merchant-sale-form__date-separator">—</span>
            <DateInput
              v-model="form.ends_at"
              placeholder="Дата окончания"
              :errors="errors.ends_at ? [errors.ends_at] : []"
            />
          </div>
        </div>

        <!-- Активность -->
        <div class="merchant-sale-form__field merchant-sale-form__field--toggle">
          <label class="merchant-sale-form__label">Активность</label>
          <label class="merchant-sale-form__toggle">
            <input type="checkbox" v-model="form.is_active" />
            <span class="merchant-sale-form__toggle-slider"></span>
            <span class="merchant-sale-form__toggle-text">{{ form.is_active ? 'Активна' : 'Неактивна' }}</span>
          </label>
        </div>

        <div class="merchant-sale-form__field">
          <CustomCheckboxesField entity="MerchantSale" v-model="form.custom_checkboxes" />
        </div>
      </div>

      <!-- Правая колонка - изображение и товары -->
      <div class="merchant-sale-form__side">
        <!-- Изображение -->
        <div class="merchant-sale-form__field">
          <label class="merchant-sale-form__label">Изображение акции</label>
          <div class="merchant-sale-form__image-upload">
            <ImageUpload
              v-model="form.image"
              text="Загрузить изображение"
              @uploadImage="onImageUpload"
            />
          </div>
        </div>

        <!-- Товары -->
        <MerchantSaleProductPicker
          v-model="form.products"
          :shopId="pickerShopId"
          :excludeSaleId="excludeSaleId"
          :discountPercent="form.discount_percent || 0"
          :error="errors.products"
        />
      </div>
    </div>

    <!-- Кнопки -->
    <div class="merchant-sale-form__actions">
      <button type="button" class="btn btn--white btn--md" @click="$emit('cancel')">
        Отмена
      </button>
      <button type="submit" class="btn btn--md" :disabled="loading">
        <span v-if="loading">Сохранение...</span>
        <span v-else>{{ isEdit ? 'Сохранить' : 'Создать' }}</span>
      </button>
    </div>

    <!-- Модальное окно подтверждения смены мерчанта -->
    <div v-if="showShopChangeConfirm" class="merchant-sale-form__modal-overlay" @click.self="cancelShopChange">
      <div class="merchant-sale-form__modal">
        <h3>Сменить магазин?</h3>
        <p>При смене магазина выбранные товары будут сброшены. Продолжить?</p>
        <div class="merchant-sale-form__modal-actions">
          <button type="button" class="btn btn--white btn--sm" @click="cancelShopChange">Отмена</button>
          <button type="button" class="btn btn--sm" @click="confirmShopChange">Продолжить</button>
        </div>
      </div>
    </div>
  </form>
</template>

<script>
import ShopSelectComponent from "@/components/inputs/ShopSelectComponent.vue";
import DateInput from "@/components/inputs/DateInput.vue";
import ImageUpload from "@/components/ImageUpload.vue";
import CustomCheckboxesField from "@/components/inputs/CustomCheckboxesField.vue";
import ShopMultiTargetField from "@/components/inputs/ShopMultiTargetField.vue";
import MerchantSaleProductPicker from "./MerchantSaleProductPicker.vue";
import SHOPS_PAGINATE from "@/graphql/queries/shops_paginate.graphql";
import dayjs from "dayjs";
import { parseCustomCheckboxes } from "@/utils.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";

// Сентинел shop_id для пикера товаров в сетевом каталоге: бэкенд в network
// mode игнорирует shop_id и отдаёт весь каталог сети (MID-237)
const NETWORK_PICKER_SHOP_ID = -1;

export default {
  name: "MerchantSaleForm",
  inject: ["apollo"],
  components: {
    ShopSelectComponent,
    DateInput,
    ImageUpload,
    CustomCheckboxesField,
    ShopMultiTargetField,
    MerchantSaleProductPicker,
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
    excludeSaleId: {
      type: [Number, String],
      default: null,
    },
  },
  emits: ["submit", "cancel"],
  data() {
    return {
      form: {
        title: "",
        description: "",
        shop_id: null,
        multi_target: { all_shops: false, shops: [] },
        discount_percent: null,
        starts_at: null,
        ends_at: null,
        is_active: true,
        image: null,
        products: [],
        custom_checkboxes: {},
      },
      errors: {},
      shops: [],
      shopSearchDebounce: null,
      showShopChangeConfirm: false,
      pendingShopId: null,
      previousShopId: null,
    };
  },
  computed: {
    networkCatalogEnabled() {
      return useModuleConfigStore().isModuleEnabled("network_catalog");
    },
    // Мультивыбор магазинов — только при создании в сетевом каталоге;
    // редактирование остаётся пер-запись (MID-237)
    isNetworkCreate() {
      return this.networkCatalogEnabled && !this.isEdit;
    },
    pickerShopId() {
      return this.isNetworkCreate ? NETWORK_PICKER_SHOP_ID : this.form.shop_id;
    },
  },
  watch: {
    initialData: {
      immediate: true,
      handler(data) {
        if (data) {
          const shopId = data.shop_id || data.shop?.id || null;
          this.form = {
            title: data.title || "",
            description: data.description || "",
            shop_id: shopId ? parseInt(shopId) : null,
            discount_percent: data.discount_percent || null,
            starts_at: data.starts_at ? dayjs(data.starts_at).format() : null,
            ends_at: data.ends_at ? dayjs(data.ends_at).format() : null,
            is_active: data.is_active ?? true,
            image: data.image || null,
            products: (data.products || []).map(p => ({ ...p, id: parseInt(p.id) })),
            custom_checkboxes: parseCustomCheckboxes(data.custom_checkboxes),
          };
          this.previousShopId = shopId ? parseInt(shopId) : null;
        }
      },
    },
  },
  mounted() {
    this.fetchShops();
  },
  methods: {
    fetchShops(title = null) {
      this.apollo()
        .admin.query({
          query: SHOPS_PAGINATE,
          variables: { first: 100, title: title || undefined },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.shops = data.shops_paginate?.data || [];
        })
        .catch((error) => {
          console.error("Error fetching shops:", error);
        });
    },
    // Серверный поиск магазинов для мультиселекта: в сети магазинов ~1.5k,
    // first:100 без поиска не покрывает список (MID-237)
    onShopSearch(title) {
      clearTimeout(this.shopSearchDebounce);
      this.shopSearchDebounce = setTimeout(() => this.fetchShops(title), 300);
    },
    onShopChange(newShopId) {

      if (this.form.products.length > 0 && this.previousShopId && this.previousShopId !== newShopId) {
        this.pendingShopId = newShopId;
        this.form.shop_id = this.previousShopId;
        this.showShopChangeConfirm = true;
      } else {
        this.previousShopId = newShopId;
        this.form.shop_id = newShopId;
      }
    },
    confirmShopChange() {
      this.form.shop_id = this.pendingShopId;
      this.form.products = [];
      this.previousShopId = this.pendingShopId;
      this.pendingShopId = null;
      this.showShopChangeConfirm = false;
    },
    cancelShopChange() {
      this.pendingShopId = null;
      this.showShopChangeConfirm = false;
    },
    validate() {
      this.errors = {};
      
      if (!this.form.title || !this.form.title.trim()) {
        this.errors.title = "Название обязательно";
      } else if (this.form.title.length > 255) {
        this.errors.title = "Максимум 255 символов";
      }
      
      if (this.isNetworkCreate) {
        if (!this.form.multi_target.all_shops && !this.form.multi_target.shops.length) {
          this.errors.shop_id = "Выберите магазины или включите «Во всех магазинах сети»";
        }
      } else if (!this.form.shop_id) {
        this.errors.shop_id = "Выберите магазин";
      }
      
      if (!this.form.discount_percent) {
        this.errors.discount_percent = "Укажите процент скидки";
      } else if (this.form.discount_percent < 1 || this.form.discount_percent > 100) {
        this.errors.discount_percent = "Скидка должна быть от 1% до 100%";
      }
      
      if (!this.form.starts_at) {
        this.errors.starts_at = "Укажите дату начала";
      }
      
      if (!this.form.ends_at) {
        this.errors.ends_at = "Укажите дату окончания";
      } else if (this.form.starts_at && dayjs(this.form.ends_at).isBefore(dayjs(this.form.starts_at))) {
        this.errors.ends_at = "Дата окончания должна быть после даты начала";
      }
      
      if (!this.form.products || this.form.products.length === 0) {
        this.errors.products = "Выберите хотя бы один товар";
      }
      
      return Object.keys(this.errors).length === 0;
    },
    submit() {
      if (!this.validate()) return;
      
      const data = {
        title: this.form.title.trim(),
        description: this.form.description?.trim() || null,
        discount_percent: parseInt(this.form.discount_percent),
        starts_at: dayjs(this.form.starts_at).format("YYYY-MM-DD HH:mm:ss"),
        ends_at: dayjs(this.form.ends_at).format("YYYY-MM-DD HH:mm:ss"),
        is_active: this.form.is_active,
        product_ids: this.form.products.map((p) => parseInt(p.id)),
        custom_checkboxes: JSON.stringify(this.form.custom_checkboxes || {}),
      };

      if (this.isNetworkCreate) {
        if (this.form.multi_target.all_shops) {
          data.all_shops = true;
        } else {
          data.shop_ids = this.form.multi_target.shops.map((s) => parseInt(s.id));
        }
      } else {
        data.shop_id = parseInt(this.form.shop_id);
      }
      
      if (this.form.image) {
        data.image = {
          path: this.form.image.path,
          origin_name: this.form.image.origin_name,
          alt: this.form.image.alt,
          size: this.form.image.size,
          type: this.form.image.type,
        };
      }
      
      this.$emit("submit", data);
    },
    onImageUpload() {
      // Callback after image upload
    },
  },
};
</script>

<style lang="stylus">
.merchant-sale-form {
  &__grid {
    display grid
    grid-template-columns 1fr 450px
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
  &__textarea,
  &__select {
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
    
    &--error {
      border-color var(--red)
    }
  }
  
  &__textarea {
    resize vertical
    min-height 80px
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
  
  &__number-wrapper {
    position relative
  }
  
  &__percent {
    position absolute
    right 16px
    top 50%
    transform translateY(-50%)
    color var(--dark-light)
    font-size 0.875rem
  }
  
  &__date-range {
    display flex
    align-items center
    gap 12px
    
    .date-input {
      flex 1
    }
  }
  
  &__date-separator {
    color var(--dark-light)
    flex-shrink 0
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
  }
  
  &__toggle-text {
    font-size 0.875rem
    color var(--dark)
  }
  
  &__image-upload {
    width 100%
    max-width 300px
    
    .image-upload__container {
      width 100%
      height 180px
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
  
  &__modal-overlay {
    position fixed
    top 0
    left 0
    right 0
    bottom 0
    background rgba(0, 0, 0, 0.5)
    display flex
    align-items center
    justify-content center
    z-index 1000
  }
  
  &__modal {
    background var(--white)
    border-radius 12px
    padding 24px
    max-width 400px
    width 90%
    
    h3 {
      font-size 1.125rem
      font-weight 600
      color var(--dark)
      margin-bottom 12px
    }
    
    p {
      font-size 0.875rem
      color var(--dark-light)
      margin-bottom 24px
    }
  }
  
  &__modal-actions {
    display flex
    justify-content flex-end
    gap 12px
  }
}
</style>
