var d={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"name"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"latitude"}},type:{kind:"NamedType",name:{kind:"Name",value:"Float"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"longitude"}},type:{kind:"NamedType",name:{kind:"Name",value:"Float"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_active"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_default"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"CityUpdate"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}},{kind:"Argument",name:{kind:"Name",value:"name"},value:{kind:"Variable",name:{kind:"Name",value:"name"}}},{kind:"Argument",name:{kind:"Name",value:"latitude"},value:{kind:"Variable",name:{kind:"Name",value:"latitude"}}},{kind:"Argument",name:{kind:"Name",value:"longitude"},value:{kind:"Variable",name:{kind:"Name",value:"longitude"}}},{kind:"Argument",name:{kind:"Name",value:"is_active"},value:{kind:"Variable",name:{kind:"Name",value:"is_active"}}},{kind:"Argument",name:{kind:"Name",value:"is_default"},value:{kind:"Variable",name:{kind:"Name",value:"is_default"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"message"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"code"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:306}};d.loc.source={body:`mutation (
  $id: Int!
  $name: String
  $latitude: Float
  $longitude: Float
  $is_active: Boolean
  $is_default: Boolean
) {
  CityUpdate(
    id: $id
    name: $name
    latitude: $latitude
    longitude: $longitude
    is_active: $is_active
    is_default: $is_default
  ) {
    message
    code
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function t(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var a=e.type;a.kind==="NamedType"&&i.add(a.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(n){t(n,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(n){t(n,i)}),e.definitions&&e.definitions.forEach(function(n){t(n,i)})}var l={};(function(){d.definitions.forEach(function(i){if(i.name){var a=new Set;t(i,a),l[i.name.value]=a}})})();export{d};
