var d={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"categories"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"attributes"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"attribute_families"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_system"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"assembly_zones"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"position"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:193}};d.loc.source={body:`query {
  categories {
    id
    title
  }
  attributes {
    id
    title
  }
  attribute_families {
    id
    title
    is_system
  }
  assembly_zones {
    id
    title
    position
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function a(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(t){a(t,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(t){a(t,i)}),e.definitions&&e.definitions.forEach(function(t){a(t,i)})}var s={};(function(){d.definitions.forEach(function(i){if(i.name){var n=new Set;a(i,n),s[i.name.value]=n}})})();export{d};
