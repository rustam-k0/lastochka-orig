<template>
  <div v-if="!fieldsStore.isEnabled('Shop', 'categories_assembly_time')" class="assembly-map-tab__empty">
    Управление секциями магазина отключено в настройках.
  </div>
  <div v-else class="assembly-map-tab">
    <div class="assembly-map-tab__head">
      <h3 class="assembly-map-tab__heading">Структура магазина для сборщика</h3>
      <p class="assembly-map-tab__subheading">
        Настройте последовательность обхода секций магазина и время сборки в каждой секции
      </p>
    </div>

    <BaseWrapper>
      <template #title> Секции магазина </template>
      <template #actions>
        <button
          class="btn btn--md"
          :disabled="!adding && !leafOptions.length"
          @click="toggleAdd"
        >
          <IconComponent name="add-circle-line" />
          <span>Добавить секцию</span>
        </button>
      </template>
      <template #default>
        <!-- Блок добавления секции -->
        <div v-if="adding" class="assembly-map-tab__add">
          <div class="assembly-map-tab__add-grid">
            <div class="assembly-map-tab__field">
              <span>Секция (категория)</span>
              <SelectComponent
                v-model="newCategory"
                :options="leafOptions"
                placeholder="Выберите категорию"
              />
            </div>
            <div class="assembly-map-tab__field assembly-map-tab__field--time">
              <span>Время сборки, сек</span>
              <InputComponent v-model="newTime" type="number" placeholder="0" />
            </div>
          </div>
          <div class="assembly-map-tab__add-actions">
            <button class="btn btn--md" :disabled="!newCategory || saving" @click="addSection">
              <span>Добавить</span>
            </button>
            <button class="btn btn--white btn--md" :disabled="saving" @click="cancelAdd">
              <span>Отмена</span>
            </button>
          </div>
        </div>

        <!-- Список секций -->
        <StoreCategoryItems
          ref="list"
          :items="sections"
          draggable
          @reload="$emit('reload')"
        />
        <div v-if="!sections.length && !adding" class="assembly-map-tab__empty">
          Секции ещё не добавлены. Нажмите «Добавить секцию».
        </div>
      </template>
    </BaseWrapper>

    <div class="assembly-map-tab__info">
      <div class="assembly-map-tab__info-row">
        <IconComponent name="information-line" />
        <span>Секции формируют маршрут сборщика: он собирает заказ в этом порядке</span>
      </div>
      <div class="assembly-map-tab__info-row">
        <IconComponent name="information-line" />
        <span>Последовательность секций определяет оптимальный маршрут обхода магазина для сборщика</span>
      </div>
      <div class="assembly-map-tab__info-row">
        <IconComponent name="information-line" />
        <span>Время сборки отправляется в мобильное приложение сборщика для тайм-менеджмента</span>
      </div>
    </div>
  </div>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import StoreCategoryItems from "@/views/stores/components/StoreCategoryItems.vue";
import { useFieldVisibilityConfigStore } from "@/stores/fieldVisibilityConfig.js";

export default {
  name: "AssemblyMapTab",
  components: { BaseWrapper, IconComponent, InputComponent, SelectComponent, StoreCategoryItems },
  props: {
    shopCategories: {
      type: Array,
      default: null,
    },
    // Дерево всех категорий (parent_categories из OPTIONS) — источник листовых
    // категорий для добавления секции.
    parentCategories: {
      type: Array,
      default: () => [],
    },
  },
  emits: ["reload"],
  data() {
    return {
      adding: false,
      newCategory: null,
      newTime: null,
      saving: false,
    };
  },
  computed: {
    fieldsStore() {
      return useFieldVisibilityConfigStore();
    },
    sections() {
      return this.shopCategories || [];
    },
    sectionIds() {
      return new Set(this.sections.map((s) => s.id));
    },
    // Листовые категории всего дерева, ещё не входящие в секции магазина.
    leafOptions() {
      const leaves = [];
      const walk = (nodes) => {
        (nodes || []).forEach((node) => {
          if (node.child_categories && node.child_categories.length) {
            walk(node.child_categories);
          } else {
            leaves.push({ id: node.id, title: node.title });
          }
        });
      };
      walk(this.parentCategories);
      return leaves.filter((leaf) => !this.sectionIds.has(leaf.id));
    },
  },
  methods: {
    toggleAdd() {
      if (this.adding) {
        this.cancelAdd();
        return;
      }
      if (!this.leafOptions.length) {
        return;
      }
      this.adding = true;
    },
    cancelAdd() {
      this.adding = false;
      this.newCategory = null;
      this.newTime = null;
    },
    addSection() {
      if (!this.newCategory || this.saving) {
        return;
      }
      const child = this.$refs.list;
      if (!child) {
        return;
      }
      this.saving = true;
      Promise.resolve(child.appendSection(this.newCategory, this.newTime))
        .then((ok) => {
          if (ok) {
            this.cancelAdd();
          }
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.assembly-map-tab {
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

  &__add {
    display flex
    flex-direction column
    gap 16px
    padding 16px
    margin-bottom 16px
    border 1px solid var(--gray-light)
    border-radius 12px
    background var(--gray-3, #F9FBFC)
  }

  &__add-grid {
    display grid
    grid-template-columns 1fr 160px
    gap 16px
    align-items start
  }

  &__field {
    display flex
    flex-direction column
    gap 8px

    > span {
      font-weight 500
      font-size 0.813em
      line-height 18px
    }
  }

  &__add-actions {
    display flex
    gap 8px
  }

  &__empty {
    padding 20px
    color var(--dark-light)
    font-size 0.875rem
    background var(--gray-3, #F9FBFC)
    border-radius 12px
    text-align center
  }

  &__info {
    display flex
    flex-direction column
    gap 8px
    background var(--blue-light, #F0FAFF)
    border 1px solid var(--blue-50, #E6F6FF)
    border-radius 12px
    padding 14px 16px
  }

  &__info-row {
    display flex
    align-items flex-start
    gap 8px
    color var(--blue-600, #0082CC)
    font-size 0.813rem
    line-height 150%

    .icon {
      flex-shrink 0
      width 16px
      height 16px
      margin-top 2px

      svg path {
        fill var(--blue-600, #0082CC)
      }
    }
  }
}

@media (max-width 768px) {
  .assembly-map-tab__add-grid {
    grid-template-columns 1fr
  }
}
</style>
