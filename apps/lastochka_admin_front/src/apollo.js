import { ApolloClient, InMemoryCache, ApolloLink } from "@apollo/client/core";
import { BatchHttpLink } from "@apollo/client/link/batch-http";
import { createUploadLink } from "apollo-upload-client";
import { setContext } from '@apollo/client/link/context';
import { onError } from "@apollo/client/link/error";
import { uploadFetch, isAuthenticationError } from "./utils.js";
import Cookies from "js-cookie";

const customFetch = (uri, options) => {
  if (options.useUpload) {
    return uploadFetch(uri, options);
  }
  return fetch(uri, options);
};

// Файл в variables (File/Blob) означает multipart-запрос через uploadLink —
// такие операции нельзя класть в JSON-батч.
const containsFile = (value) => {
  if (typeof File !== "undefined" && value instanceof File) return true;
  if (typeof Blob !== "undefined" && value instanceof Blob) return true;
  if (Array.isArray(value)) return value.some(containsFile);
  if (value && typeof value === "object") return Object.values(value).some(containsFile);
  return false;
};

// Протухший или отозванный токен бэкенд отдаёт как GraphQL-ошибку
// category=authentication при HTTP 200. Без глобального перехвата SPA остаётся
// «зомби»: me и списки рендерятся из кеша, а любые сохранения молча падают
// Unauthenticated. Поэтому чистим токен и уводим на форму входа.
const errorLink = onError(({ graphQLErrors }) => {
  if (!isAuthenticationError({ graphQLErrors })) return;
  if (window.location.pathname.startsWith("/admin/login")) return;
  Cookies.remove("admin_token");
  window.location.href = "/admin/login";
});
export default function createApollo() {
  const host = import.meta.env.VITE_HOST_ENDPOINT;
  const cache = new InMemoryCache({
    addTypename: false,
  });

  const authLink = setContext((_, { headers }) => {
    const token = Cookies.get("admin_token");
    return {
      headers: {
        ...headers,
        authorization: token ? `Bearer ${token}` : "",
      }
    }
  });

  const uploadLink = createUploadLink({
    uri: host + "/graphql-admin",
    fetch: customFetch,
  });

  // Основной клиент собирает burst из 5-10 boot-запросов в один HTTP-запрос
  // (Lighthouse: batched_queries=true) — оверхед бэкенда платится один раз.
  // Загрузки файлов (useUpload или File/Blob в variables) идут мимо батча
  // через uploadLink (multipart).
  const batchLink = new BatchHttpLink({
    uri: host + "/graphql-admin",
    batchMax: 10,
    batchInterval: 15,
  });

  const adminTerminatingLink = ApolloLink.split(
    (operation) => {
      const { fetchOptions } = operation.getContext();
      return Boolean(fetchOptions && fetchOptions.useUpload) || containsFile(operation.variables);
    },
    uploadLink,
    batchLink
  );

  const adminPanel = new ApolloClient({
    link: errorLink.concat(authLink).concat(adminTerminatingLink),
    cache,
  });

  const importerAdmin = new ApolloClient({
    link: errorLink.concat(
      createUploadLink({
        uri: host + "/graphql-importer",
        fetch: customFetch,
        headers: {
          Authorization: "Bearer " + Cookies.get("admin_token"),
        },
      })
    ),
    cache,
  });
  const purchaseAdmin = new ApolloClient({
    link: errorLink.concat(
      createUploadLink({
        uri: host + "/graphql-purchase",
        fetch: customFetch,
        headers: {
          Authorization: "Bearer " + Cookies.get("admin_token"),
        },
      })
    ),
    cache,
  });

  const deliveryAdmin = new ApolloClient({
    link: errorLink.concat(
      createUploadLink({
        uri: host + "/graphql-delivery",
        fetch: customFetch,
        headers: {
          Authorization: "Bearer " + Cookies.get("admin_token"),
        },
      })
    ),
    cache,
  });

  const loyalOnline = new ApolloClient({
    link: errorLink.concat(
      createUploadLink({
        uri: host + "/graphql-loyal-online",
        fetch: customFetch,
        headers: {
          Authorization: "Bearer " + Cookies.get("admin_token"),
        },
      })
    ),
    cache,
  });

  return {
    admin: adminPanel,
    importer: importerAdmin,
    purchase: purchaseAdmin,
    delivery: deliveryAdmin,
    defaultClient: adminPanel,
    loyalOnline: loyalOnline,
  };
}
