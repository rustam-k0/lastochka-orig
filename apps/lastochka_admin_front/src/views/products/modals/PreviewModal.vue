<template>
  <div class="preview-modal">
    <PreviewTabs @change-tabs="changeTabs" :active-item="activeItem" :items="items" />
    <div class="preview-modal__content">
      <iframe
        class="preview-modal__iframe"
        :class="{ 'preview-modal__iframe--mobile': isMobile }"
        v-if="link"
        :src="link"
      ></iframe>
    </div>
  </div>
</template>

<script>
import PreviewTabs from "@/views/products/components/PreviewTabs.vue";
import { useAuthStore } from "@/stores/auth.js";
import { useDefaultStore } from "@/stores/default.js";
export default {
  name: "PreviewModal",

  mounted() {
    this.changeTabs();
  },

  data() {
    return {
      activeItem: 1,
      link: null,
      isMobile: null,
      items: ["Карточка товара(Мобильная)", "Карточка товара", "Каталог(Мобильная)", "Каталог"],
    };
  },

  methods: {
    changeTabs($event) {
      this.link = null;
      this.activeItem = $event ?? this.activeItem;

      //Просто карточка товара
      if ([0, 1].includes($event)) {
        this.link = `${this.HOST_ENDPOINT}?product_id=${this.id}&token=${this.token}`;
      } else {
        //Карточка товара в каталоге
        this.link = `${this.HOST_ENDPOINT}/category-open?catalog_product_id=${this.id}&token=${this.token}`;
      }

      //Проверка мобильная версия или нет
      this.isMobile = [0, 2].includes($event);
    },
  },
  computed: {
    id() {
      return this.$route.params.id;
    },
    token() {
      return useAuthStore().token;
    },
    HOST_ENDPOINT() {
      return useDefaultStore().HOST_ENDPOINT;
    },
  },
  components: {
    PreviewTabs,
  },
};
</script>

<style lang="stylus">
.preview-modal {
  width calc(100vw - 300px)
  display flex
  flex-direction column
  gap 16px
  z-index 1
  background var(--white)
  border-radius 16px
  overflow hidden
  padding 16px
  height 100%


  &__content {
    display flex
    justify-content center
    width 100%
    height 100%
  }

  &__iframe {
    width 100%
    height 100%
    border none
  }

  &__iframe--mobile {
    width 100%
    max-width 420px
  }
}
</style>
