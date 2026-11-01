<template>
  <div class="action-menu">
    <span @click="$emit('update')">Редактировать</span>
    <div class="action-menu_line"></div>
    <span @click="$emit('remove')" class="action-menu--red">Удалить</span>
  </div>
</template>

<script>
export default {
  name: "ActionMenu",
};
</script>

<style lang="stylus">
.action-menu {
  display: flex;
  position absolute
  right: 32px;
  top -33px
  flex-direction column
  background: var(--white);
  border-radius: 8px;
  width: 142px;
  transition var(--transition)
  z-index: 4;
  opacity: 0;
  visibility: hidden;
  filter: drop-shadow(0px 30px 30px rgba(0, 0, 0, 0.07)) drop-shadow(0px 8px 10px rgba(0, 0, 0, 0.035));

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
    background #E1EAEF;
  }

  &--red {
    color: var(--red-dark-2) !important
  }
}
</style>
