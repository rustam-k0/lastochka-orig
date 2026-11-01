var l={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"title"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"description"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"shop_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"show_on_main"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"show_on_merchant_page"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"show_in_merchant_catalog"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"show_on_search"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"sort_order"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_active"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_recommended"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_cart_page"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_complex_solution"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"image"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"product_ids"}},type:{kind:"ListType",type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"products_with_qty"}},type:{kind:"ListType",type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"ProductGroupItemInput"}}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"filter_value_ids"}},type:{kind:"ListType",type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"fill_type"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"updateMerchantProductGroup"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}},{kind:"Argument",name:{kind:"Name",value:"input"},value:{kind:"ObjectValue",fields:[{kind:"ObjectField",name:{kind:"Name",value:"title"},value:{kind:"Variable",name:{kind:"Name",value:"title"}}},{kind:"ObjectField",name:{kind:"Name",value:"description"},value:{kind:"Variable",name:{kind:"Name",value:"description"}}},{kind:"ObjectField",name:{kind:"Name",value:"shop_id"},value:{kind:"Variable",name:{kind:"Name",value:"shop_id"}}},{kind:"ObjectField",name:{kind:"Name",value:"show_on_main"},value:{kind:"Variable",name:{kind:"Name",value:"show_on_main"}}},{kind:"ObjectField",name:{kind:"Name",value:"show_on_merchant_page"},value:{kind:"Variable",name:{kind:"Name",value:"show_on_merchant_page"}}},{kind:"ObjectField",name:{kind:"Name",value:"show_in_merchant_catalog"},value:{kind:"Variable",name:{kind:"Name",value:"show_in_merchant_catalog"}}},{kind:"ObjectField",name:{kind:"Name",value:"show_on_search"},value:{kind:"Variable",name:{kind:"Name",value:"show_on_search"}}},{kind:"ObjectField",name:{kind:"Name",value:"sort_order"},value:{kind:"Variable",name:{kind:"Name",value:"sort_order"}}},{kind:"ObjectField",name:{kind:"Name",value:"is_active"},value:{kind:"Variable",name:{kind:"Name",value:"is_active"}}},{kind:"ObjectField",name:{kind:"Name",value:"is_recommended"},value:{kind:"Variable",name:{kind:"Name",value:"is_recommended"}}},{kind:"ObjectField",name:{kind:"Name",value:"is_cart_page"},value:{kind:"Variable",name:{kind:"Name",value:"is_cart_page"}}},{kind:"ObjectField",name:{kind:"Name",value:"is_complex_solution"},value:{kind:"Variable",name:{kind:"Name",value:"is_complex_solution"}}},{kind:"ObjectField",name:{kind:"Name",value:"image"},value:{kind:"Variable",name:{kind:"Name",value:"image"}}},{kind:"ObjectField",name:{kind:"Name",value:"product_ids"},value:{kind:"Variable",name:{kind:"Name",value:"product_ids"}}},{kind:"ObjectField",name:{kind:"Name",value:"products_with_qty"},value:{kind:"Variable",name:{kind:"Name",value:"products_with_qty"}}},{kind:"ObjectField",name:{kind:"Name",value:"filter_value_ids"},value:{kind:"Variable",name:{kind:"Name",value:"filter_value_ids"}}},{kind:"ObjectField",name:{kind:"Name",value:"custom_checkboxes"},value:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}}},{kind:"ObjectField",name:{kind:"Name",value:"fill_type"},value:{kind:"Variable",name:{kind:"Name",value:"fill_type"}}}]}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_recommended"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_cart_page"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_complex_solution"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_active"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"show_on_main"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"show_on_merchant_page"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"show_in_merchant_catalog"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"show_on_search"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"fill_type"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:1413}};l.loc.source={body:`mutation (
  $id: Int!
  $title: String
  $description: String
  $shop_id: Int
  $show_on_main: Boolean
  $show_on_merchant_page: Boolean
  $show_in_merchant_catalog: Boolean
  $show_on_search: Boolean
  $sort_order: Int
  $is_active: Boolean
  $is_recommended: Boolean
  $is_cart_page: Boolean
  $is_complex_solution: Boolean
  $image: ImgInput
  $product_ids: [Int!]
  $products_with_qty: [ProductGroupItemInput!]
  $filter_value_ids: [Int!]
  $custom_checkboxes: String
  $fill_type: String
) {
  updateMerchantProductGroup(
    id: $id
    input: {
      title: $title
      description: $description
      shop_id: $shop_id
      show_on_main: $show_on_main
      show_on_merchant_page: $show_on_merchant_page
      show_in_merchant_catalog: $show_in_merchant_catalog
      show_on_search: $show_on_search
      sort_order: $sort_order
      is_active: $is_active
      is_recommended: $is_recommended
      is_cart_page: $is_cart_page
      is_complex_solution: $is_complex_solution
      image: $image
      product_ids: $product_ids
      products_with_qty: $products_with_qty
      filter_value_ids: $filter_value_ids
      custom_checkboxes: $custom_checkboxes
      fill_type: $fill_type
    }
  ) {
    id
    title
    is_recommended
    is_cart_page
    is_complex_solution
    is_active
    show_on_main
    show_on_merchant_page
    show_in_merchant_catalog
    show_on_search
    fill_type
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function d(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var a=e.type;a.kind==="NamedType"&&i.add(a.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(n){d(n,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(n){d(n,i)}),e.definitions&&e.definitions.forEach(function(n){d(n,i)})}var t={};(function(){l.definitions.forEach(function(i){if(i.name){var a=new Set;d(i,a),t[i.name.value]=a}})})();export{l as d};
