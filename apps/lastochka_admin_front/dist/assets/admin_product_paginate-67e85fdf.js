var l={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"page"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"first"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"title"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"ordering"}},type:{kind:"ListType",type:{kind:"NamedType",name:{kind:"Name",value:"DefaultOrdering"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"shop_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"available_in_shop_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"status_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"categories"}},type:{kind:"ListType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_configurable"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"exclude_children"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"only_children"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"has_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"has_reviews"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"fill_percent_lt"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"fill_percent_gte"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_new"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_hurry_up"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"selected_shop_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"admin_products_paginate"},arguments:[{kind:"Argument",name:{kind:"Name",value:"page"},value:{kind:"Variable",name:{kind:"Name",value:"page"}}},{kind:"Argument",name:{kind:"Name",value:"first"},value:{kind:"Variable",name:{kind:"Name",value:"first"}}},{kind:"Argument",name:{kind:"Name",value:"title"},value:{kind:"Variable",name:{kind:"Name",value:"title"}}},{kind:"Argument",name:{kind:"Name",value:"ordering"},value:{kind:"Variable",name:{kind:"Name",value:"ordering"}}},{kind:"Argument",name:{kind:"Name",value:"shop_id"},value:{kind:"Variable",name:{kind:"Name",value:"shop_id"}}},{kind:"Argument",name:{kind:"Name",value:"available_in_shop_id"},value:{kind:"Variable",name:{kind:"Name",value:"available_in_shop_id"}}},{kind:"Argument",name:{kind:"Name",value:"status_id"},value:{kind:"Variable",name:{kind:"Name",value:"status_id"}}},{kind:"Argument",name:{kind:"Name",value:"categories"},value:{kind:"Variable",name:{kind:"Name",value:"categories"}}},{kind:"Argument",name:{kind:"Name",value:"is_configurable"},value:{kind:"Variable",name:{kind:"Name",value:"is_configurable"}}},{kind:"Argument",name:{kind:"Name",value:"exclude_children"},value:{kind:"Variable",name:{kind:"Name",value:"exclude_children"}}},{kind:"Argument",name:{kind:"Name",value:"only_children"},value:{kind:"Variable",name:{kind:"Name",value:"only_children"}}},{kind:"Argument",name:{kind:"Name",value:"has_img"},value:{kind:"Variable",name:{kind:"Name",value:"has_img"}}},{kind:"Argument",name:{kind:"Name",value:"has_reviews"},value:{kind:"Variable",name:{kind:"Name",value:"has_reviews"}}},{kind:"Argument",name:{kind:"Name",value:"fill_percent_lt"},value:{kind:"Variable",name:{kind:"Name",value:"fill_percent_lt"}}},{kind:"Argument",name:{kind:"Name",value:"fill_percent_gte"},value:{kind:"Variable",name:{kind:"Name",value:"fill_percent_gte"}}},{kind:"Argument",name:{kind:"Name",value:"is_new"},value:{kind:"Variable",name:{kind:"Name",value:"is_new"}}},{kind:"Argument",name:{kind:"Name",value:"is_hurry_up"},value:{kind:"Variable",name:{kind:"Name",value:"is_hurry_up"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"data"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"articul"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"description"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"fill_percent"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"default_price_value"},arguments:[{kind:"Argument",name:{kind:"Name",value:"shop_id"},value:{kind:"Variable",name:{kind:"Name",value:"selected_shop_id"}}}],directives:[]},{kind:"Field",name:{kind:"Name",value:"remains_sum"},arguments:[{kind:"Argument",name:{kind:"Name",value:"shop_id"},value:{kind:"Variable",name:{kind:"Name",value:"selected_shop_id"}}}],directives:[]},{kind:"Field",name:{kind:"Name",value:"effective_stock_mode"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"effective_fixed_stock"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"base_unit_type_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"info_weight_type_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"categories"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"status_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_configurable"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"parent_id"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"paginatorInfo"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"total"},arguments:[],directives:[]}]}}]}}]}}],loc:{start:0,end:1417}};l.loc.source={body:`query (
  $page: Int
  $first: Int
  $title: String
  $ordering: [DefaultOrdering]
  $shop_id: Int
  $available_in_shop_id: Int
  $status_id: Int
  $categories: [Int]
  $is_configurable: Boolean
  $exclude_children: Boolean
  $only_children: Boolean
  $has_img: Boolean
  $has_reviews: Boolean
  $fill_percent_lt: Int
  $fill_percent_gte: Int
  $is_new: Boolean
  $is_hurry_up: Boolean
  $selected_shop_id: Int
) {
  admin_products_paginate(
    page: $page
    first: $first
    title: $title
    ordering: $ordering
    shop_id: $shop_id
    available_in_shop_id: $available_in_shop_id
    status_id: $status_id
    categories: $categories
    is_configurable: $is_configurable
    exclude_children: $exclude_children
    only_children: $only_children
    has_img: $has_img
    has_reviews: $has_reviews
    fill_percent_lt: $fill_percent_lt
    fill_percent_gte: $fill_percent_gte
    is_new: $is_new
    is_hurry_up: $is_hurry_up
  ) {
    data {
      id
      articul
      title
      description
      fill_percent
      img {
        path
      }
      default_price_value(shop_id: $selected_shop_id)
      remains_sum(shop_id: $selected_shop_id)
      effective_stock_mode
      effective_fixed_stock
      base_unit_type_id
      info_weight_type_id
      categories {
        id
        title
      }
      status_id
      is_configurable
      parent_id
    }
    paginatorInfo {
      total
    }
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function d(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var a=e.type;a.kind==="NamedType"&&i.add(a.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(n){d(n,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(n){d(n,i)}),e.definitions&&e.definitions.forEach(function(n){d(n,i)})}var t={};(function(){l.definitions.forEach(function(i){if(i.name){var a=new Set;d(i,a),t[i.name.value]=a}})})();export{l as d};
