import plural from "plural-ru";
// import { useDefaultStore } from "@/stores/default";
import { useAuthStore } from "@/stores/auth.js";

export function resetErrors() {
  Object.keys(this.form).forEach((key) => {
    this.form[key].errors = [];
    if (Array.isArray(this.form[key].value)) {
      this.form[key].value.forEach((k) => {
        if (k.errors) {
          k.errors = [];
        }
        Object.values(k).forEach((item) => {
          if (item && item.errors) {
            item.errors = [];
          }
        });
      });
    }
  });
}

export function firstRouteWithAccess($router) {
  if (useAuthStore().user) {
    let role = useAuthStore().user.role;
    if (role) {
      let allowedEntities = role.entities
        .filter((e) =>
          e.permissions
            .filter((p) => p.allowed)
            .map((p) => p.permission)
            .includes("read")
        )
        .map((e) => e.entity);
      return $router
        .getRoutes()
        .filter((r) => !r.path.includes("/:"))
        .map((r) => ({
          name: r.name,
          entities: r.meta?.entities,
        }))
        .find((r) => {
          if (!r.entities.length) {
            return r;
          }
          return r.entities.filter((e) => {
            return allowedEntities.includes(e);
          }).length;
        });
    }
  }
  return { name: "no-access" };
}

export function haveAccess(entity, permission) {
  if (useAuthStore().user) {
    let role = useAuthStore().user.role;
    if (role) {
      let accesses = role.entities.map((e) => ({
        entity: e.entity,
        permissions: e.permissions.filter((p) => p.allowed).map((p) => p.permission),
      }));
      if (accesses.find((a) => a.entity === entity)) {
        return accesses.find((a) => a.entity === entity).permissions.includes(permission);
      }
    }
  }
  return false;
}

export function parseGqlErrors(graphQLErrors) {
  graphQLErrors.forEach((err) => {
    if (err.extensions.category === "validation") {
      Object.keys(err.extensions.validation).forEach((key) => {
        let arr = key.split(".");
        if (arr.length <= 1) {
          if (this.form[key]) {
            this.form[key].errors = err.extensions.validation[key];
          }
        } else {
          let [parent, index, child] = arr;
          if (this.form[parent].value[index][child]) {
            this.form[parent].value[index][child].errors = [...err.extensions.validation[key]];
          } else {
            this.form[parent].value[index].errors.push(...err.extensions.validation[key]);
          }
        }
      });
    }
  });
}
const parseHeaders = (rawHeaders) => {
  const headers = new Headers();
  // Replace instances of \r\n and \n followed by at least one space or horizontal tab with a space
  // https://tools.ietf.org/html/rfc7230#section-3.2
  const preProcessedHeaders = rawHeaders.replace(/\r?\n[\t ]+/g, " ");
  preProcessedHeaders.split(/\r?\n/).forEach((line) => {
    const parts = line.split(":");
    const key = parts.shift().trim();
    if (key) {
      const value = parts.join(":").trim();
      headers.append(key, value);
    }
  });
  return headers;
};

export const uploadFetch = (url, options) =>
  new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.onload = () => {
      const opts = {
        status: xhr.status,
        statusText: xhr.statusText,
        headers: parseHeaders(xhr.getAllResponseHeaders() || ""),
      };
      opts.url = "responseURL" in xhr ? xhr.responseURL : opts.headers.get("X-Request-URL");
      const body = "response" in xhr ? xhr.response : xhr.responseText;
      resolve(new Response(body, opts));
    };
    xhr.onerror = () => {
      reject(new TypeError("Network request failed"));
    };
    xhr.ontimeout = () => {
      reject(new TypeError("Network request failed"));
    };
    xhr.open(options.method, url, true);

    Object.keys(options.headers).forEach((key) => {
      xhr.setRequestHeader(key, options.headers[key]);
    });

    if (xhr.upload) {
      xhr.upload.onprogress = options.onProgress;
    }

    options.onAbortPossible(() => {
      xhr.abort();
    });

    xhr.send(options.body);
  });

export function pluralWord(count, ...words) {
  return plural(count, ...words.map((w) => w));
}

export function formatPrice(price) {
  if (price) {
    return (
      parseFloat(price).toLocaleString("ru-RU", {
        maximumFractionDigits: 0,
      }) + " ₽"
    );
  }
  return 0 + " ₽";
}

export function imageUpload(entity, api) {
  if (entity && entity.path) {
    if (entity.path.search(/(http:|https:)/) !== -1) {
      return entity.path;
    } else {
      return api + entity.path;
    }
  }
  return "/public/assets/images/no-image.png";
}

// Разбор JSON-строки динамических чекбоксов (custom_checkboxes) в map-объект.
export function parseCustomCheckboxes(raw) {
  if (!raw) return {};
  try {
    const value = typeof raw === "string" ? JSON.parse(raw) : raw;
    return value && typeof value === "object" && !Array.isArray(value) ? value : {};
  } catch {
    return {};
  }
}

// Транслитерация названия в слаг чекбокса: [a-z][a-z0-9_]*, до 50 символов.
export function transliterate(str) {
  const map = {
    а: "a",
    б: "b",
    в: "v",
    г: "g",
    д: "d",
    е: "e",
    ё: "e",
    ж: "zh",
    з: "z",
    и: "i",
    й: "y",
    к: "k",
    л: "l",
    м: "m",
    н: "n",
    о: "o",
    п: "p",
    р: "r",
    с: "s",
    т: "t",
    у: "u",
    ф: "f",
    х: "h",
    ц: "c",
    ч: "ch",
    ш: "sh",
    щ: "sch",
    ъ: "",
    ы: "y",
    ь: "",
    э: "e",
    ю: "yu",
    я: "ya",
  };
  return String(str)
    .toLowerCase()
    .split("")
    .map((ch) => (map[ch] !== undefined ? map[ch] : ch))
    .join("")
    .replace(/[^a-z0-9_]+/g, "_")
    .replace(/^[^a-z]+/, "")
    .replace(/_+/g, "_")
    .replace(/_$/, "")
    .slice(0, 50);
}

// Ошибка GraphQL «Unauthenticated» (протухший/отозванный токен). На такие
// ошибки глобально реагирует errorLink в apollo.js (сброс токена + редирект
// на логин), поэтому компонентам не нужно показывать по ним свои alert'ы.
export function isAuthenticationError(err) {
  return (err?.graphQLErrors || []).some((e) => e?.extensions?.category === "authentication");
}

// Текст ошибки мутации для alert: сообщения валидации из extensions.validation
// вместо общего «Validation failed for the field [...]» (MID-317).
export function gqlErrorText(err, fallback = "Ошибка сохранения") {
  const messages = (err?.graphQLErrors || [])
    .filter((e) => e?.extensions?.category === "validation")
    .flatMap((e) => Object.values(e.extensions.validation || {}).flat());

  if (messages.length) {
    return messages.join("\n");
  }

  return err?.message || fallback;
}
