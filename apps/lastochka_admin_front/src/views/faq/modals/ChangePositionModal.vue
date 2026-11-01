<template>
  <div class="mod faq-position-modal">
    <div class="mod__header">
      <span class="mod__title">Изменить порядок вопросов</span>
      <button class="mod__close" @click="$emit('close')">
        <IconComponent name="close-line" />
      </button>
    </div>

    <div class="mod__body faq-position-modal__body">
      <div v-if="loading" class="faq-position-modal__status">Загрузка...</div>

      <div v-else-if="!items.length" class="faq-position-modal__status">Нет вопросов для упорядочивания</div>

      <draggable v-else class="faq-position-modal__list" v-model="items" transition="100">
        <template v-slot:item="{ item }">
          <div class="faq-position-modal__item">
            <IconComponent name="menu-line" />
            <span class="faq-position-modal__title">{{ item.question }}</span>
            <span v-if="!item.is_active" class="faq-position-modal__badge">Неактивен</span>
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
import FAQS from "@/graphql/queries/admin_faqs.graphql";
import FAQ_CHANGE_POSITION from "@/graphql/mutations/FaqChangePosition.graphql";

export default {
  name: "FaqChangePositionModal",
  inject: ["apollo"],
  components: { Draggable, IconComponent },
  props: {
    callback: {
      type: Function,
      default: null,
    },
  },
  data() {
    return {
      items: [],
      loading: true,
      saving: false,
    };
  },
  mounted() {
    this.loadItems();
  },
  methods: {
    loadItems() {
      this.loading = true;
      this.apollo()
        .admin.query({
          query: FAQS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.items = [...(data.allFaqs || [])]
            .sort((a, b) => (a.position ?? Infinity) - (b.position ?? Infinity) || a.id - b.id)
            .map((f) => ({ ...f }));
        })
        .catch((error) => {
          console.error("Error loading faqs for position:", error);
          alert("Не удалось загрузить список вопросов");
        })
        .finally(() => {
          this.loading = false;
        });
    },
    submit() {
      this.saving = true;
      const payload = this.items.map((item, index) => ({
        id: parseInt(item.id),
        position: index + 1,
      }));

      this.apollo()
        .admin.mutate({
          mutation: FAQ_CHANGE_POSITION,
          variables: { items: payload },
        })
        .then(() => {
          if (this.callback) {
            this.callback();
          }
          this.$emit("close");
        })
        .catch((error) => {
          console.error("Error changing faq positions:", error);
          alert(error.message || "Не удалось сохранить порядок");
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.faq-position-modal {
  width 100%
  max-width 560px

  &__body {
    display flex
    flex-direction column
    gap 12px
    max-height 60vh
    overflow auto
  }

  &__list {
    display flex
    flex-direction column
    gap 8px
  }

  &__item {
    display flex
    align-items center
    gap 12px
    cursor grab
    background var(--gray-6)
    padding 10px 12px
    border-radius 6px

    .icon {
      width 18px
      height 18px
      flex-shrink 0
    }

    &:active {
      cursor grabbing
    }
  }

  &__title {
    font-size 0.9375rem
    color var(--dark)
    overflow hidden
    text-overflow ellipsis
    white-space nowrap
  }

  &__badge {
    margin-left auto
    flex-shrink 0
    font-size 0.75rem
    color var(--dark-o50)
    background var(--gray-dark)
    border-radius 4px
    padding 2px 8px
  }

  &__status {
    padding 24px
    text-align center
    color var(--dark-o50)
  }
}
</style>
