<template>
  <ul class="breadcrumbs">
    <template v-for="(link, i) in breadcrumbs || []" :key="i">
      <li>
        <router-link v-if="link.to" :to="link.to">{{ link.title }}</router-link>
        <span v-else>{{ link.title }}</span>
      </li>
      <li v-if="i !== breadcrumbs.length - 1">
        <IconComponent name="arrow-right-line" />
      </li>
    </template>
  </ul>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "BreadcrumbsComponent",
  components: { IconComponent },
  props: {
    breadcrumbs: {
      type: Array,
      default: () => [],
    },
  },
};
</script>

<style lang="stylus">
.breadcrumbs {
  display flex
  align-items center
  gap 4px

  li {
    display inline-flex
    align-items center
    justify-content center
    color #222128

    .icon {
      width 12px
      height 12px

      svg path {
        fill var(--body-muted)
      }
    }

    span,
    a {
      font-weight: 400;
      font-size 0.688em
      line-height: 15px;
    }

    a {
      opacity: 0.5;
      color: var(--body-muted)
      text-decoration underline
    }
  }
}
</style>
