<template>
  <div class="mod categories-position-modal">
    <div class="mod__header">
      <span class="mod__title">{{ title }}</span>
      <button class="mod__close" @click="$emit('close')">
        <IconComponent name="close-line" />
      </button>
    </div>

    <div class="mod__body categories-position-modal__body">
      <div v-if="!items.length" class="categories-position-modal__status">
        Нет категорий для упорядочивания
      </div>

      <draggable v-else class="categories-position-modal__list" v-model="items" transition="100">
        <template v-slot:item="{ item }">
          <div class="categories-position-modal__item">
            <span class="categories-position-modal__number">{{ items.indexOf(item) + 1 }}</span>
            <IconComponent name="menu-line" />
            <span class="categories-position-modal__title">{{ item.title }}</span>
          </div>
        </template>
      </draggable>
    </div>

    <div class="mod__footer">
      <button class="btn btn--white btn--md" @click="$emit('close')">Отмена</button>
      <button
        class="btn btn--md"
        :class="{ 'btn--loading': saving }"
        :disabled="saving || !items.length"
        @click="submit"
      >
        <span>Сохранить</span>
      </button>
    </div>
  </div>
</template>

<script>
import Draggable from "vue3-draggable";
import IconComponent from "@/components/IconComponent.vue";
import SUBMIT from "@/graphql/pages/Categories/CategoryChangePosition.graphql";

export default {
  name: "CategoriesChangePositionModal",

  inject: ["apollo"],

  components: {
    Draggable,
    IconComponent,
  },

  props: {
    // Массив категорий одного уровня: [{ id, title, position }]
    data: {
      type: Array,
      default: () => [],
    },
    title: {
      type: String,
      default: "Изменить порядок категорий",
    },
    callback: Function,
  },

  data() {
    return {
      // копия, чтобы не мутировать дерево родителя до сохранения
      items: this.data.map((item) => ({ ...item })),
      saving: false,
    };
  },

  methods: {
    submit() {
      if (!this.items.length) return;
      this.saving = true;
      const items = this.items.map((item, index) => ({ id: item.id, position: index }));
      this.apollo()
        .admin.mutate({
          mutation: SUBMIT,
          variables: { items },
        })
        .then(() => {
          if (this.callback) {
            this.callback();
          }
          this.saving = false;
          this.$emit("close");
        })
        .catch((error) => {
          this.saving = false;
          console.error("CategoryChangePosition error:", error);
          this.$emit("close");
        });
    },
  },
};
</script>

<style lang="stylus">
.categories-position-modal
  display flex
  flex-direction column
  width 100%
  max-width 480px

  &__body
    max-height 60vh
    overflow-y auto

  &__status
    padding 24px
    text-align center
    color var(--dark-o50)

  &__list
    display flex
    flex-direction column
    gap 8px

  &__item
    display flex
    align-items center
    gap 10px
    cursor grab
    background var(--gray-6)
    padding 8px 10px
    border-radius 6px

    .icon
      width 18px
      height 18px
      flex-shrink 0

  &__number
    display inline-flex
    align-items center
    justify-content center
    min-width 24px
    height 24px
    padding 0 6px
    border-radius 12px
    background var(--gray-dark-3)
    color var(--dark)
    font-size 0.8125rem
    font-weight 600
    line-height 1
    flex-shrink 0

  &__title
    font-size 0.9375rem
    color var(--dark)
</style>
