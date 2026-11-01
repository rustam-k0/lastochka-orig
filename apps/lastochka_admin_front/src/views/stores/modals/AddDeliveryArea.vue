<template>
  <div class="default-modal delivery-modal">
    <div class="default-modal__header">
      <span class="default-modal__title">{{ form.name.value || 'Зона ' + zoneIndex }}</span>
      <button type="button" class="default-modal__close" @click="$emit('close')">
        <IconComponent name="close-fill" />
      </button>
    </div>
    <div class="default-modal__body">
      <InputComponent
        v-model="form.name.value"
        :errors="form.name.errors"
        title="Название зоны"
        :placeholder="'Зона ' + zoneIndex"
      />
      <!--      <FileUploadComponent v-model="form.geojson.value" text="Загрузите файл" />-->
      <div class="delivery-modal__wrapper">
        <div class="delivery-modal__file-wrapper">
          <div class="delivery-modal__file">
            <input title=" " class="file" type="file" @change="changeFile($event.target.files)" />
            <span>{{ form.geojson.value || fileSelected ? "Файл загружен" : "Выберите файл" }}</span>
          </div>
          <span>*</span>
        </div>
        <span v-if="form.geojson.errors.length" class="delivery-modal__error">Загрузите файл</span>
      </div>
      <ZoneMapPreview
        v-if="zoneCoordinates.length"
        :coordinates="zoneCoordinates"
      />
      <div class="delivery-modal__form">
        <div class="input-component">
          <label class="input-component__label">Тип доставки</label>
          <select
            :value="form.delivery_type.value"
            class="input-component__input"
            @change="changeDeliveryType($event.target.value)"
          >
            <option value="own">Свои курьеры</option>
            <option value="yandex">Яндекс Доставка</option>
          </select>
        </div>
        <InputComponent
          v-model="form.delivery_price.value"
          :errors="form.delivery_price.errors"
          title="Цена доставки"
          placeholder="Цена"
          required
        />
        <InputComponent
          v-model="form.min_order_price.value"
          :errors="form.min_order_price.errors"
          title="Минимальная сумма заказа для доставки"
          placeholder="Цена"
          required
        />
        <InputComponent
          v-if="isYandex || !deliveryPriceRangesEnabled"
          v-model="form.free_delivery_from.value"
          :errors="form.free_delivery_from.errors"
          title="Сумма для бесплатной доставки"
          placeholder="Сумма"
        />
        <InputComponent
          v-model="form.delivery_lead_minutes.value"
          :errors="form.delivery_lead_minutes.errors"
          type="number"
          :min="0"
          title="Минимальное время до доставки, минут"
          placeholder="Минуты"
          :messages="['Слот станет доступен не раньше, чем через указанное число минут от текущего момента (например, 30 или 90). Пусто — без задержки']"
        />
      </div>
      <div v-if="!isYandex && deliveryPriceRangesEnabled" class="delivery-modal__ranges">
        <span class="delivery-modal__ranges-title">Стоимость доставки от суммы заказа</span>
        <DeliveryPriceRangesEditor
          v-model="form.delivery_price_ranges.value"
          :errors="form.delivery_price_ranges.errors"
        />
      </div>
      <div class="delivery-modal__active">
        <FlagCheckboxComponent
          green
          :model-value="form.is_active.value"
          @update:model-value="onActiveToggle"
        >
          Зона активна
        </FlagCheckboxComponent>
        <span class="delivery-modal__active-hint">
          Неактивная зона не участвует в расчёте доставки на витрине.
        </span>
      </div>
      <div class="delivery-modal__days">
        <label class="delivery-modal__days-title">Дни недели доставки</label>
        <div class="delivery-modal__days-list">
          <label
            v-for="day in weekDays"
            :key="day.value"
            class="delivery-modal__day"
            :class="{ 'delivery-modal__day--active': form.delivery_days.value.includes(day.value) }"
          >
            <input
              type="checkbox"
              :value="day.value"
              v-model="form.delivery_days.value"
            />
            <span>{{ day.label }}</span>
          </label>
        </div>
        <span class="delivery-modal__days-hint">Если ничего не выбрано — доставка во все дни</span>
      </div>
    </div>
    <div class="default-modal__footer">
      <button v-if="id" @click="remove" class="btn btn--md btn--red" :disabled="removing">
        Удалить зону
      </button>
      <button @click="submit" class="btn btn--md delivery-modal__btn">Подтвердить</button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import UPDATE_DELIVERY from "@/graphql/pages/Stores/ShopZoneCreteOrUpdate.graphql";
