import Vue from "vue";
import moment from "moment";

/**
 * Форматирование даты
 */
Vue.filter("formatDate", (date) => {
  return moment(date).format("DD.MM.YYYY");
});

/**
 * Форматирование цены
 */
Vue.filter("prettyPrice", (price) => {
  return Number(price).toLocaleString("ru", {
    maximumFractionDigits: 2,
  });
});

Vue.filter("image", (entity) => {
  if (entity) {
    return location.origin + entity.url;
  }
  return "/distro/images/no-image.jpg";
});

Vue.filter("image_alt", (entity) => {
  if (entity && entity.img) {
    return entity.img.alt;
  }
  return "";
});

Vue.filter("getArticle", (item, name = "description") => {
  if (item) {
    try {
      let json = JSON.parse(item[name]);
      if (typeof json === "string") {
        json = JSON.parse(json);
      }
      if (json.blocks) {
        return json;
      }
      return {
        blocks: [
          {
            type: "paragraph",
            data: { text: item[name] },
          },
        ],
      };
    } catch {
      return {
        blocks: [
          {
            type: "paragraph",
            data: { text: item[name] },
          },
        ],
      };
    }
  }
  return {
    blocks: [],
  };
});
