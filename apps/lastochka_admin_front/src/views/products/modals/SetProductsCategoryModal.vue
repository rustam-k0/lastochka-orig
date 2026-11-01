<template>
  <div class="default-modal set-products-category-modal">
    <div class="default-modal__header">
      <span class="default-modal__title">Перенести в категорию ({{ count }} товар{{ countSuffix }})</span>
      <button type="button" class="default-modal__close" @click="$emit('close')">
        <IconComponent name="close-fill" />
      </button>
    </div>
    <form @submit.prevent="submit" class="default-modal__body set-products-category-modal__body">
      <CategoryTreeSelect
        title="Категория"
        placeholder="Выберите категорию"
        v-model="form.category_id.value"
        :errors="form.category_id.errors"
        :options="categoriesTree"
        leaf-only
        clearable
      />
    </form>
    <div class="default-modal__footer set-products-category-modal__footer">
      <button type="button" @click="$emit('close')" class="btn btn--gray btn--md">Отмена</button>
      <button type="button" @click="submit" :disabled="loading" class="btn btn--main btn--md">
        {{ loading ? "Сохранение…" : "Перенести" }}
      </button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import CategoryTreeSelect from "@/components/inputs/CategoryTreeSelect.vue";
import { resetErrors, parseGqlErrors } from "@/utils.js";
import SET_PRODUCTS_CATEGORY from "@/graphql/mutations/SetProductsCategory.graphql";

export default {
  name: "SetProductsCategoryModal",
  inject: ["apollo"],
  components: { IconComponent, CategoryTreeSelect },
  emits: ["close", "success"],
  props: {
    ids: {
      type: Array,
      default: () => [],
    },
    categoriesTree: {
      type: Array,
      default: () => [],
    },
    callback: {
      type: Function,
      default: null,
    },
  },
  data() {
    return {
      loading: false,
      form: {
        category_id: { value: null, errors: [] },
      },
    };
  },
  computed: {
    count() {
      return this.ids.length;
    },
    countSuffix() {
      const n = this.count % 100;
      const n1 = this.count % 10;
      if (n >= 11 && n <= 19) return "ов";
      if (n1 === 1) return "";
      if (n1 >= 2 && n1 <= 4) return "а";
      return "ов";
    },
  },
  methods: {
    resetErrors,
    parseGqlErrors,
    submit() {
      this.resetErrors();
      const category = this.form.category_id.value;
      if (!category?.id) {
        this.form.category_id.errors = ["Выберите категорию"];
        return;
      }
      this.loading = true;
      this.apollo()
        .admin.mutate({
          mutation: SET_PRODUCTS_CATEGORY,
          variables: {
            ids: this.ids.map((id) => parseInt(id)),
            category_id: parseInt(category.id),
          },
        })
        .then(() => {
          this.$emit("success");
          if (this.callback) {
            this.callback();
          }
          this.$emit("close");
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) {
            this.parseGqlErrors(graphQLErrors);
          }
        })
        .finally(() => {
          this.loading = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.set-products-category-modal {
  width 400px

  &__body {
    display flex
    flex-direction column
    gap 16px
  }

  &__footer {
    display flex
    gap 16px

    .btn {
      flex 1
    }
  }
}
</style>
