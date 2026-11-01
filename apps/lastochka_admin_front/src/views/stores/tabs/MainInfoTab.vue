<template>
  <BaseWrapper>
    <template #title> Основная информация </template>
    <template #default>
      <div class="main-info-tab">
        <!-- Изображения -->
        <div class="main-info-tab__images">
          <div v-if="fieldsStore.isEnabled('Shop', 'img')" class="main-info-tab__row">
            <span>Фото</span>
            <ImageUploadComponent v-model="form.img.value" text="Загрузить фото" />
          </div>
          <div v-if="fieldsStore.isEnabled('Shop', 'logo_img')" class="main-info-tab__row">
            <span>Логотип</span>
            <ImageUploadComponent v-model="form.logo_img.value" text="Загрузить фото" />
          </div>
          <div v-if="fieldsStore.isEnabled('Shop', 'background_img')" class="main-info-tab__row">
            <span>Задний фон</span>
            <ImageUploadComponent v-model="form.background_img.value" text="Загрузить фото" />
          </div>
        </div>

        <div class="main-info-tab__grid">
          <!-- Код магазина (1С) — всегда readonly -->
          <div v-if="isAdmin" class="main-info-tab__row">
            <span>Код магазина (1С)</span>
            <InputComponent
              v-model="form.code.value"
              :errors="form.code.errors"
              :disabled="true"
              placeholder="Из 1С"
              :messages="['Поле синхронизируется из 1С и недоступно для редактирования']"
            />
          </div>

          <!-- Название -->
          <div class="main-info-tab__row">
            <span>Название <span class="main-info-tab__required">*</span></span>
            <InputComponent
              v-model="form.title.value"
              :errors="form.title.errors"
              placeholder="Название"
              required
            />
          </div>

          <!-- Тип магазина (скрывается в «Настройки → Поля»: на mendev тип не ведётся, MID-306) -->
          <div v-if="isAdmin && fieldsStore.isEnabled('Shop', 'type_id')" class="main-info-tab__row">
            <span>Тип магазина <span class="main-info-tab__required">*</span></span>
            <SelectComponent
              v-model="form.type_id.value"
              :errors="form.type_id.errors"
              :options="typeOptions"
              :disabled="readonlyEdit"
              placeholder="Выберите тип заведения"
              required
            />
          </div>

          <!-- Город -->
          <div v-if="isAdmin" class="main-info-tab__row">
            <span>Город</span>
            <SelectComponent
              :model-value="selectedCity"
              @update:model-value="setCity"
              :errors="form.city_id.errors"
              :options="cities"
              label-name="name"
              placeholder="Выберите город"
              clearable
            />
          </div>

          <!-- Магазин по умолчанию в городе: без города флаг бессмыслен, поэтому
               вместо неактивного чекбокса показываем подсказку -->
          <div v-if="isAdmin" class="main-info-tab__row">
            <span>Магазин по умолчанию</span>
            <CheckboxComponent
              v-if="form.city_id.value"
              v-model="form.is_default.value"
              :errors="form.is_default.errors"
              green
            >
              Магазин по умолчанию в городе
            </CheckboxComponent>
            <span v-else class="main-info-tab__hint">Доступно после выбора города</span>
          </div>

          <!-- Статус магазина -->
          <div v-if="isAdmin && isEdit" class="main-info-tab__row">
            <span>Статус магазина</span>
            <CheckboxComponent v-model="isActive" green>
              {{ isActive ? 'Активен' : 'Неактивен' }}
            </CheckboxComponent>
          </div>

          <!-- Адрес -->
          <div v-if="fieldsStore.isEnabled('Shop', 'address')" class="main-info-tab__row main-info-tab__row--full">
            <span>Адрес</span>
            <InputComponent v-model="form.address.value" :errors="form.address.errors" placeholder="Адрес" />
          </div>

          <!-- Телефон -->
          <div v-if="fieldsStore.isEnabled('Shop', 'phone')" class="main-info-tab__row">
            <span>Телефон</span>
            <InputComponent
              v-model="form.phone.value"
              :errors="form.phone.errors"
              mask="+7 (###) ### ## ##"
              placeholder="Телефон"
            />
          </div>

          <!-- Email -->
          <div v-if="fieldsStore.isEnabled('Shop', 'email')" class="main-info-tab__row">
            <span>Почта</span>
            <InputComponent
              v-model="form.email.value"
              :errors="form.email.errors"
              placeholder="email"
              type="email"
            />
          </div>

          <!-- Площадь магазина: ручной управленческий ввод, из 1С не синхронизируется -->
          <div v-if="isAdmin" class="main-info-tab__row">
            <span>Площадь магазина, м²</span>
            <InputComponent
              v-model.number="form.area_sqm.value"
              :errors="form.area_sqm.errors"
              type="number"
              placeholder="Например: 120"
            />
          </div>

          <!-- Описание -->
          <div v-if="fieldsStore.isEnabled('Shop', 'description')" class="main-info-tab__row main-info-tab__row--full">
            <div class="main-info-tab__label-row">
              <span>Описание</span>
              <span class="main-info-tab__counter">{{ descriptionLength }} / 500</span>
            </div>
            <TextareaComponent
              v-model="form.description.value"
              :errors="form.description.errors"
              :maxlength="500"
              placeholder="Описание"
            />
          </div>

          <!-- Координаты -->
          <div class="main-info-tab__row">
            <span>Широта</span>
            <InputComponent
              v-model.number="form.latitude.value"
              :errors="form.latitude.errors"
              type="number"
              placeholder="с.ш"
            />
          </div>
          <div class="main-info-tab__row">
            <span>Долгота</span>
            <InputComponent
              v-model.number="form.longitude.value"
              :errors="form.longitude.errors"
              type="number"
              placeholder="в.д"
            />
          </div>

          <!-- Слоты. Модуль «Оформление заказа без слотов» (MID-256): настройки
               слотов доставки не нужны — «Количество заказов на слот» скрыто,
               значение при сохранении уходит как есть (ShopUpdate требует
               slot_time/slot_max_orders для админа). «Временной слот» остаётся:
               им нарезаются слоты самовывоза (stores.slot_duration на витрине) -->
          <div v-if="isAdmin" class="main-info-tab__row">
            <span>Временной слот (мин)</span>
            <InputComponent
              v-model.number="form.slot_time.value"
              :errors="form.slot_time.errors"
              placeholder="В минутах"
              type="number"
            />
          </div>
          <div v-if="isAdmin && !moduleStore.isModuleEnabled('checkout_without_slots')" class="main-info-tab__row">
            <span>Количество заказов на слот</span>
            <InputComponent
              v-model.number="form.slot_max_orders.value"
              :errors="form.slot_max_orders.errors"
              placeholder="Заказы на 1 слот"
              type="number"
            />
          </div>

          <!-- График работы (текст) -->
          <div v-if="fieldsStore.isEnabled('Shop', 'schedule')" class="main-info-tab__row main-info-tab__row--full">
            <span>График работы</span>
            <InputComponent
              v-model="form.schedule.value"
              :errors="form.schedule.errors"
              placeholder="График работы"
            />
          </div>

          <!-- Структурированный график работы (только админ, редактирование) -->
          <template v-if="isAdmin && isEdit">
            <div class="main-info-tab__row main-info-tab__row--full">
              <span>Часы работы</span>
              <div class="main-info-tab__from-to-wrapper">
                <div class="main-info-tab__from-to">
                  <span>с</span>
                  <InputComponent
                    :disabled="form.twentyFourSeven.value"
                    mask="##:##"
                    v-model="form.opening_time.value"
                    :errors="form.opening_time.errors"
                  />
                  <span>до</span>
                  <InputComponent
                    :disabled="form.twentyFourSeven.value"
                    mask="##:##"
                    v-model="form.closing_time.value"
                    :errors="form.closing_time.errors"
                  />
                </div>
                <CheckboxComponent v-model="form.twentyFourSeven.value" big green>
                  Круглосуточно
                </CheckboxComponent>
              </div>
            </div>
            <div class="main-info-tab__row main-info-tab__row--full">
              <span>Дни работы</span>
              <div>
                <WorkDaysComponent v-model="form.working_days.value" checkbox />
                <div v-if="form.working_days.errors?.length" class="input__errors">
                  <span v-for="(error, i) in form.working_days.errors" :key="i">{{ error }}</span>
                </div>
              </div>
            </div>
          </template>

          <!-- Категории -->
          <div v-if="isAdmin" class="main-info-tab__row main-info-tab__row--full">
            <span>Категории, отображаемые для клиента</span>
            <CategoryTreePanel
              v-model="form.categories.value"
              :errors="form.categories.errors"
              :options="parentCategories"
            />
          </div>

          <!-- Тяжёлый заказ -->
          <div
            v-if="isAdmin && (moduleStore.isModuleEnabled('heavy_orders') || moduleStore.isModuleEnabled('courier_assignment'))"
            class="main-info-tab__row"
          >
            <span>Тяжёлый заказ (кг)</span>
            <InputComponent
              v-model.number="form.heavy_order_threshold.value"
              :errors="form.heavy_order_threshold.errors"
              placeholder="Порог веса, напр. 30"
              type="number"
            />
          </div>

          <!-- B2B -->
          <div
            v-if="isAdmin && moduleStore.isModuleEnabled('b2b') && fieldsStore.isEnabled('Shop', 'is_b2b')"
            class="main-info-tab__row main-info-tab__row--full"
          >
            <span>Тип магазина</span>
            <CheckboxComponent v-model="form.is_b2b.value" green> B2B-магазин </CheckboxComponent>
          </div>

          <!-- Признаки магазина: чекбоксы потерялись при переносе карточки на вкладки (d195b798) -->
          <div
            v-if="
              isAdmin &&
              (fieldsStore.isEnabled('Shop', 'is_popular') || fieldsStore.isEnabled('Shop', 'ignore_stock'))
            "
            class="main-info-tab__row main-info-tab__row--full"
          >
            <span>Признаки магазина</span>
            <div class="main-info-tab__checkboxes">
              <CheckboxComponent
                v-if="fieldsStore.isEnabled('Shop', 'is_popular')"
                v-model="form.is_popular.value"
                green
              >
                Популярный
              </CheckboxComponent>
              <CheckboxComponent
                v-if="fieldsStore.isEnabled('Shop', 'ignore_stock')"
                v-model="form.ignore_stock.value"
                green
              >
                Не учитывать остатки
              </CheckboxComponent>
            </div>
          </div>

          <div class="main-info-tab__row main-info-tab__row--full">
            <CustomCheckboxesField entity="Shop" v-model="form.custom_checkboxes.value" />
          </div>
        </div>
      </div>
    </template>
  </BaseWrapper>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import TextareaComponent from "@/components/inputs/TextareaComponent.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import CategoryTreePanel from "@/components/inputs/CategoryTreePanel.vue";
