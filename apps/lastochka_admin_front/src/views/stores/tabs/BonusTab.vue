<template>
  <div class="bonus-tab">
    <div class="bonus-tab__head">
      <h3 class="bonus-tab__heading">Бонусная программа магазина</h3>
      <p class="bonus-tab__subheading">
        Настройте параметры бонусной программы, которая будет доступна покупателям
        при заказах в этом магазине
      </p>
    </div>

    <!-- Блок «Основные настройки» -->
    <BaseWrapper>
      <template #title> Основные настройки </template>
      <template #default>
        <div class="bonus-tab__block">
          <!-- Декоративный селект программы (единственная опция, недоступен для смены) -->
          <div class="bonus-tab__row">
            <span>Бонусная программа</span>
            <SelectComponent
              :model-value="bonusProgram"
              :options="bonusProgramOptions"
              disabled
              :messages="['Единая программа лояльности сети. Применяется ко всем магазинам.']"
            />
          </div>

          <!-- Статус программы -->
          <div class="bonus-tab__row">
            <span>Статус программы</span>
            <FlagCheckboxComponent v-model="form.is_bonus_enabled.value" green>
              Активна
            </FlagCheckboxComponent>
            <span class="bonus-tab__hint">
              Отключите, чтобы временно приостановить начисление и списание бонусов
            </span>
          </div>
        </div>
      </template>
    </BaseWrapper>

    <!-- Блок «Начисление бонусов» -->
    <BaseWrapper>
      <template #title> Начисление бонусов </template>
      <template #default>
        <div class="bonus-tab__grid">
          <div class="bonus-tab__row">
            <span>Начислять бонусы за заказы, %</span>
            <InputComponent
              v-model.number="form.bonus_earn_percent.value"
              :errors="form.bonus_earn_percent.errors"
              :disabled="!form.is_bonus_enabled.value"
              placeholder="0–100"
              type="number"
            />
          </div>
          <div class="bonus-tab__row">
            <span>Списывать бонусами до, %</span>
            <InputComponent
              v-model.number="form.bonus_spend_percent.value"
              :errors="form.bonus_spend_percent.errors"
              :disabled="!form.is_bonus_enabled.value"
              placeholder="0–100"
              type="number"
              :messages="['Максимальный процент оплаты заказа бонусами']"
            />
          </div>
        </div>
      </template>
    </BaseWrapper>
  </div>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";

export default {
  name: "BonusTab",
  components: { BaseWrapper, InputComponent, FlagCheckboxComponent, SelectComponent },
  props: {
    form: {
      type: Object,
      required: true,
    },
  },
  data() {
    return {
      // Декоративный селект: сейчас доступна единственная программа сети.
      bonusProgram: { id: 1, title: "Стандартная программа сети" },
      bonusProgramOptions: [{ id: 1, title: "Стандартная программа сети" }],
    };
  },
};
</script>

<style lang="stylus">
.bonus-tab {
  display flex
  flex-direction column
  gap 16px

  &__head {
    display flex
    flex-direction column
    gap 4px
  }

  &__heading {
    margin 0
    font-family var(--font-bold)
    font-weight 500
    font-size 1.125rem
    line-height 25px
    color var(--dark)
  }

  &__subheading {
    margin 0
    font-weight 400
    font-size 0.875rem
    line-height 140%
    color var(--black-2)
  }

  &__block {
    display flex
    flex-direction column
    gap 16px
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

  &__hint {
    color var(--gray-dark-2, #8C8C8C)
    font-size 0.8125em
    line-height 140%
  }
}

@media (max-width 768px) {
  .bonus-tab__grid {
    grid-template-columns 1fr
  }
}
</style>
