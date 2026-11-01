var d={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"title"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"link"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"country_origin"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"country_origin_code"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"country_production"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"country_production_code"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"certificate_path"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"certificate_name"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_active"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"position"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"logo_image"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"BrandUpdate"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}},{kind:"Argument",name:{kind:"Name",value:"title"},value:{kind:"Variable",name:{kind:"Name",value:"title"}}},{kind:"Argument",name:{kind:"Name",value:"link"},value:{kind:"Variable",name:{kind:"Name",value:"link"}}},{kind:"Argument",name:{kind:"Name",value:"country_origin"},value:{kind:"Variable",name:{kind:"Name",value:"country_origin"}}},{kind:"Argument",name:{kind:"Name",value:"country_origin_code"},value:{kind:"Variable",name:{kind:"Name",value:"country_origin_code"}}},{kind:"Argument",name:{kind:"Name",value:"country_production"},value:{kind:"Variable",name:{kind:"Name",value:"country_production"}}},{kind:"Argument",name:{kind:"Name",value:"country_production_code"},value:{kind:"Variable",name:{kind:"Name",value:"country_production_code"}}},{kind:"Argument",name:{kind:"Name",value:"certificate_path"},value:{kind:"Variable",name:{kind:"Name",value:"certificate_path"}}},{kind:"Argument",name:{kind:"Name",value:"certificate_name"},value:{kind:"Variable",name:{kind:"Name",value:"certificate_name"}}},{kind:"Argument",name:{kind:"Name",value:"is_active"},value:{kind:"Variable",name:{kind:"Name",value:"is_active"}}},{kind:"Argument",name:{kind:"Name",value:"position"},value:{kind:"Variable",name:{kind:"Name",value:"position"}}},{kind:"Argument",name:{kind:"Name",value:"logo_image"},value:{kind:"Variable",name:{kind:"Name",value:"logo_image"}}},{kind:"Argument",name:{kind:"Name",value:"custom_checkboxes"},value:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:799}};d.loc.source={body:`mutation(
  $id: Int!
  $title: String
  $link: String
  $country_origin: String
  $country_origin_code: String
  $country_production: String
  $country_production_code: String
  $certificate_path: String
  $certificate_name: String
  $is_active: Boolean
  $position: Int
  $logo_image: ImgInput
  $custom_checkboxes: String
) {
  BrandUpdate(
    id: $id
    title: $title
    link: $link
    country_origin: $country_origin
    country_origin_code: $country_origin_code
    country_production: $country_production
    country_production_code: $country_production_code
    certificate_path: $certificate_path
    certificate_name: $certificate_name
    is_active: $is_active
    position: $position
    logo_image: $logo_image
    custom_checkboxes: $custom_checkboxes
  ) {
    id
    title
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function t(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){t(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){t(a,i)}),e.definitions&&e.definitions.forEach(function(a){t(a,i)})}var r={};(function(){d.definitions.forEach(function(i){if(i.name){var n=new Set;t(i,n),r[i.name.value]=n}})})();export{d};
