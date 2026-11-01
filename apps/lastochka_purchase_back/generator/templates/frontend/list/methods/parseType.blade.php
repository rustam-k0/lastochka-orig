parseType(row) {
  if (row && row.status) {
    return this.types.find((type) => type.id === row.status.table_name);
  }
  return {};
},
