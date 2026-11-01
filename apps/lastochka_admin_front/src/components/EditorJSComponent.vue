<template>
  <div class="editor__container">
    <div
      :class="{
        'editor--error': errors.length,
      }"
      class="editor editor--default"
    >
      <span class="editor__title">{{ title }}</span>
      <div :id="holderId" class="editor__field"></div>
    </div>
    <ul v-if="errors && errors.length" class="editor__errors">
      <li v-for="(e, i) in errors" :key="i">{{ e }}</li>
    </ul>
    <ul
      v-if="messages && messages.length"
      class="editor__errors editor__errors--messages"
    >
      <li v-for="(m, i) in messages" :key="i">{{ m }}</li>
    </ul>
  </div>
</template>

<script>
import EditorJS from "@editorjs/editorjs";
import Header from "@editorjs/header";
import ImageTool from "@editorjs/image";
import Embed from "@editorjs/embed";
import Quote from "@editorjs/quote";
import CodeTool from "@editorjs/code";
import LinkTool from "@editorjs/link";
import List from "@editorjs/list";
import Delimiter from "@editorjs/delimiter";
import RawTool from "@editorjs/raw";
import Warning from "@editorjs/warning";
import Table from "@editorjs/table";
import Marker from "@editorjs/marker";
import InlineCode from "@editorjs/inline-code";
import Paragraph from "@editorjs/paragraph";
import Cookies from "js-cookie";
export default {
  emits: ["update:model-value", "blur", "focus"],
  name: "EditorJSComponent",
  props: {
    title: {
      type: String,
      required: false,
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
    modelValue: [Object, String],
    autofocus: Boolean,
  },
  data() {
    return {
      editor: null,
      focused: false,
      text: null,
      isUpdating: false,
      holderId: "editorjs-" + Math.random().toString(36).substring(2, 9),
    };
  },
  mounted() {
    this.init(this.modelValue);
  },
  watch: {
    modelValue(newValue) {
      if (!this.editor) return;

      this.updateEditor(newValue);
    },
  },
  methods: {
    save() {
      return this.editor.save();
    },
    init(modelValue) {
      this.text = modelValue;
      if (this.editor) {
        this.editor.destroy();
      }
      this.editor = new EditorJS({
        holder: this.holderId,
        logLevel: "ERROR",
        placeholder: this.placeholder,
        onChange: this.changeHandler,
        onReady: this.changeHandler,
        data: this.parse(this.text),
        tools: {
          paragraph: {
            class: Paragraph,
            inlineToolbar: true,
          },
          header: Header,
          embed: {
            class: Embed,
            inlineToolbar: true,
            config: {
              services: {
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
              endpoint:
                import.meta.env.VITE_HOST_ENDPOINT + "/action/fetchlink",
              additionalRequestHeaders: {
                Authorization: "Bearer " + Cookies.get("token"),
              },
            },
          },
          image: {
            class: ImageTool,
            config: {
              endpoints: {
                byFile:
                  import.meta.env.VITE_HOST_ENDPOINT + "/action/uploadeditor",
                byUrl:
                  import.meta.env.VITE_HOST_ENDPOINT + "/action/uploadeditor",
              },
              additionalRequestHeaders: {
                Authorization: "Bearer " + Cookies.get("token"),
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
                "The block can not be displayed correctly.":
                  "Блок не может быть отображен",
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
    parse(text) {
      if (text) {
        // Если это уже объект, возвращаем как есть
        if (typeof text === "object") {
          return text;
        }
        // Если это строка, пытаемся парсить как JSON
        try {
          return JSON.parse(text);
        } catch {
          return {
            time: Date.now(),
            blocks: [{ type: "paragraph", data: { text: text } }],
          };
        }
      }
      return null;
    },
    changeHandler() {
      if (this.isUpdating) return;

      this.editor.saver.save().then((res) => {
        this.text = res;
        this.$emit("update:model-value", res);
      });
    },
    async updateEditor(newData) {
      const parsed = this.parse(newData);

      // защита от лишнего render
      if (JSON.stringify(parsed) === JSON.stringify(this.text)) {
        return;
      }

      this.isUpdating = true;

      this.text = parsed;

      if (this.editor && this.editor.render) {
        await this.editor.render(parsed);
      }

      setTimeout(() => {
        this.isUpdating = false;
      }, 0);
    },
  },
};
</script>

<style lang="stylus">
@import "~@/styles/parts/input.styl"
.editor {
  height auto
  display grid

  &--default {
    width 100%
    display flex
    flex-direction column
    gap 8px
  }

  &__title {
    font-weight: 400;
    font-size: 0.8125em;
    line-height: 20px;
    color: var(--dark);
  }

  .codex-editor__redactor {
    padding-bottom 60px !important
  }

  &__field {
    padding 16px 46px 5px
    border: 1px solid var(--white-2);
    border-radius: 6px;
    font-weight: 500;
    font-size: 0.875rem
    line-height: 16px;
    color: #222128;
  }

  .ce-toolbar__content
  .ce-block__content {
    max-width 100%
  }
}
</style>
