<template>
  <div class="default-modal barcode-modal">
    <div class="default-modal__header">
      <span class="default-modal__title">Штрихкод</span>
      <button type="button" class="default-modal__close" @click="$emit('close')">
        <IconComponent name="close-fill" />
      </button>
    </div>
    <div class="default-modal__body barcode-modal__body">
      <span v-if="title" class="barcode-modal__product">{{ title }}</span>
      <div class="barcode-modal__canvas-wrap">
        <canvas ref="canvas" v-show="!error"></canvas>
        <span v-if="error" class="barcode-modal__error">
          Не удалось сгенерировать штрихкод для значения «{{ code }}»
        </span>
      </div>
    </div>
    <div class="default-modal__footer">
      <button type="button" @click="$emit('close')" class="btn btn--gray btn--md">Закрыть</button>
      <button v-if="!error" type="button" @click="download" class="btn btn--main btn--md">
        <IconComponent name="download-2-line" />
        <span>Скачать PNG</span>
      </button>
    </div>
  </div>
</template>

<script>
import JsBarcode from "jsbarcode";
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "BarcodeModal",
  components: {
    IconComponent,
  },
  props: {
    code: {
      type: [String, Number],
      required: true,
    },
    title: {
      type: String,
      default: "",
    },
  },
  data() {
    return {
      error: false,
    };
  },
  mounted() {
    this.render();
  },
  methods: {
    tryRender(format) {
      let valid = true;
      try {
        JsBarcode(this.$refs.canvas, String(this.code).trim(), {
          format,
          width: 3,
          height: 120,
          displayValue: true,
          fontSize: 20,
          textMargin: 6,
          margin: 16,
          background: "#ffffff",
          lineColor: "#000000",
          valid: (ok) => {
            valid = ok;
          },
        });
      } catch (e) {
        valid = false;
      }
      return valid;
    },
    render() {
      const value = String(this.code).trim();
      // товарные штрихкоды — EAN-13; всё остальное (артикулы, внутренние коды) — CODE128
      if (/^\d{13}$/.test(value) && this.tryRender("EAN13")) {
        return;
      }
      this.error = !this.tryRender("CODE128");
    },
    download() {
      const link = document.createElement("a");
      link.href = this.$refs.canvas.toDataURL("image/png");
      link.download = `barcode-${String(this.code).trim()}.png`;
      link.click();
    },
  },
};
</script>

<style lang="stylus">
.barcode-modal {
  width 100%
  max-width: 480px;

  &__body {
    display flex
    flex-direction column
    align-items center
    gap 12px
  }

  &__product {
    font-size 0.875rem
    color var(--dark-light)
    text-align center
  }

  &__canvas-wrap {
    display flex
    justify-content center
    align-items center
    width 100%
    min-height 120px
    background var(--white)

    canvas {
      max-width 100%
    }
  }

  &__error {
    font-size 0.875rem
    color var(--red, #dc2626)
    text-align center
  }
}
</style>
