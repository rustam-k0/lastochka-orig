<template>
  <template v-if="fieldsStore.isEnabled('Shop', 'employees_table')">
    <StoreCreateTable :rows="staffs" :loading="loading" />
  </template>
</template>

<script>
import StoreCreateTable from "@/views/stores/components/StoreCreateTable.vue";
import { useFieldVisibilityConfigStore } from "@/stores/fieldVisibilityConfig.js";

export default {
  name: "EmployeesTab",
  components: { StoreCreateTable },
  props: {
    staffs: {
      type: Array,
      default: () => [],
    },
    loading: {
      type: Boolean,
      default: false,
    },
  },
  computed: {
    fieldsStore() {
      return useFieldVisibilityConfigStore();
    },
  },
};
</script>
