var o={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"ProductPublish"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"code"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"message"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:85}};o.loc.source={body:`mutation ($id: Int) {
  ProductPublish(
    id: $id
  ) {
    code
    message
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function t(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){t(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){t(a,i)}),e.definitions&&e.definitions.forEach(function(a){t(a,i)})}var l={};(function(){o.definitions.forEach(function(i){if(i.name){var n=new Set;t(i,n),l[i.name.value]=n}})})();var c={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"ProductHide"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"message"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"code"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:74}};c.loc.source={body:`mutation ($id: Int) {
  ProductHide(id: $id) {
    message
    code
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function d(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){d(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){d(a,i)}),e.definitions&&e.definitions.forEach(function(a){d(a,i)})}var r={};(function(){c.definitions.forEach(function(i){if(i.name){var n=new Set;d(i,n),r[i.name.value]=n}})})();export{c as a,o as d};