import ImageUploadComponent from "@/components/ImageUpload.vue";
import WorkDaysComponent from "@/views/stores/components/WorkDaysComponent.vue";
import CustomCheckboxesField from "@/components/inputs/CustomCheckboxesField.vue";
import { useFieldVisibilityConfigStore } from "@/stores/fieldVisibilityConfig.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";

export default {
  name: "MainInfoTab",
  components: {
    BaseWrapper,
    InputComponent,
    SelectComponent,
    TextareaComponent,
    CheckboxComponent,
    CategoryTreePanel,
    ImageUploadComponent,
    WorkDaysComponent,
    CustomCheckboxesField,
  },
  props: {
    form: {
      type: Object,
      required: true,
    },
    isAdmin: {
      type: Boolean,
      default: false,
    },
    isEdit: {
      type: Boolean,
      default: false,
    },
    typeOptions: {
      type: Array,
      default: () => [],
    },
    parentCategories: {
      type: Array,
      default: () => [],
    },
    cities: {
      type: Array,
      default: () => [],
    },
  },
  methods: {
    // Город снят — снимаем и «магазин по умолчанию»: без города флаг не имеет смысла
    // и backend отклонит его валидацией.
    setCity(city) {
      this.form.city_id.value = city ? city.id : null;
      if (!city) {
        this.form.is_default.value = false;
      }
    },
  },
  computed: {
    selectedCity() {
      return this.cities.find((city) => city.id === this.form.city_id.value) || null;
    },
    fieldsStore() {
      return useFieldVisibilityConfigStore();
    },
    moduleStore() {
      return useModuleConfigStore();
    },
    // Тип магазина фиксируется после создания — только для чтения при редактировании админом.
    readonlyEdit() {
      return this.isAdmin && this.isEdit;
    },
    descriptionLength() {
      return (this.form.description.value || "").length;
    },
    isActive: {
      get() {
        return this.form.status_id.value === 1;
      },
      set(val) {
        // Контракт с backend: 1 = активен, 3 = неактивен.
        this.form.status_id.value = val ? 1 : 3;
      },
    },
  },
};
</script>

