<template>
  <main class="page--main assembly-zones-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="assembly-zones-page__header">
      <h2>{{ pageTitle }}</h2>
      <button @click="openCreate" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Добавить зону</span>
      </button>
    </div>

    <div v-if="!zones.length && !creating" class="assembly-zones-page__empty">
      <p>Зоны сборки не добавлены</p>
    </div>

    <section v-else class="assembly-zones-page__list">
      <div
        v-for="zone in zones"
        :key="zone.id"
        class="assembly-zones-page__item"
      >
        <template v-if="editingId === zone.id">
          <div class="assembly-zones-page__form assembly-zones-page__form--edit">
            <div class="assembly-zones-page__form-row">
              <InputComponent
                v-model="form.title.value"
                :errors="form.title.errors"
                title="Название"
                placeholder="Название зоны"
                required
              />
              <InputComponent
                v-model="form.color.value"
                :errors="form.color.errors"
                title="Цвет"
                type="color"
              />
              <InputComponent
                v-model="form.position.value"
                :errors="form.position.errors"
                title="Позиция"
                placeholder="0"
                type="number"
              />
            </div>
            <TextareaComponent
              v-model="form.description.value"
              :errors="form.description.errors"
              title="Описание"
              placeholder="Комментарий к зоне"
            />
            <CheckboxComponent v-model="form.is_active.value" green>Активна</CheckboxComponent>
          </div>
          <div class="assembly-zones-page__item-actions">
            <button @click="saveEdit(zone)" class="btn btn--main btn--sm">Сохранить</button>
            <button @click="cancelEdit" class="btn btn--white btn--sm">Отмена</button>
          </div>
        </template>
        <template v-else>
          <div class="assembly-zones-page__item-info">
            <span class="assembly-zones-page__item-title">
              <span
                class="assembly-zones-page__item-color"
                :style="{ background: zone.color || 'transparent' }"
              ></span>
              {{ zone.title }}
              <span v-if="zone.is_active === false" class="assembly-zones-page__item-badge">выключена</span>
            </span>
            <span class="assembly-zones-page__item-position">Позиция: {{ zone.position }}</span>
            <span v-if="zone.description" class="assembly-zones-page__item-description">{{ zone.description }}</span>
          </div>
          <div class="assembly-zones-page__item-actions">
            <button @click="startEdit(zone)" class="btn btn--white btn--sm">
              <IconComponent name="edit-line" />
            </button>
            <button @click="remove(zone)" class="btn btn--danger btn--sm">
              <IconComponent name="delete-bin-line" />
            </button>
          </div>
        </template>
      </div>
    </section>

    <div v-if="creating" class="assembly-zones-page__create">
      <h3>Новая зона</h3>
      <div class="assembly-zones-page__form-row">
        <InputComponent
          v-model="form.title.value"
          :errors="form.title.errors"
          title="Название"
          placeholder="Название зоны"
          required
        />
        <InputComponent
          v-model="form.color.value"
          :errors="form.color.errors"
          title="Цвет"
          type="color"
        />
        <InputComponent
          v-model="form.position.value"
          :errors="form.position.errors"
          title="Позиция"
          placeholder="0"
          type="number"
        />
      </div>
      <TextareaComponent
        v-model="form.description.value"
        :errors="form.description.errors"
        title="Описание"
        placeholder="Комментарий к зоне"
      />
      <CheckboxComponent v-model="form.is_active.value" green>Активна</CheckboxComponent>
      <div class="assembly-zones-page__item-actions">
        <button @click="create" class="btn btn--main btn--sm">Создать</button>
        <button @click="cancelCreate" class="btn btn--white btn--sm">Отмена</button>
      </div>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import TextareaComponent from "@/components/inputs/TextareaComponent.vue";
import GET from "@/graphql/queries/assembly_zones.graphql";
import CREATE from "@/graphql/pages/AssemblyZone/AssemblyZoneCreate.graphql";
import UPDATE from "@/graphql/pages/AssemblyZone/AssemblyZoneUpdate.graphql";
import REMOVE from "@/graphql/mutations/EntityDelete.graphql";
import { resetErrors, parseGqlErrors } from "@/utils.js";
import { useMenuStore } from "@/stores/menu.js";

const DEFAULT_COLOR = "#3B82F6";

