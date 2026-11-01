var o={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"shops"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:41}};o.loc.source={body:`query {
  shops {
    id
    title
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function a(i,e){if(i.kind==="FragmentSpread")e.add(i.name.value);else if(i.kind==="VariableDefinition"){var n=i.type;n.kind==="NamedType"&&e.add(n.name.value)}i.selectionSet&&i.selectionSet.selections.forEach(function(t){a(t,e)}),i.variableDefinitions&&i.variableDefinitions.forEach(function(t){a(t,e)}),i.definitions&&i.definitions.forEach(function(t){a(t,e)})}var c={};(function(){o.definitions.forEach(function(e){if(e.name){var n=new Set;a(e,n),c[e.name.value]=n}})})();export{o as d};
