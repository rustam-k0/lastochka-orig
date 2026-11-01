<template>
  <div class="filter-values-list">
    <div class="filter-values-list__header">
      <h3>Значения фильтра</h3>
      <button type="button" @click="showAddForm = true" class="btn btn--sm" v-if="!showAddForm">
        <IconComponent name="add-circle-line" />
        <span>Добавить значение</span>
      </button>
    </div>

    <!-- Форма добавления -->
    <div v-if="showAddForm" class="filter-values-list__add-form">
      <div class="filter-values-list__add-fields">
        <div class="filter-values-list__field">
          <label class="filter-values-list__label">Значение <span class="required">*</span></label>
          <input
            type="text"
            v-model="newValue.value"
            placeholder="Введите значение"
            class="filter-values-list__input"
            :class="{ 'filter-values-list__input--error': newValueErrors.value }"
          />
          <span v-if="newValueErrors.value" class="filter-values-list__error">{{ newValueErrors.value }}</span>
        </div>
        <div class="filter-values-list__field">
          <label class="filter-values-list__label">Позиция</label>
          <input
            type="number"
            v-model.number="newValue.position"
            placeholder="0"
            class="filter-values-list__input filter-values-list__input--small"
            min="0"
          />
        </div>
        <div class="filter-values-list__field filter-values-list__field--toggle">
          <label class="filter-values-list__label">Активность</label>
          <label class="filter-values-list__toggle">
            <input type="checkbox" v-model="newValue.is_active" />
            <span class="filter-values-list__toggle-slider"></span>
          </label>
        </div>
      </div>
      <div class="filter-values-list__add-actions">
        <button type="button" class="btn btn--white btn--sm" @click="cancelAdd">Отмена</button>
        <button type="button" class="btn btn--sm" :disabled="addLoading" @click="addValue">
          <span v-if="addLoading">Сохранение...</span>
          <span v-else>Добавить</span>
        </button>
      </div>
    </div>

    <!-- Таблица значений -->
    <div v-if="values.length" class="filter-values-list__table-wrapper">
      <table class="filter-values-list__table">
        <thead>
          <tr>
            <th>Значение</th>
            <th>Позиция</th>
            <th>Активность</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in values" :key="item.id">
            <td>
              <input
                v-if="editingId === item.id"
                type="text"
                v-model="editForm.value"
                class="filter-values-list__input"
              />
              <span v-else>{{ item.value }}</span>
            </td>
            <td>
              <input
                v-if="editingId === item.id"
                type="number"
                v-model.number="editForm.position"
                class="filter-values-list__input filter-values-list__input--small"
                min="0"
              />
              <span v-else>{{ item.position }}</span>
            </td>
            <td>
              <label class="filter-values-list__toggle">
                <input
                  type="checkbox"
                  :checked="editingId === item.id ? editForm.is_active : item.is_active"
                  @change="editingId === item.id ? (editForm.is_active = $event.target.checked) : toggleValueActive(item)"
                />
                <span class="filter-values-list__toggle-slider"></span>
              </label>
            </td>
            <td>
              <div class="filter-values-list__row-actions">
                <template v-if="editingId === item.id">
                  <button type="button" class="btn btn--sm" :disabled="updateLoading" @click="saveEdit(item)">
                    <span v-if="updateLoading">...</span>
                    <span v-else>Сохранить</span>
                  </button>
                  <button type="button" class="btn btn--white btn--sm" @click="cancelEdit">Отмена</button>
                </template>
                <template v-else>
                  <button type="button" class="btn btn--white btn--sm" @click="startEdit(item)">
                    <IconComponent name="edit-line" />
                  </button>
                  <button type="button" class="btn btn--white btn--sm btn--danger" @click="confirmDelete(item)">
                    <IconComponent name="delete-bin-line" />
                  </button>
                </template>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-else-if="!showAddForm" class="filter-values-list__empty">
      <p>Нет значений фильтра</p>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import PRODUCT_GROUP_FILTER_VALUE_CREATE from "@/graphql/productGroupFilters/ProductGroupFilterValueCreate.graphql";
import PRODUCT_GROUP_FILTER_VALUE_UPDATE from "@/graphql/productGroupFilters/ProductGroupFilterValueUpdate.graphql";
import PRODUCT_GROUP_FILTER_VALUE_DELETE from "@/graphql/productGroupFilters/ProductGroupFilterValueDelete.graphql";

