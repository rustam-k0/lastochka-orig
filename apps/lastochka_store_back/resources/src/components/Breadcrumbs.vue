<template>
  <div class="breadcrumb">
    <div class="container">
      <h1 class="breadcrumb__title">{{ title }}</h1>
      <ul v-if="$route.name !== 'home' || ($route.meta && $route.meta.parent)" class="breadcrumb__list">
        <li v-if="$route.name !== 'home'">
          <router-link :to="{ name: 'home' }">Главная</router-link>
        </li>
        <li v-for="(link, i) in links" :key="i">
          <router-link :to="link.to">{{ link.title }}</router-link>
        </li>
      </ul>
    </div>
  </div>
</template>

<script>
export default {
  name: "BreadcrumbsComponent",
  props: {
    links: {
      type: Array,
      default: () => [],
    },
    title: {
      type: String,
      required: true,
    },
  },
};
</script>
<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
.breadcrumb {
  padding 20px 0

  .container {
    display flex
    align-items flex-start
    justify-content space-between
    flex-direction column

    +below(768px) {
      display block
    }
  }

  &__title {
    margin 0 0 5px
    padding 0
    font-size 1.3em
    flex 1

    +below(768px) {
      margin 0 0 15px
    }
  }

  &__list {
    flex 1
    display flex
    flex-wrap wrap
    justify-content flex-end
    margin-bottom -10px

    +below(768px) {
      justify-content flex-start
    }

    li {
      list-style none

      a {
        link($main_color)
        text-decoration underline
      }

      &::after {
        content '/'
        display inline-flex
        margin 0 10px
      }

      &:last-child::after {
        display none
      }
    }
  }
}
</style>
