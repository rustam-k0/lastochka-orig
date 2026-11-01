showModal(name, object) {
  this.$store.state._modals.push({
    component: () => import(`@/components/modals/components/generated/${name}.vue`),
    options: {
      object: object,
    },
  });
},
