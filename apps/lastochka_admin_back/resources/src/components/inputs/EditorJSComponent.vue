<template>
  <div class="editor__container">
    <div
      :class="{
        'editor--error': errors.length,
      }"
      class="editor editor--default"
    >
      <span class="editor__title">{{ title }}</span>
      <span ref="editor" class="editor__field"></span>
    </div>
    <ul v-if="errors && errors.length" class="editor__errors">
      <li v-for="(e, i) in errors" :key="i">{{ e }}</li>
    </ul>
    <ul v-if="messages && messages.length" class="editor__errors editor__errors--messages">
      <li v-for="(m, i) in messages" :key="i">{{ m }}</li>
    </ul>
  </div>
</template>

<script>
export default {
  name: "EditorJSComponent",
  props: {
    title: {
      type: String,
      required: true,
    },
    placeholder: {
      type: String,
      default() {
        return "Ваша статья";
      },
    },
    errors: {
      type: Array,
      default() {
        return [];
      },
    },
    messages: {
      type: Array,
      default() {
        return [];
      },
    },
    rows: {
      type: [Number, String],
      default() {
        return 3;
      },
    },
    value: [Object, String],
    autofocus: Boolean,
  },
  data() {
    return {
      editor: null,
      focused: false,
      text: null,
    };
  },
  mounted() {
    this.init(this.value);
  },
  methods: {
    save() {
      return this.editor.save();
    },
    init(value) {
      this.text = value;
      if (this.editor) {
        this.editor.destroy();
      }
      const EditorJS = require("@editorjs/editorjs");
      const Header = require("@editorjs/header");
      const ImageTool = require("@editorjs/image");
      const Embed = require("@editorjs/embed");
      const Quote = require("@editorjs/quote");
      const CodeTool = require("@editorjs/code");
      const LinkTool = require("@editorjs/link");
      const List = require("@editorjs/list");
      const Delimiter = require("@editorjs/delimiter");
      const RawTool = require("@editorjs/raw");
      const Warning = require("@editorjs/warning");
      const Table = require("@editorjs/table");
      const Marker = require("@editorjs/marker");
      const InlineCode = require("@editorjs/inline-code");
      const Paragraph = require("@editorjs/paragraph");
      const Carousel = require("@vtchinh/gallery-editorjs");
      this.editor = new EditorJS({
        holder: this.$refs.editor,
        logLevel: "ERROR",
        placeholder: this.placeholder,
        onChange: (e) => {
          e.saver.save().then((res) => {
            this.$emit("input", res);
          });
        },
        data: JSON.parse(this.text),
        tools: {
          paragraph: {
            class: Paragraph,
            inlineToolbar: true,
          },
          header: {
            class: Header,
            config: {
              placeholder: "Введите заголовок",
              levels: [2, 3, 4, 5, 6],
              defaultLevel: 2,
            },
          },
          embed: {
            class: Embed,
            config: {
              services: {
                instagram: true,
                vimeo: true,
                coub: true,
                gfycat: true,
                "yandex-music-track": true,
                "yandex-music-album": true,
                "yandex-music-playlist": true,
                twitter: true,
                facebook: true,
                youtube: true,
              },
            },
          },
          code: CodeTool,
          quote: Quote,
          delimiter: Delimiter,
          raw: RawTool,
          warning: Warning,
          table: Table,
          marker: Marker,
          inlineCode: InlineCode,
          list: List,
          linkTool: {
            class: LinkTool,
            config: {
              endpoint: location.origin + "/action/fetchlink",
              headers: {
                Authorization: "Bearer " + this.$cookies.get("admin-token"),
              },
            },
          },
          carousel: {
            class: Carousel,
            config: {
              endpoints: {
                byFile: location.origin + "/action/uploadeditor",
              },
              additionalRequestHeaders: {
                Authorization: "Bearer " + this.$cookies.get("admin-token"),
              },
              field: "image",
              types: "image/*",
            },
          },
          image: {
            class: ImageTool,
            config: {
              endpoints: {
                byFile: location.origin + "/action/uploadeditor",
                byUrl: location.origin + "/action/uploadeditor",
              },
              additionalRequestHeaders: {
                Authorization: "Bearer " + this.$cookies.get("admin-token"),
              },
            },
          },
        },
        i18n: {
          messages: {
            ui: {
              blockTunes: {
                toggler: {
                  "Click to tune": "Нажмите, чтобы настроить",
                  "or drag to move": "или перетащите",
                },
              },
              inlineToolbar: {
                converter: {
                  "Convert to": "Конвертировать в",
                },
              },
              toolbar: {
                toolbox: {
                  Add: "Добавить",
                },
              },
            },
            toolNames: {
              Text: "Параграф",
              Heading: "Заголовок",
              List: "Список",
              Carousel: "Галерея",
              Warning: "Примечание",
              Checklist: "Чеклист",
              Quote: "Цитата",
              Code: "Код",
              Delimiter: "Разделитель",
              "Raw HTML": "HTML-фрагмент",
              Table: "Таблица",
              Image: "Изображение",
              SimpleCarousel: "Слайдер изображений",
              Link: "Ссылка",
              Marker: "Маркер",
              Bold: "Полужирный",
              Italic: "Курсив",
              InlineCode: "Моноширинный",
            },
            tools: {
              warning: {
                Title: "Название",
                Message: "Сообщение",
              },
              link: {
                "Add a link": "Вставьте ссылку",
              },
              stub: {
                "The block can not be displayed correctly.": "Блок не может быть отображен",
              },
            },
            blockTunes: {
              delete: {
                Delete: "Удалить",
              },
              moveUp: {
                "Move up": "Переместить вверх",
              },
              moveDown: {
                "Move down": "Переместить вниз",
              },
            },
          },
        },
      });
      // Какие то div в таким классом создаются при каждой инициализации
      document.querySelectorAll("body > .ct").forEach((ct) => {
        ct.remove();
      });
    },
    focus() {
      if (!this.focused) {
        this.focused = true;
      }
      this.$emit("focus");
    },
    blur() {
      if (this.focused) {
        this.focused = false;
      }
      this.$emit("blur");
    },
  },
};
</script>

<style lang="stylus">
@import "~@/styles/elements/input.styl"
.editor {
  @extends .input
  height auto
  display grid

  &__field {
    padding 25px 46px 5px
  }

  .ce-toolbar__content
  .ce-block__content {
    max-width 100%
  }
}
</style>
