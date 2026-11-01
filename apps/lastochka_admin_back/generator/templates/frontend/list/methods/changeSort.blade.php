changeSort(event) {
  this.ordering = [];
  event.forEach((column) => {
    if (column.type.toUpperCase() !== "NONE") {
      this.ordering.push({
        orderColumn: column.field,
        orderBy: column.type.toUpperCase(),
      });
    }
  });
  this.submit();
},
