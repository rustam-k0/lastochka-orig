var t={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"title"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"link_type"}},type:{kind:"NamedType",name:{kind:"Name",value:"MerchantBannerLinkType"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"product_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"category_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"display_category_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"position"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_active"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"image"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"merchantBannerUpdate"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}},{kind:"Argument",name:{kind:"Name",value:"input"},value:{kind:"ObjectValue",fields:[{kind:"ObjectField",name:{kind:"Name",value:"title"},value:{kind:"Variable",name:{kind:"Name",value:"title"}}},{kind:"ObjectField",name:{kind:"Name",value:"link_type"},value:{kind:"Variable",name:{kind:"Name",value:"link_type"}}},{kind:"ObjectField",name:{kind:"Name",value:"product_id"},value:{kind:"Variable",name:{kind:"Name",value:"product_id"}}},{kind:"ObjectField",name:{kind:"Name",value:"category_id"},value:{kind:"Variable",name:{kind:"Name",value:"category_id"}}},{kind:"ObjectField",name:{kind:"Name",value:"display_category_id"},value:{kind:"Variable",name:{kind:"Name",value:"display_category_id"}}},{kind:"ObjectField",name:{kind:"Name",value:"position"},value:{kind:"Variable",name:{kind:"Name",value:"position"}}},{kind:"ObjectField",name:{kind:"Name",value:"is_active"},value:{kind:"Variable",name:{kind:"Name",value:"is_active"}}},{kind:"ObjectField",name:{kind:"Name",value:"image"},value:{kind:"Variable",name:{kind:"Name",value:"image"}}},{kind:"ObjectField",name:{kind:"Name",value:"custom_checkboxes"},value:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}}}]}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:595}};t.loc.source={body:`mutation (
  $id: Int!
  $title: String
  $link_type: MerchantBannerLinkType
  $product_id: Int
  $category_id: Int
  $display_category_id: Int
  $position: Int
  $is_active: Boolean
  $image: ImgInput
  $custom_checkboxes: String
) {
  merchantBannerUpdate(
    id: $id
    input: {
      title: $title
      link_type: $link_type
      product_id: $product_id
      category_id: $category_id
      display_category_id: $display_category_id
      position: $position
      is_active: $is_active
      image: $image
      custom_checkboxes: $custom_checkboxes
    }
  ) {
    id
    title
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function d(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var a=e.type;a.kind==="NamedType"&&i.add(a.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(n){d(n,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(n){d(n,i)}),e.definitions&&e.definitions.forEach(function(n){d(n,i)})}var l={};(function(){t.definitions.forEach(function(i){if(i.name){var a=new Set;d(i,a),l[i.name.value]=a}})})();export{t as d};
