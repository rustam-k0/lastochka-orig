import Vue from "vue";
import VueApollo from "vue-apollo";
import { ApolloClient } from "apollo-client";
import { InMemoryCache } from "apollo-cache-inmemory";
import { createUploadLink } from "apollo-upload-client";

import { uploadFetch } from "./custom-fetch.js";

const customFetch = (uri, options) => {
  if (options.useUpload) {
    return uploadFetch(uri, options);
  }
  return fetch(uri, options);
};

Vue.use(VueApollo);

export default function createApollo() {
  return new VueApollo({
    defaultClient: new ApolloClient({
      link: createUploadLink({
        uri: location.origin + "/graphql",
        fetch: customFetch,
        headers: {
          Authorization: "Bearer " + Vue.$cookies.get("admin-token"),
        },
      }),
      cache: new InMemoryCache({
        addTypename: false,
        resultCaching: false,
      }),
    }),
  });
}