export default {
  name: "AssemblyZonesPage",
  inject: ["apollo"],
  components: { BreadcrumbsComponent, IconComponent, InputComponent, CheckboxComponent, TextareaComponent },
  data() {
    return {
      zones: [],
      creating: false,
      editingId: null,
      form: {
        title: {
          value: null,
          errors: [],
        },
        color: {
          value: DEFAULT_COLOR,
          errors: [],
        },
        is_active: {
          value: true,
          errors: [],
        },
        description: {
          value: null,
          errors: [],
        },
        position: {
          value: 0,
          errors: [],
        },
      },
    };
  },
  mounted() {
    this.get();
  },
  methods: {
    resetErrors,
    parseGqlErrors,
    get() {
      this.apollo()
        .admin.query({ query: GET, fetchPolicy: "network-only" })
        .then(({ data }) => {
          this.zones = data.assembly_zones || [];
        })
        .catch(() => {});
    },
    resetForm() {
      this.form.title.value = null;
      this.form.color.value = DEFAULT_COLOR;
      this.form.is_active.value = true;
      this.form.description.value = null;
      this.form.position.value = 0;
      this.resetErrors();
    },
    openCreate() {
      this.editingId = null;
      this.resetForm();
      this.creating = true;
    },
    cancelCreate() {
      this.creating = false;
      this.resetForm();
    },
    startEdit(zone) {
      this.creating = false;
      this.resetErrors();
      this.form.title.value = zone.title;
      this.form.color.value = zone.color || DEFAULT_COLOR;
      this.form.is_active.value = zone.is_active !== false;
      this.form.description.value = zone.description || null;
      this.form.position.value = zone.position;
      this.editingId = zone.id;
    },
    cancelEdit() {
      this.editingId = null;
      this.resetForm();
    },
    buildVariables() {
      return {
        title: this.form.title.value,
        color: this.form.color.value || null,
        is_active: !!this.form.is_active.value,
        description: this.form.description.value || null,
        position: this.form.position.value ? parseInt(this.form.position.value) : 0,
      };
    },
    create() {
      this.resetErrors();
      this.apollo()
        .admin.mutate({
          mutation: CREATE,
          variables: this.buildVariables(),
        })
        .then(() => {
          this.creating = false;
          this.resetForm();
          this.get();
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) this.parseGqlErrors(graphQLErrors);
        });
    },
    saveEdit(zone) {
      this.resetErrors();
      this.apollo()
        .admin.mutate({
          mutation: UPDATE,
          variables: { id: zone.id, ...this.buildVariables() },
        })
        .then(() => {
          this.editingId = null;
          this.resetForm();
          this.get();
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) this.parseGqlErrors(graphQLErrors);
        });
    },
    remove(zone) {
      if (!confirm(`Удалить зону сборки «${zone.title}»?`)) return;
      this.apollo()
        .admin.mutate({
          mutation: REMOVE,
          variables: { id: zone.id, table: "assembly_zones" },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          alert(error.message || "Ошибка удаления");
        });
    },
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Зоны сборки");
    },
    breadcrumbs() {
      return [{ to: { name: "assembly-zones" }, title: this.pageTitle }];
    },
  },
};
</script>

<style lang="stylus">
.assembly-zones-page {
  &__header {
    display flex
    justify-content space-between
    align-items center
    margin-bottom 24px
  }

  &__empty {
    color var(--gray-2)
    padding 32px 0
  }

  &__list {
    display flex
    flex-direction column
    gap 12px
    max-width 600px
  }

  &__item {
    display flex
    justify-content space-between
    align-items center
    padding 16px
    background var(--white)
    border-radius 12px
    border 1px solid var(--white-2)
    gap 16px
  }

  &__item-info {
    display flex
    flex-direction column
    gap 4px
  }

  &__item-title {
    font-weight 500
    font-size 0.9375rem
    display flex
    align-items center
    gap 8px
  }

  &__item-color {
    width 14px
    height 14px
    border-radius 50%
    border 1px solid var(--white-2)
    flex-shrink 0
  }

  &__item-badge {
    font-size 0.6875rem
    color var(--gray-2)
    background var(--white-2)
    border-radius 6px
    padding 2px 6px
  }

  &__item-position {
    font-size 0.75rem
    color var(--gray-2)
  }

  &__item-description {
    font-size 0.75rem
    color var(--gray-2)
  }

  &__form-row {
    display flex
    gap 12px
    align-items flex-end
  }

  &__form--edit {
    flex-direction column
    align-items stretch
    gap 12px
    flex 1
  }

  &__item-actions {
    display flex
    gap 8px
    flex-shrink 0
  }

  &__form {
    display flex
    gap 12px
    flex 1
    align-items flex-end
  }

  &__create {
    margin-top 24px
    padding 20px
    background var(--white)
    border-radius 12px
    border 1px solid var(--white-2)
    max-width 600px
    display flex
    flex-direction column
    gap 16px

    h3 {
      margin 0
    }
  }
}
</style>
