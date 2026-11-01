<template>
  <main class="page--main product-group-filter-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="product-group-filter-edit-page__header">
      <h2>Редактирование фильтра</h2>
    </div>

    <div v-if="pageLoading" class="product-group-filter-edit-page__loading">
      <LoadingIndicator />
    </div>

    <div v-else-if="!filterData" class="product-group-filter-edit-page__error">
      <IconComponent name="error-warning-line" />
      <h3>Фильтр не найден</h3>
      <button @click="goBack" class="btn btn--md">
        Вернуться к списку
      </button>
    </div>

    <template v-else>
      <div class="product-group-filter-edit-page__content">
        <form @submit.prevent="handleSubmit">
          <div class="product-group-filter-edit-page__fields">
            <!-- Название -->
            <div class="product-group-filter-edit-page__field">
              <label class="product-group-filter-edit-page__label">Название <span class="required">*</span></label>
              <input
                type="text"
                v-model="form.name"
                placeholder="Введите название фильтра"
                class="product-group-filter-edit-page__input"
                :class="{ 'product-group-filter-edit-page__input--error': errors.name }"
                maxlength="255"
              />
              <span v-if="errors.name" class="product-group-filter-edit-page__error-text">{{ errors.name }}</span>
            </div>

            <!-- Позиция -->
            <div class="product-group-filter-edit-page__field">
              <label class="product-group-filter-edit-page__label">Позиция</label>
              <input
                type="number"
                v-model.number="form.position"
                placeholder="0"
                class="product-group-filter-edit-page__input product-group-filter-edit-page__input--small"
                min="0"
              />
            </div>

            <!-- Активность -->
            <div class="product-group-filter-edit-page__field product-group-filter-edit-page__field--toggle">
              <label class="product-group-filter-edit-page__label">Активность</label>
              <label class="product-group-filter-edit-page__toggle">
                <input type="checkbox" v-model="form.is_active" />
                <span class="product-group-filter-edit-page__toggle-slider"></span>
                <span class="product-group-filter-edit-page__toggle-text">{{ form.is_active ? 'Активен' : 'Неактивен' }}</span>
              </label>
            </div>
          </div>

          <!-- Кнопки -->
          <div class="product-group-filter-edit-page__actions">
            <button type="button" class="btn btn--white btn--md" @click="handleCancel">
              Отмена
            </button>
            <button type="submit" class="btn btn--md" :disabled="loading">
              <span v-if="loading">Сохранение...</span>
              <span v-else>Сохранить</span>
            </button>
          </div>
        </form>
      </div>

      <!-- Значения фильтра -->
      <div class="product-group-filter-edit-page__content product-group-filter-edit-page__values-section">
        <FilterValuesList
          :filterId="parseInt(filterId)"
          :values="filterData.values || []"
          @refresh="fetchFilter"
        />
      </div>
    </template>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import FilterValuesList from "./components/FilterValuesList.vue";
import PRODUCT_GROUP_FILTER_ITEM from "@/graphql/productGroupFilters/product_group_filter_item.graphql";
import PRODUCT_GROUP_FILTER_UPDATE from "@/graphql/productGroupFilters/ProductGroupFilterUpdate.graphql";

export default {
  name: "ProductGroupFilterEditPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
    FilterValuesList,
  },
  data() {
    return {
      filterData: null,
      pageLoading: true,
      loading: false,
      form: {
        name: "",
        position: 0,
        is_active: true,
      },
      errors: {},
      breadcrumbs: [
        { to: { name: "product-group-filters" }, title: "Фильтры товарных групп" },
        { title: "Редактирование" },
      ],
    };
  },
  computed: {
    filterId() {
      return this.$route.params.id;
    },
  },
  mounted() {
    this.fetchFilter();
  },
  methods: {
    fetchFilter() {
      this.pageLoading = true;

      this.apollo()
        .admin.query({
          query: PRODUCT_GROUP_FILTER_ITEM,
          variables: { id: parseInt(this.filterId) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.productGroupFilter) {
            this.filterData = data.productGroupFilter;
            this.form = {
              name: data.productGroupFilter.name || "",
              position: data.productGroupFilter.position ?? 0,
              is_active: data.productGroupFilter.is_active ?? true,
            };
          }
        })
        .catch((error) => {
          console.error("Error fetching filter:", error);
        })
        .finally(() => {
          this.pageLoading = false;
        });
    },
    validate() {
      this.errors = {};
      if (!this.form.name || !this.form.name.trim()) {
        this.errors.name = "Название обязательно";
      } else if (this.form.name.length > 255) {
        this.errors.name = "Максимум 255 символов";
      }
      return Object.keys(this.errors).length === 0;
    },
    handleSubmit() {
      if (!this.validate()) return;

      this.loading = true;

      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_GROUP_FILTER_UPDATE,
          variables: {
            id: parseInt(this.filterId),
            name: this.form.name.trim(),
            position: this.form.position || 0,
            is_active: this.form.is_active,
          },
        })
        .then(() => {
          this.$router.push({ name: "product-group-filters" });
        })
        .catch((error) => {
          console.error("Error updating product group filter:", error);
          alert(error.message || "Ошибка сохранения фильтра");
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "product-group-filters" });
    },
    goBack() {
      this.$router.push({ name: "product-group-filters" });
    },
  },
};
</script>

<style lang="stylus">
.product-group-filter-edit-page {
  &__header {
    margin-bottom 24px

    h2 {
      font-size 1.5rem
      font-weight 600
      color var(--dark)
    }
  }

  &__loading {
    display flex
    justify-content center
    padding 48px
  }

  &__error {
    display flex
    flex-direction column
    align-items center
    justify-content center
    padding 64px
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    text-align center

    .icon {
      width 64px
      height 64px
      margin-bottom 16px

      svg path {
        fill var(--red)
      }
    }

    h3 {
      font-size 1.25rem
      color var(--dark)
      margin-bottom 24px
    }
  }

  &__content {
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    padding 32px
  }

  &__values-section {
    margin-top 24px
  }

  &__fields {
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
    background var(--white)

    &:focus {
      border-color var(--pink)
    }

    &--error {
      border-color var(--red)
    }

    &--small {
      width 120px
    }
  }

  &__error-text {
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
