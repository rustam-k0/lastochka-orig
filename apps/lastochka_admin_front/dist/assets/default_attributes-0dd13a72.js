var d={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"default_attributes"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"code"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"category_type_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"values"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}}]}}]}}],loc:{start:0,end:136}};d.loc.source={body:`query {
  default_attributes {
    id
    title
    code
    type_id
    category_type_id
    values {
      id
      title
    }
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function a(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(t){a(t,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(t){a(t,i)}),e.definitions&&e.definitions.forEach(function(t){a(t,i)})}var l={};(function(){d.definitions.forEach(function(i){if(i.name){var n=new Set;a(i,n),l[i.name.value]=n}})})();export{d};
