changeStatus(id, status, table) {
  if (!this.loading) {
    this.loading = true;
    this.$store
      .dispatch("CHANGE_STATUS", {
        apollo: this.$apollo,
        variables: {
          items: [
            {
              id: id,
              code: status,
              table_name: table,
            },
          ],
        },
      })
      .then(({ data }) => {
        if (data && data.ChangeStatus) {
          this.$notify({
            title: "Готово",
            text: data.ChangeStatus.message,
            type: "success",
          });
        }
        this.$nextTick(this.submit);
        this.loading = false;
      })
      .catch(() => {
        this.loading = false;
      });
  }
},
