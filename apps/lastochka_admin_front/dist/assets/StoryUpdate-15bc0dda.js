var d={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"title"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"expires_at"}},type:{kind:"NamedType",name:{kind:"Name",value:"DateTime"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"link_type"}},type:{kind:"NamedType",name:{kind:"Name",value:"StoryLinkType"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"product_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"shop_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"external_url"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_active"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_quick_category"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"position"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"thumbnail_image"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"images"}},type:{kind:"ListType",type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"StoryUpdate"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}},{kind:"Argument",name:{kind:"Name",value:"title"},value:{kind:"Variable",name:{kind:"Name",value:"title"}}},{kind:"Argument",name:{kind:"Name",value:"expires_at"},value:{kind:"Variable",name:{kind:"Name",value:"expires_at"}}},{kind:"Argument",name:{kind:"Name",value:"link_type"},value:{kind:"Variable",name:{kind:"Name",value:"link_type"}}},{kind:"Argument",name:{kind:"Name",value:"product_id"},value:{kind:"Variable",name:{kind:"Name",value:"product_id"}}},{kind:"Argument",name:{kind:"Name",value:"shop_id"},value:{kind:"Variable",name:{kind:"Name",value:"shop_id"}}},{kind:"Argument",name:{kind:"Name",value:"external_url"},value:{kind:"Variable",name:{kind:"Name",value:"external_url"}}},{kind:"Argument",name:{kind:"Name",value:"is_active"},value:{kind:"Variable",name:{kind:"Name",value:"is_active"}}},{kind:"Argument",name:{kind:"Name",value:"is_quick_category"},value:{kind:"Variable",name:{kind:"Name",value:"is_quick_category"}}},{kind:"Argument",name:{kind:"Name",value:"position"},value:{kind:"Variable",name:{kind:"Name",value:"position"}}},{kind:"Argument",name:{kind:"Name",value:"thumbnail_image"},value:{kind:"Variable",name:{kind:"Name",value:"thumbnail_image"}}},{kind:"Argument",name:{kind:"Name",value:"images"},value:{kind:"Variable",name:{kind:"Name",value:"images"}}},{kind:"Argument",name:{kind:"Name",value:"custom_checkboxes"},value:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:706}};d.loc.source={body:`mutation(
  $id: Int!
  $title: String
  $expires_at: DateTime
  $link_type: StoryLinkType
  $product_id: Int
  $shop_id: Int
  $external_url: String
  $is_active: Boolean
  $is_quick_category: Boolean
  $position: Int
  $thumbnail_image: ImgInput
  $images: [ImgInput!]
  $custom_checkboxes: String
) {
  StoryUpdate(
    id: $id
    title: $title
    expires_at: $expires_at
    link_type: $link_type
    product_id: $product_id
    shop_id: $shop_id
    external_url: $external_url
    is_active: $is_active
    is_quick_category: $is_quick_category
    position: $position
    thumbnail_image: $thumbnail_image
    images: $images
    custom_checkboxes: $custom_checkboxes
  ) {
    id
    title
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function t(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var a=e.type;a.kind==="NamedType"&&i.add(a.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(n){t(n,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(n){t(n,i)}),e.definitions&&e.definitions.forEach(function(n){t(n,i)})}var l={};(function(){d.definitions.forEach(function(i){if(i.name){var a=new Set;t(i,a),l[i.name.value]=a}})})();export{d};
