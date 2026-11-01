<template>
  <main class="page--main attributes-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="attributes-page__header">
      <h2>{{ pageTitle }}</h2>
      <button class="btn btn--md" @click="$router.push({ name: 'attribute-create' })">
        <IconComponent name="add-circle-line" />
        <span>Добавить атрибут</span>
      </button>
    </div>

    <div v-if="loading" class="attributes-page__loading">
      <LoadingIndicator />
    </div>

    <div v-else-if="!attributes.length" class="attributes-page__empty">
      <IconComponent name="list-settings-line" />
      <h3>Нет атрибутов</h3>
      <button class="btn btn--md" @click="$router.push({ name: 'attribute-create' })">
        <IconComponent name="add-circle-line" />
        <span>Создать атрибут</span>
      </button>
    </div>

    <div v-else class="attributes-page__table-wrapper">
      <table class="attributes-page__table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Название</th>
            <th>Код</th>
            <th>Тип значения</th>
            <th>Группа</th>
            <th>Семейства</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="attr in attributes" :key="attr.id">
            <td>{{ attr.id }}</td>
            <td>{{ attr.title }}</td>
            <td><code class="attributes-page__code">{{ attr.code || '—' }}</code></td>
            <td>{{ typeLabel(attr.type_id) }}</td>
            <td>{{ groupLabel(attr.category_type_id) }}</td>
            <td>{{ (attr.families || []).map(f => f.title).join(', ') || '—' }}</td>
            <td>
              <div class="attributes-page__actions">
                <button
                  class="attributes-page__action-btn"
                  title="Редактировать"
                  @click="$router.push({ name: 'attribute-edit', params: { id: attr.id } })"
                >
                  <IconComponent name="edit-line" />
                </button>
                <button
                  class="attributes-page__action-btn attributes-page__action-btn--danger"
                  title="Удалить"
                  @click="confirmDelete(attr)"
                >
                  <IconComponent name="delete-bin-line" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="paginatorInfo.lastPage > 1" class="attributes-page__pagination">
        <button
          class="btn btn--sm"
          :disabled="page <= 1"
          @click="page--; loadAttributes()"
        >
          ←
        </button>
        <span>{{ page }} / {{ paginatorInfo.lastPage }}</span>
        <button
          class="btn btn--sm"
          :disabled="page >= paginatorInfo.lastPage"
          @click="page++; loadAttributes()"
        >
          →
        </button>
      </div>
    </div>

    <transition name="fade">
      <div v-if="toast" class="attributes-page__toast">
        {{ toast }}
      </div>
    </transition>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import ATTRIBUTES_PAGINATE from "@/graphql/queries/attributes_paginate.graphql";
import DEFAULT_ATTRIBUTES from "@/graphql/queries/default_attributes.graphql";
import ENTITY_DELETE from "@/graphql/mutations/EntityDelete.graphql";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "AttributesPage",
  inject: ["apollo"],
  components: { BreadcrumbsComponent, IconComponent, LoadingIndicator },
  data() {
    return {
      attributes: [],
      loading: false,
      toast: null,
      toastTimeout: null,
      page: 1,
      perPage: 50,
      paginatorInfo: {},
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Атрибуты");
    },
    breadcrumbs() {
      return [{ title: this.pageTitle }];
    },
  },
  mounted() {
    this.loadAttributes();
  },
  methods: {
    typeLabel(typeId) {
      const map = {
        1: "Строка",
        2: "Текст",
        3: "Целое число",
        4: "Дробное число",
        5: "Выбор из списка",
      };
      return map[typeId] || typeId;
    },
    groupLabel(categoryTypeId) {
      const map = {
        100: "Состав",
        101: "Параметры",
        103: "Динамические",
        104: "Параметры упаковки",
      };
      return map[categoryTypeId] || categoryTypeId;
    },
    loadAttributes() {
      this.loading = true;
      const client = this.apollo().admin;
      Promise.all([
        client.query({
          query: DEFAULT_ATTRIBUTES,
          fetchPolicy: "network-only",
        }),
        client.query({
          query: ATTRIBUTES_PAGINATE,
          variables: { first: this.perPage, page: this.page },
          fetchPolicy: "network-only",
        }),
      ])
        .then(([defaultRes, dynamicRes]) => {
          const defaults = defaultRes.data?.default_attributes || [];
          const dynamic = dynamicRes.data?.attributes_paginate?.data || [];
          this.paginatorInfo = dynamicRes.data?.attributes_paginate?.paginatorInfo || {};
          this.attributes = [...defaults, ...dynamic];
        })
        .catch((err) => {
          console.error("Error loading attributes:", err);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    confirmDelete(attr) {
      if (!confirm(`Удалить атрибут «${attr.title}»?`)) return;
      this.apollo()
        .admin.mutate({
          mutation: ENTITY_DELETE,
          variables: { id: attr.id, table: "attributes" },
        })
        .then(() => {
          this.showToast(`Атрибут «${attr.title}» удалён`);
          this.loadAttributes();
        })
        .catch((error) => {
          console.error("Error deleting attribute:", error);
          alert(error.message || "Ошибка удаления");
        });
    },
    showToast(message) {
      if (this.toastTimeout) clearTimeout(this.toastTimeout);
      this.toast = message;
      this.toastTimeout = setTimeout(() => { this.toast = null; }, 3000);
    },
  },
  beforeUnmount() {
    if (this.toastTimeout) clearTimeout(this.toastTimeout);
  },
};
</script>

<style lang="stylus">
.attributes-page
  padding 24px

  &__header
    display flex
    align-items center
    justify-content space-between
    margin-bottom 24px

    h2
      margin 0

  &__loading
    display flex
    justify-content center
    padding 48px

  &__empty
    text-align center
    padding 48px
    color #999

    .icon
      font-size 48px
      margin-bottom 16px

    h3
      margin 0 0 8px

    .btn
      margin-top 16px

  &__table-wrapper
    background #fff
    border-radius 8px
    overflow hidden

  &__table
    width 100%
    border-collapse collapse

    th, td
      padding 12px 16px
      text-align left
      border-bottom 1px solid #eee

    th
      font-weight 600
      color #666
      font-size 13px

    tr:hover td
      background #fafafa

  &__muted
    color #ccc

  &__code
    font-family monospace
    font-size 12px
    background #f5f5f5
    padding 2px 6px
    border-radius 4px
    color #444

  &__actions
    display flex
    gap 8px

  &__action-btn
    display flex
    align-items center
    justify-content center
    width 36px
    height 36px
    border-radius 8px
    border 1px solid #eee
    background #fff
    cursor pointer
    transition all 0.2s

    .icon
      width 18px
      height 18px

      svg path
        fill #333

    &:hover
      border-color var(--primary)

      .icon svg path
        fill var(--primary)

    &--danger:hover
      border-color #e55

      .icon svg path
        fill #e55

  &__pagination
    display flex
    align-items center
    justify-content center
    gap 16px
    padding 16px

  &__toast
    position fixed
    bottom 32px
    left 50%
    transform translateX(-50%)
    background #333
    color #fff
    padding 12px 24px
    border-radius 8px
    font-size 14px
    z-index 1000
    box-shadow 0 4px 12px rgba(0, 0, 0, 0.15)

.fade-enter-active,
.fade-leave-active
  transition opacity 0.3s

.fade-enter-from,
.fade-leave-to
  opacity 0
</style>
