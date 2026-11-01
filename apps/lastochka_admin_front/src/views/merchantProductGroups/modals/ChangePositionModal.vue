<template>
  <div class="mod merchant-product-groups-position-modal">
    <div class="mod__header">
      <span class="mod__title">Изменить порядок товарных групп</span>
      <button class="mod__close" @click="$emit('close')">
        <IconComponent name="close-line" />
      </button>
    </div>

    <div class="mod__body merchant-product-groups-position-modal__body">
      <div v-if="loading" class="merchant-product-groups-position-modal__status">Загрузка...</div>

      <div v-else-if="!items.length" class="merchant-product-groups-position-modal__status">
        Нет товарных групп для упорядочивания
      </div>

      <draggable v-else class="merchant-product-groups-position-modal__list" v-model="items" transition="100">
        <template v-slot:item="{ item }">
          <div class="merchant-product-groups-position-modal__item">
            <IconComponent name="menu-line" />
            <img
              v-if="resolveImage(item)"
              :src="resolveImage(item)"
              :alt="item.title"
              class="merchant-product-groups-position-modal__thumb"
            />
            <span class="merchant-product-groups-position-modal__title">{{ item.title }}</span>
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
import { useDefaultStore } from "@/stores/default.js";
import GROUPS_QUERY from "@/graphql/queries/merchant_product_groups_all_for_position.graphql";
import CHANGE_POSITION from "@/graphql/mutations/MerchantProductGroupChangePosition.graphql";

export default {
  name: "MerchantProductGroupsChangePositionModal",
  inject: ["apollo"],
  components: { Draggable, IconComponent },
  props: {
    shopId: {
      type: Number,
      default: null,
    },
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
  computed: {
    media_endpoint() {
      return useDefaultStore().MEDIA_ENDPOINT;
    },
  },
  mounted() {
    this.loadItems();
  },
  methods: {
    resolveImage(item) {
      if (!item.image || !item.image.path) {
        return null;
      }
      const path = item.image.path;
      if (path.startsWith("http") || path.startsWith("data:")) {
        return path;
      }
      return this.media_endpoint + path;
    },
    loadItems() {
      this.loading = true;
      this.apollo()
        .admin.query({
          query: GROUPS_QUERY,
          variables: { shop_id: this.shopId },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.items = (data.merchantProductGroupsAll || []).map((g) => ({ ...g }));
        })
        .catch((error) => {
          console.error("Error loading product groups for position:", error);
          alert("Не удалось загрузить список товарных групп");
        })
        .finally(() => {
          this.loading = false;
        });
    },
    submit() {
      this.saving = true;
      const payload = this.items.map((item, index) => ({
        id: parseInt(item.id),
        sort_order: index,
      }));

      this.apollo()
        .admin.mutate({
          mutation: CHANGE_POSITION,
          variables: { items: payload },
        })
        .then(() => {
          if (this.callback) {
            this.callback();
          }
          this.$emit("close");
        })
        .catch((error) => {
          console.error("Error changing positions:", error);
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
.merchant-product-groups-position-modal {
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

  &__thumb {
    width 36px
    height 36px
    border-radius 4px
    object-fit cover
    flex-shrink 0
  }

  &__title {
    font-size 0.9375rem
    color var(--dark)
    overflow hidden
    text-overflow ellipsis
    white-space nowrap
  }

  &__status {
    padding 24px
    text-align center
    color var(--dark-o50)
  }
}
</style>
