var d={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"shop_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"couriers"},arguments:[{kind:"Argument",name:{kind:"Name",value:"shop_id"},value:{kind:"Variable",name:{kind:"Name",value:"shop_id"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"surname"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"patronymic"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"collectors"},arguments:[{kind:"Argument",name:{kind:"Name",value:"shop_id"},value:{kind:"Variable",name:{kind:"Name",value:"shop_id"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"surname"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"patronymic"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:185}};d.loc.source={body:`query($shop_id: Int) {
  couriers(shop_id: $shop_id) {
    id
    name
    surname
    patronymic
  }
  collectors(shop_id: $shop_id) {
    id
    name
    surname
    patronymic
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function t(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){t(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){t(a,i)}),e.definitions&&e.definitions.forEach(function(a){t(a,i)})}var m={};(function(){d.definitions.forEach(function(i){if(i.name){var n=new Set;t(i,n),m[i.name.value]=n}})})();export{d};
