import ChangeStatus from "@/graphql/mutations/ChangeStatus.graphql";

async function CHANGE_STATUS(_, { apollo, variables }) {
  apollo.provider.defaultClient.resetStore();
  return await apollo.mutate({
    mutation: ChangeStatus,
    variables: variables,
  });
}

export default {
  CHANGE_STATUS,
};
