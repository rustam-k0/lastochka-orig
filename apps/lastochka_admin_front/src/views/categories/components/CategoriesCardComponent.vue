<template>
  <div class="categories_card">
    <div class="categories_card_top">
      <div @click="getItem" class="categories_card__image">
        <ImgComponent :head_img="data.site_square_img" />
      </div>
      <div class="categories_card__options">
        <IconComponent name="more-2-line" />
        <div class="categories_card__menu">
          <span @click="goToItem(data.id)">Редактировать</span>
          <div class="categories_card__menu_line"></div>
          <span @click="remove(data.id)" class="categories_card__menu--red">Удалить</span>
        </div>
      </div>
      <span class="categories_card__teg"> Товары: {{ data.products }}</span>
    </div>
    <span class="categories_card__title"> {{ data.title }} </span>
  </div>
</template>

<script>
import ImgComponent from "@/components/ImgComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import NewCategoryModal from "@/views/categories/modals/NewCategoryModal.vue";
import { useDefaultStore } from "@/stores/default.js";
import { shallowRef } from "vue";
import REMOVE from "@/graphql/mutations/EntityDelete.graphql";
export default {
  name: "CategoriesCardComponent",
  inject: ["apollo"],
  props: {
    data: {
      type: Object,
      default: () => {},
    },
  },
  methods: {
    getItem() {
      if (this.data.child_categories && this.data.child_categories.length) {
        this.$router.push({ name: "categories", params: { id: this.data.id } });
      }
    },
    remove() {
      if (confirm("Вы уверены?")) {
        this.apollo()
          .admin.mutate({
            mutation: REMOVE,
            variables: {
              id: this.data.id,
              table: "categories",
            },
          })
          .then(() => {
            this.$emit("update:get");
            useDefaultStore().modals = [];
          });
      }
    },
    openModal(id) {
      useDefaultStore().modals.push({
        component: shallowRef(NewCategoryModal),
        options: {
          id: id,
          callback: () => {
            this.$emit("update:get");
          },
        },
      });
    },
    goToItem(id) {
      this.$router.push({ name: "category", params: { id: id } });
    },
  },
  components: { IconComponent, ImgComponent },
};
</script>

<style lang="stylus">
.categories_card {
  display flex
  flex-direction column
  align-items center
  gap 10px

  //&:hover {
  //  .categories_card__menu {
  //    visibility visible
  //    opacity 1
  //  }
  //}

  &__menu {
    display: flex;
    position absolute
    right: 32px;
    top -33px
    flex-direction column
    background: var(--white);
    border-radius: 8px;
    width: 142px;
    transition var(--transition)
    z-index: 1;
    opacity: 0;
    visibility: hidden;

    span {
      font-weight: 400;
      font-size: 0.8125em;
      line-height: 16px;
      color: var(--dark);
      padding 16px
      transition var(--transition)
      border-radius: 8px;
      cursor pointer


      &:hover{
        background-color var(--green-light)
      }
    }

    &_line {
      border: 1px solid #E1EAEF;
    }

    &--red {
      color: var(--red-dark-2) !important
    }
  }

  &__options {
    top 8px
    right: 8px;
    cursor: pointer;
    position absolute
    padding 5px
    background: var(--white);
    border-radius: 10px;
    height: 30px;
    transition var(--transition)

    &:hover {
      .categories_card__menu {
        visibility visible
        opacity 1
      }
    }

    svg {
      max-height: 20px;
    }
  }

  &_top{
    position: relative;
    height 248px
    width 100%
  }

  &__image {
    border-radius: 16px;
    height: 100%;
    width 100%
    overflow hidden
    display block
    cursor pointer

    & img {
      width 100%
      height 100%
    }
  }

  &__title {
    font-weight: 500;
    font-size: 1.125em;
    line-height: 120%;
    color: var(--dark-2);
  }
  &__teg {
    padding 5px 10px
    background: var(--white);
    border-radius: 10px;
    font-weight: 500;
    font-size: 0.875em;
    line-height: 20px;
    text-align: right;
    color: var(--dark)
    position absolute
    right: 8px;
    bottom 8px

  }
}
</style>
