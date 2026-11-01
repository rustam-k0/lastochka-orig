@php
  $list = json_encode($data['addModalLinks'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
@endphp
<template>
  <div class="modal-body add-modal">
    <div class="modal-body__header">
      <span class="modal-body__title">Добавить контент</span>
      <button type="button" class="modal-body__close" @click="$emit('close')">
        <Icon name="close-line" category="system" class="icon" />
      </button>
    </div>
    <div class="modal-body__content">
      <ul class="add-modal__list">
        <li v-for="(link, i) in list.filter((l) => canShowLink(l.allowedRoles))" :key="i">
          <router-link :to="link.to">@verbatim{{ link.title }}@endverbatim</router-link>
        </li>
      </ul>
    </div>
  </div>
</template>

<script>
/**
 * НЕ РЕДАКТИРУЙТЕ СОДЕРЖИМОЕ ЭТОГО ФАЙЛА
 * Если задача вышла за рамки генератора,
 * то отредактируйте шаблон в папке generator/templates/frontend/add-modal.blade.php
 */
import Icon from "@/components/IconComponent.vue";

export default {
  name: "AddModal",
  components: { Icon },
  data() {
    return {
      list: {!! $list !!},
    };
  },
  methods: {
    canShowLink(roles) {
      if (window.user && window.user.roles) {
        return roles.some((r) => r === "*" || window.user.roles.map((r) => r.name).includes(r));
      }
      return false;
    },
  },
};
</script>

<style lang="stylus">
@import "~@/styles/global/colors.styl"
.add-modal {
  &__list {
    display grid
    width 100%

    li {
      display flex
      width 100%

      &:not(:last-child) {
        border-bottom 1px solid rgba($dark, .2)
      }

      a {
        width 100%
        padding 5px 15px
        color $dark
        font-size 1em

        &:hover {
          background rgba($main_color, .2)
        }
      }
    }
  }
}
</style>
