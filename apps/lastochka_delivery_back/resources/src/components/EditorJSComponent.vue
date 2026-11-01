<template>
  <div class="article">
    <template v-for="(block, index) in text.blocks">
      <p :key="index" v-if="block.type === 'paragraph'" v-html="block.data.text"></p>
      <h1 :key="index" v-if="block.type === 'header' && block.data.level === 1" v-html="block.data.text"></h1>
      <h2 :key="index" v-if="block.type === 'header' && block.data.level === 2" v-html="block.data.text"></h2>
      <h3 :key="index" v-if="block.type === 'header' && block.data.level === 3" v-html="block.data.text"></h3>
      <h4 :key="index" v-if="block.type === 'header' && block.data.level === 4" v-html="block.data.text"></h4>
      <h5 :key="index" v-if="block.type === 'header' && block.data.level === 5" v-html="block.data.text"></h5>
      <h6 :key="index" v-if="block.type === 'header' && block.data.level === 6" v-html="block.data.text"></h6>
      <pre :key="index" v-if="block.type === 'code'" v-html="block.data.code"></pre>
      <div :key="index" v-if="block.type === 'quote'" class="article__quote">
        <div v-html="block.data.text" class="article__quote-content"></div>
        <span>{{ block.data.caption }}</span>
      </div>
      <hr :key="index" v-if="block.type === 'delimiter'" />
      <div :key="index" v-if="block.type === 'raw'" v-html="block.data.html"></div>
      <div :key="index" v-if="block.type === 'warning'" class="article__warning">
        <span>{{ block.data.title }}</span>
        <p>{{ block.data.message }}</p>
      </div>
      <table :key="index" v-if="block.type === 'table'">
        <tbody>
          <tr v-for="(row, i) in block.data.content" :key="i">
            <td v-for="(column, j) in row" :key="j" v-html="column"></td>
          </tr>
        </tbody>
      </table>
      <ul :key="index" v-if="block.type === 'list' && block.data.style === 'unordered'">
        <li v-for="(item, i) in block.data.items" :key="i" v-html="item"></li>
      </ul>
      <ol :key="index" v-if="block.type === 'list' && block.data.style === 'ordered'">
        <li v-for="(item, i) in block.data.items" :key="i" v-html="item"></li>
      </ol>
      <a
        :key="index"
        v-if="block.type === 'linkTool'"
        :href="block.data.link"
        target="_blank"
        class="article__link"
        rel="nofollow"
      >
        <div>
          <span>{{ block.data.meta.title.length ? block.data.meta.title : block.data.link }}</span>
          <span v-if="block.data.meta.description.length">{{ block.data.meta.description }}</span>
        </div>
        <img
          v-if="block.data.meta.image.url.length"
          :src="block.data.meta.image.url"
          :alt="block.data.meta.title"
        />
      </a>
      <img :src="block.data.file.url" :alt="block.data.caption" v-if="block.type === 'image'" :key="index" />
    </template>
  </div>
</template>

<script>
export default {
  name: "EditorJSComponent",
  props: {
    text: {
      type: Object,
      required: true,
    },
  },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
.article {
  display grid
  width 100%

  > * {
    color: $dark;
    font-family $font_regular
  }

  > p:first-child {
    margin-top 0
  }

  > p {
    font-weight 500
    font-size 1.125em
    line-height: 28px;
  }

  > ul:not([class]),
  > ol:not([class]) {
    list-style: revert;
    padding: revert;
    margin: revert;
  }

  > img {
    border-radius 15px
    width 100%
    height auto
  }

  &__quote {
    width 100%
    display flex
    flex-direction column
    border-left 5px solid $main_color
    padding 20px 0 16px 20px

    &-content {
      font-weight normal
      font-size 1em
      width 100%
      text-align left
      line-height 26px
      color $dark
      margin-bottom 10px
    }

    span {
      font-size 0.875em
      line-height 26px
      text-align right
      color $dark
    }
  }

  &__link {
    display flex
    align-items flex-start
    justify-content flex-start
    box-shadow 0 15px 20px $dark
    border-radius 5px
    padding 10px 15px

    > div {
      display flex
      flex-direction column

      span {
        &:first-child {
          font-size 1.125em
          line-height 26px
          text-align left
          color $dark
        }

        &:last-child {
          font-size 1em
          line-height 26px
          text-align left
          color $dark
          margin-top 10px
        }
      }
    }
  }

  &__warning {
    display flex
    flex-direction column
    border-left 5px solid $red
    padding 20px 0 16px 20px
    width 100%

    p {
      font-weight normal
      font-size 1em
      width 100%
      text-align left
      line-height 26px
      color $dark
      margin 0
    }

    span {
      font-size 1.125em
      line-height 26px
      text-align left
      color $red
      margin-bottom 10px
    }
  }
}
</style>