import DELETE_ZONE from "@/graphql/pages/Stores/ShopZoneDelete.graphql";
import GET from "@/graphql/pages/Stores/shop_zone.graphql";
import { useDefaultStore } from "@/stores/default.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { parseGqlErrors, resetErrors } from "@/utils.js";
import ZoneMapPreview from "@/components/maps/ZoneMapPreview.vue";
import DeliveryPriceRangesEditor from "@/views/stores/components/DeliveryPriceRangesEditor.vue";
import {
  INVALID_RUBLES_MESSAGE,
  deliveryPriceRangeRow,
  deliveryPriceRangeRows,
  freeDeliveryFromRows,
  parseRubles,
  zoneDeliveryPriceRanges,
} from "@/utils/deliveryPriceRanges.js";

export default {
  name: "AddDeliveryArea",
  inject: ["apollo"],
  components: {
    InputComponent,
    IconComponent,
    ZoneMapPreview,
    FlagCheckboxComponent,
    DeliveryPriceRangesEditor,
  },
  props: {
    id: Number,
    shop_id: Number,
    type_id: Number,
    zoneIndex: Number,
    zoneName: String,
    callback: Function,
  },
  data() {
    return {
      fileSelected: null,
      removing: false,
      zoneCoordinates: [],
      // is_active шлём в мутацию ТОЛЬКО если пользователь трогал тогглер (иначе не затираем —
      // явный null для is_active на бэке трактуется как false).
      isActiveTouched: false,
      form: {
        name: {
          value: this.zoneName || null,
          errors: [],
        },
        geojson: {
          value: null,
          errors: [],
        },
        delivery_price: {
          value: null,
          errors: [],
        },
        min_order_price: {
          value: null,
          errors: [],
        },
        // Поле «Сумма для бесплатной доставки» только у Яндекс Доставки (MID-280): уходит
        // в мутацию одной бесплатной ступенью delivery_price_ranges
        free_delivery_from: {
          value: null,
          errors: [],
        },
        // Строки редактора диапазонов у своих курьеров (MID-280)
        delivery_price_ranges: {
          value: [],
          errors: [],
        },
        delivery_type: {
          value: 'own',
          errors: [],
        },
        delivery_days: {
          value: [],
          errors: [],
        },
        delivery_lead_minutes: {
          value: null,
          errors: [],
        },
        is_active: {
          value: true,
          errors: [],
        },
      },
      weekDays: [
        { value: 1, label: 'Пн' },
        { value: 2, label: 'Вт' },
        { value: 3, label: 'Ср' },
        { value: 4, label: 'Чт' },
        { value: 5, label: 'Пт' },
        { value: 6, label: 'Сб' },
        { value: 7, label: 'Вс' },
      ],
    };
  },
  computed: {
    isYandex() {
      return this.form.delivery_type.value === "yandex";
    },
    // Модуль delivery_price_ranges (MID-280): выключен — редактора диапазонов нет, поле «Сумма
    // для бесплатной доставки» видно у обоих типов доставки, мутация ступени не шлёт (submit()).
    deliveryPriceRangesEnabled() {
      return useModuleConfigStore().isModuleEnabled("delivery_price_ranges");
    },
  },
  mounted() {
    this.get();
  },
  methods: {
    resetErrors,
    parseGqlErrors,
    get() {
      if (this.id) {
        this.apollo()
          .admin.query({
            query: GET,
            variables: {
              id: this.id,
              shop_id: this.shop_id,
            },
          })
          .then(({ data }) => {
            if (data.shop_zone) {
              this.form.name.value = data.shop_zone.name || null;
              this.form.delivery_price.value = data.shop_zone.delivery_price;
              this.form.min_order_price.value = data.shop_zone.min_order_price;
              const ranges = zoneDeliveryPriceRanges(data.shop_zone);
              const freeRange = ranges.find((range) => range.price === 0);
              this.form.free_delivery_from.value = freeRange ? String(freeRange.from_amount) : "";
              this.form.delivery_price_ranges.value =
                data.shop_zone.delivery_type === "yandex" ? [] : deliveryPriceRangeRows(ranges);
              this.form.delivery_type.value = data.shop_zone.delivery_type || 'own';
              this.form.delivery_days.value = data.shop_zone.delivery_days || [];
              this.form.delivery_lead_minutes.value = data.shop_zone.delivery_lead_minutes ?? null;
              this.form.is_active.value = data.shop_zone.is_active !== false;
              this.fileSelected = data.shop_zone.coordinates_loaded;
              this.zoneCoordinates = data.shop_zone.coordinates || [];
            }
          })
          .catch(() => {
            console.log("error");
          });
      }
    },
    submit() {
      this.resetErrors();
      const variables = {
        id: this.id,
        name: this.form.name.value || null,
        shop_id: this.shop_id,
        type_id: this.type_id,
        geojson: this.form.geojson.value,
        delivery_price: parseInt(this.form.delivery_price.value),
        min_order_price: parseInt(this.form.min_order_price.value),
        delivery_type: this.form.delivery_type.value,
        delivery_days: this.form.delivery_days.value,
        delivery_lead_minutes: this.form.delivery_lead_minutes.value !== null && this.form.delivery_lead_minutes.value !== '' ? parseInt(this.form.delivery_lead_minutes.value) : null,
      };
      // Модуль delivery_price_ranges выключен (MID-280): ведём себя как до фичи — шлём
      // free_delivery_from, ключ delivery_price_ranges не отправляем вовсе.
      if (this.deliveryPriceRangesEnabled) {
        const deliveryPriceRanges = this.buildDeliveryPriceRangesInput();
        // Диапазоны с не целыми числами: ошибки уже показаны у полей, запрос не уходит.
        if (deliveryPriceRanges === null) return;
        // free_delivery_from не отправляем: при переданном delivery_price_ranges бэк
        // вычисляет порог бесплатной доставки сам.
        variables.delivery_price_ranges = deliveryPriceRanges;
      } else {
        variables.free_delivery_from =
          this.form.free_delivery_from.value !== null && this.form.free_delivery_from.value !== ""
            ? parseInt(this.form.free_delivery_from.value)
            : null;
      }
      // Стоимость сборки зоны в АП скрыта (MID-216): параметр не отправляем —
      // бэк обновляет assembly_price только при наличии ключа, существующие
      // значения зон не затираются.
      // is_active — только если тогглер реально меняли (иначе не передаём, чтобы не затереть).
      if (this.isActiveTouched) {
        variables.is_active = this.form.is_active.value;
      }
      this.apollo()
        .admin.mutate({
          mutation: UPDATE_DELIVERY,
          variables,
        })
        .then(({ data }) => {
          if (data.ShopZoneCreteOrUpdate.code === 500) {
            alert("Выберите файл формата: .geojson");
          } else {
            useDefaultStore().modals = [];
          }
          this.callback();
        })
        .catch(({ graphQLErrors }) => {
          this.parseGqlErrors(this.remapDeliveryPriceRangeErrors(graphQLErrors ?? []));
          console.log("error");
        });
    },
    // Смена типа доставки (MID-280). У Яндекс Доставки цену называет Яндекс, поэтому из диапазонов
    // остаётся только бесплатный: он переезжает в поле «Сумма для бесплатной доставки» и обратно
    // одной строкой редактора; платные диапазоны при переходе на Яндекс снимаются.
    changeDeliveryType(type) {
      if (type === this.form.delivery_type.value) return;
      // Модуль delivery_price_ranges выключен: поле «Сумма для бесплатной доставки» ведёт себя
      // как до фичи — не переносится и не пересобирается при смене типа доставки.
      if (this.deliveryPriceRangesEnabled) {
        if (type === "yandex") {
          this.form.free_delivery_from.value = freeDeliveryFromRows(this.form.delivery_price_ranges.value);
          this.form.delivery_price_ranges.value = [];
        } else {
          const freeFrom = parseRubles(this.form.free_delivery_from.value);
          this.form.delivery_price_ranges.value =
            freeFrom === null ? [] : [deliveryPriceRangeRow(String(freeFrom), "0")];
        }
        this.form.free_delivery_from.errors = [];
        this.form.delivery_price_ranges.errors = [];
      }
      this.form.delivery_type.value = type;
    },
    // Аргумент delivery_price_ranges (MID-280). Свои курьеры: строки редактора; полностью пустые
    // строки убираются из самого поля, а заполненные сортируются в нём по сумме, чтобы индексы
    // совпали с ключами ошибок сервера delivery_price_ranges.N.* (бэк хранит ступени по возрастанию
    // суммы). Яндекс Доставка: одна бесплатная ступень из поля «Сумма для бесплатной доставки»,
    // пустое поле = []. Не целое число: ошибка у поля и null (запрос не уходит, иначе сервер
    // ответил бы ошибкой приведения типа Int!, которую не разложить по полям).
    buildDeliveryPriceRangesInput() {
      if (this.isYandex) {
        if (String(this.form.free_delivery_from.value ?? "").trim() === "") return [];
        const fromAmount = parseRubles(this.form.free_delivery_from.value);
        if (fromAmount === null) {
          this.form.free_delivery_from.errors = [INVALID_RUBLES_MESSAGE];
          return null;
        }
        return [{ from_amount: fromAmount, price: 0 }];
      }

      const field = this.form.delivery_price_ranges;
      field.value = field.value.filter(
        (row) =>
          String(row.from_amount.value ?? "").trim() !== "" || String(row.price.value ?? "").trim() !== ""
      );

      let valid = true;
      field.value.forEach((row) => {
        row.from_amount.errors = parseRubles(row.from_amount.value) === null ? [INVALID_RUBLES_MESSAGE] : [];
        row.price.errors = parseRubles(row.price.value) === null ? [INVALID_RUBLES_MESSAGE] : [];
        if (row.from_amount.errors.length || row.price.errors.length) valid = false;
      });
      if (!valid) return null;

      field.value = [...field.value].sort(
        (a, b) => parseRubles(a.from_amount.value) - parseRubles(b.from_amount.value)
      );
      return field.value.map((row) => ({
        from_amount: parseRubles(row.from_amount.value),
        price: parseRubles(row.price.value),
      }));
    },
    // Ключи ошибок delivery_price_ranges.* под поля формы (MID-280). У Яндекс Доставки ступень одна и
    // введена полем free_delivery_from. У своих курьеров ключ без строки редактора или без поля строки
    // (например, delivery_price_ranges.3) уходит в общую ошибку редактора: системный parseGqlErrors
    // упал бы на несуществующей строке.
    remapDeliveryPriceRangeErrors(graphQLErrors) {
      const rows = this.form.delivery_price_ranges.value;
      const target = (key) => {
        if (key !== "delivery_price_ranges" && !key.startsWith("delivery_price_ranges.")) return key;
        if (this.isYandex) return "free_delivery_from";
        const [, index, child] = key.split(".");
        return rows[index] && (child === "from_amount" || child === "price") ? key : "delivery_price_ranges";
      };

      return graphQLErrors.map((err) => {
        if (err.extensions?.category !== "validation") return err;
        const validation = {};
        Object.entries(err.extensions.validation).forEach(([key, messages]) => {
          const name = target(key);
          validation[name] = [...(validation[name] ?? []), ...messages];
        });
        return { ...err, extensions: { ...err.extensions, validation } };
      });
    },
    changeFile(file) {
      this.form.geojson.value = file[0];
    },
    onActiveToggle(val) {
      this.form.is_active.value = val;
      this.isActiveTouched = true;
    },
    remove() {
      const name = this.form.name.value || "Зона " + this.zoneIndex;
      if (!confirm(`Удалить зону «${name}»?`)) return;
      this.removing = true;
      this.apollo()
        .admin.mutate({
          mutation: DELETE_ZONE,
          variables: {
            id: this.id,
          },
        })
        .then(() => {
          useDefaultStore().modals = [];
          this.callback();
        })
        .catch(({ graphQLErrors }) => {
          this.parseGqlErrors(graphQLErrors);
        })
        .finally(() => {
          this.removing = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.delivery-modal {
  width 100%
  max-width 1132px

  &__form {
    display flex
    gap 16px
    width 60%
  }

  &__btn {
    margin-left auto
  }

  & .btn--red {
    background var(--red, #e53935)
    color var(--white)

    &:hover:not(:disabled) {
      background var(--red-dark, #c62828)
    }
  }

  &__file {
    width 170px
    height 35px
    border-radius: 8px;
    background: var(--gray-5);
    position: relative;
    overflow: hidden;
    cursor pointer
    display flex
    justify-content center

    & .file {
      opacity 0
      height 100%
      z-index 2
    }

    &-wrapper {
      display flex
      gap 4px

      & > span {
        color var(--red)
      }
    }

    & span {
      position absolute
      top 0
      line-height 35px
    }
  }

  &__error {
    color var(--red)
    font-weight: 400;
    font-size: 0.8125em;
    line-height: 20px;
  }

  &__wrapper {
    display flex
    gap 4px
    flex-direction column
  }

  &__days {
    display flex
    flex-direction column
    gap 8px
    margin-top 16px
  }

  &__days-title {
    font-weight 500
  }

  &__days-list {
    display flex
    gap 8px
    flex-wrap wrap
  }

  &__day {
    display flex
    align-items center
    gap 6px
    padding 6px 12px
    border 1px solid var(--gray-dark-3, #D1D1D1)
    border-radius 8px
    cursor pointer
    user-select none

    &--active {
      border-color var(--primary, #2D9CDB)
    }

    & input {
      cursor pointer
    }
  }

  &__days-hint {
    color var(--gray-dark-2, #8c8c8c)
    font-size 0.8125em
  }

  &__ranges {
    display flex
    flex-direction column
    gap 8px
    width 60%
    margin-top 16px
  }

  &__ranges-title {
    font-weight 500
  }

  &__active {
    display flex
    align-items center
    gap 12px
    margin-top 16px
    flex-wrap wrap
  }

  &__active-hint {
    color var(--gray-dark-2, #8c8c8c)
    font-size 0.8125em
  }
}
</style>
