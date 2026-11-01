var d={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"title"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"description"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"shop_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"discount_percent"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"starts_at"}},type:{kind:"NamedType",name:{kind:"Name",value:"DateTime"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"ends_at"}},type:{kind:"NamedType",name:{kind:"Name",value:"DateTime"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_active"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"image"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"product_ids"}},type:{kind:"ListType",type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"updateMerchantSale"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}},{kind:"Argument",name:{kind:"Name",value:"input"},value:{kind:"ObjectValue",fields:[{kind:"ObjectField",name:{kind:"Name",value:"title"},value:{kind:"Variable",name:{kind:"Name",value:"title"}}},{kind:"ObjectField",name:{kind:"Name",value:"description"},value:{kind:"Variable",name:{kind:"Name",value:"description"}}},{kind:"ObjectField",name:{kind:"Name",value:"shop_id"},value:{kind:"Variable",name:{kind:"Name",value:"shop_id"}}},{kind:"ObjectField",name:{kind:"Name",value:"discount_percent"},value:{kind:"Variable",name:{kind:"Name",value:"discount_percent"}}},{kind:"ObjectField",name:{kind:"Name",value:"starts_at"},value:{kind:"Variable",name:{kind:"Name",value:"starts_at"}}},{kind:"ObjectField",name:{kind:"Name",value:"ends_at"},value:{kind:"Variable",name:{kind:"Name",value:"ends_at"}}},{kind:"ObjectField",name:{kind:"Name",value:"is_active"},value:{kind:"Variable",name:{kind:"Name",value:"is_active"}}},{kind:"ObjectField",name:{kind:"Name",value:"image"},value:{kind:"Variable",name:{kind:"Name",value:"image"}}},{kind:"ObjectField",name:{kind:"Name",value:"product_ids"},value:{kind:"Variable",name:{kind:"Name",value:"product_ids"}}},{kind:"ObjectField",name:{kind:"Name",value:"custom_checkboxes"},value:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}}}]}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:621}};d.loc.source={body:`mutation (
  $id: Int!
  $title: String
  $description: String
  $shop_id: Int
  $discount_percent: Int
  $starts_at: DateTime
  $ends_at: DateTime
  $is_active: Boolean
  $image: ImgInput
  $product_ids: [Int!]
  $custom_checkboxes: String
) {
  updateMerchantSale(
    id: $id
    input: {
      title: $title
      description: $description
      shop_id: $shop_id
      discount_percent: $discount_percent
      starts_at: $starts_at
      ends_at: $ends_at
      is_active: $is_active
      image: $image
      product_ids: $product_ids
      custom_checkboxes: $custom_checkboxes
    }
  ) {
    id
    title
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function t(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var a=e.type;a.kind==="NamedType"&&i.add(a.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(n){t(n,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(n){t(n,i)}),e.definitions&&e.definitions.forEach(function(n){t(n,i)})}var l={};(function(){d.definitions.forEach(function(i){if(i.name){var a=new Set;t(i,a),l[i.name.value]=a}})})();export{d};