export default {
  name: "FilterValuesList",
  inject: ["apollo"],
  components: {
    IconComponent,
  },
  props: {
    filterId: {
      type: Number,
      required: true,
    },
    values: {
      type: Array,
      default: () => [],
    },
  },
  emits: ["refresh"],
  data() {
    return {
      showAddForm: false,
      addLoading: false,
      updateLoading: false,
      editingId: null,
      newValue: {
        value: "",
        position: 0,
        is_active: true,
      },
      newValueErrors: {},
      editForm: {
        value: "",
        position: 0,
        is_active: true,
      },
    };
  },
  methods: {
    validateNewValue() {
      this.newValueErrors = {};
      if (!this.newValue.value || !this.newValue.value.trim()) {
        this.newValueErrors.value = "Значение обязательно";
      }
      return Object.keys(this.newValueErrors).length === 0;
    },
    addValue() {
      if (!this.validateNewValue()) return;

      this.addLoading = true;

      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_GROUP_FILTER_VALUE_CREATE,
          variables: {
            product_group_filter_id: this.filterId,
            value: this.newValue.value.trim(),
            position: this.newValue.position || 0,
            is_active: this.newValue.is_active,
          },
        })
        .then(() => {
          this.cancelAdd();
          this.$emit("refresh");
        })
        .catch((error) => {
          console.error("Error creating filter value:", error);
          alert("Ошибка создания значения");
        })
        .finally(() => {
          this.addLoading = false;
        });
    },
    cancelAdd() {
      this.showAddForm = false;
      this.newValue = { value: "", position: 0, is_active: true };
      this.newValueErrors = {};
    },
    startEdit(item) {
      this.editingId = item.id;
      this.editForm = {
        value: item.value,
        position: item.position,
        is_active: item.is_active,
      };
    },
    cancelEdit() {
      this.editingId = null;
      this.editForm = { value: "", position: 0, is_active: true };
    },
    saveEdit(item) {
      if (!this.editForm.value || !this.editForm.value.trim()) {
        alert("Значение обязательно");
        return;
      }

      this.updateLoading = true;

      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_GROUP_FILTER_VALUE_UPDATE,
          variables: {
            id: parseInt(item.id),
            value: this.editForm.value.trim(),
            position: this.editForm.position || 0,
            is_active: this.editForm.is_active,
          },
        })
        .then(() => {
          this.cancelEdit();
          this.$emit("refresh");
        })
        .catch((error) => {
          console.error("Error updating filter value:", error);
          alert("Ошибка обновления значения");
        })
        .finally(() => {
          this.updateLoading = false;
        });
    },
    toggleValueActive(item) {
      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_GROUP_FILTER_VALUE_UPDATE,
          variables: {
            id: parseInt(item.id),
            is_active: !item.is_active,
          },
        })
        .then(() => {
          this.$emit("refresh");
        })
        .catch((error) => {
          console.error("Error toggling filter value:", error);
          alert("Ошибка изменения статуса");
        });
    },
    confirmDelete(item) {
      if (confirm(`Удалить значение "${item.value}"?`)) {
        this.deleteValue(item);
      }
    },
    deleteValue(item) {
      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_GROUP_FILTER_VALUE_DELETE,
          variables: { id: parseInt(item.id) },
        })
        .then(() => {
          this.$emit("refresh");
        })
        .catch((error) => {
          console.error("Error deleting filter value:", error);
          alert("Ошибка удаления значения");
        });
    },
  },
};
</script>

<style lang="stylus">
.filter-values-list {
  margin-top 32px
  padding-top 24px
  border-top 1px solid var(--gray-dark)

  &__header {
    display flex
    justify-content space-between
    align-items center
    margin-bottom 16px

    h3 {
      font-size 1.125rem
      font-weight 600
      color var(--dark)
    }
  }

  &__add-form {
    background var(--bg)
    border-radius 8px
    padding 20px
    margin-bottom 16px
  }

  &__add-fields {
    display flex
    gap 16px
    align-items flex-end
    flex-wrap wrap
    margin-bottom 16px
  }

  &__add-actions {
    display flex
    justify-content flex-end
    gap 12px
  }

  &__field {
    display flex
    flex-direction column

    &--toggle {
      flex-direction row
      align-items center
      gap 8px
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
    padding 10px 14px
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
      width 80px
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
    gap 8px
    cursor pointer

    input {
      display none
    }
  }

  &__toggle-slider {
    width 40px
    height 20px
    background var(--gray-dark)
    border-radius 20px
    position relative
    transition background 0.2s

    &::after {
      content ''
      position absolute
      width 16px
      height 16px
      background var(--white)
      border-radius 50%
      top 2px
      left 2px
      transition left 0.2s
    }

    input:checked + & {
      background var(--green)

      &::after {
        left 22px
      }
    }
  }

  &__table-wrapper {
    overflow-x auto
  }

  &__table {
    width 100%
    border-collapse collapse

    th, td {
      padding 12px 16px
      text-align left
      font-size 0.875rem
      border-bottom 1px solid var(--gray-dark)
    }

    th {
      font-weight 600
      color var(--dark)
      background var(--bg)
    }

    td {
      color var(--dark)
    }
  }

  &__row-actions {
    display flex
    gap 8px
  }

  &__empty {
    text-align center
    padding 24px
    color var(--dark-light)
    font-size 0.875rem
  }
}

.btn--danger {
  &:hover {
    border-color var(--red)
    color var(--red)
  }
}
</style>