<style lang="stylus">
.main-info-tab {
  display flex
  flex-direction column
  gap 20px

  &__images {
    display grid
    grid-template-columns repeat(3, 1fr)
    gap 16px

    .image-upload__container {
      width 100%
    }
  }

  &__grid {
    display grid
    grid-template-columns 1fr 1fr
    gap 16px
  }

  &__row {
    display flex
    flex-direction column
    gap 8px

    > span {
      font-weight 500
      font-size 0.813em
      line-height 18px
    }
  }

  &__row--full {
    grid-column 1 / -1
  }

  &__checkboxes {
    display flex
    flex-wrap wrap
    align-items center
    gap 16px
  }

  &__label-row {
    display flex
    align-items center
    justify-content space-between
  }

  &__counter {
    font-weight 400
    font-size 0.75rem
    color var(--dark-light)
  }

  &__required {
    color var(--red)
  }

  &__hint {
    font-weight 400
    font-size 0.813rem
    color var(--dark-light)
  }

  &__from-to-wrapper {
    display flex
    align-items center
    gap 26px
  }

  &__from-to {
    display flex
    align-items center
    gap 16px

    .checkbox__field {
      gap 7px
    }

    .input {
      max-width 74.5px
    }
  }
}

@media (max-width 720px) {
  .main-info-tab__grid {
    grid-template-columns 1fr
  }

  .main-info-tab__images {
    grid-template-columns 1fr
  }
}
</style>
