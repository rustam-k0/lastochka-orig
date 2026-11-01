<script>
import { h, ref, watch } from "vue";
import InlineSvg from "vue-inline-svg";

export default {
  name: "IconComponent",
  props: {
    name: String,
    category: {
      type: String,
      default: "remix",
    },
    // Показывается вместо `name`, если его SVG не нашёлся (404/битое имя).
    fallback: {
      type: String,
      default: "question-line",
    },
  },
  setup(props) {
    const failed = ref(false);
    watch(
      () => props.name,
      () => {
        failed.value = false;
      }
    );

    return () => {
      const name = failed.value ? props.fallback : props.name;
      return h(
        "figure",
        {
          class: `icon ${props.category}-${name}-icon`,
        },
        h(InlineSvg, {
          src: `/public/assets/svg/${props.category}/${name}.svg`,
          onError: () => {
            failed.value = true;
          },
        })
      );
    };
  },
};
</script>
