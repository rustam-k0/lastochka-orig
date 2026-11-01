var d={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"title"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"first"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"categories"}},type:{kind:"ListType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"admin_products_paginate"},arguments:[{kind:"Argument",name:{kind:"Name",value:"title"},value:{kind:"Variable",name:{kind:"Name",value:"title"}}},{kind:"Argument",name:{kind:"Name",value:"first"},value:{kind:"Variable",name:{kind:"Name",value:"first"}}},{kind:"Argument",name:{kind:"Name",value:"categories"},value:{kind:"Variable",name:{kind:"Name",value:"categories"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"data"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"articul"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"default_price"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"value"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"remains"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"shop_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"quantity"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"categories"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"status_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_configurable"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"parent_id"},arguments:[],directives:[]}]}}]}}]}}],loc:{start:0,end:439}};d.loc.source={body:`query ($title: String, $first: Int, $categories: [Int]) {
  admin_products_paginate(title: $title, first: $first, categories: $categories) {
    data {
      id
      articul
      title
      img {
        path
      }
      default_price {
        value
      }
      remains {
        shop_id
        quantity
      }
      categories {
        id
        title
      }
      status_id
      is_configurable
      parent_id
    }
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function t(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){t(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){t(a,i)}),e.definitions&&e.definitions.forEach(function(a){t(a,i)})}var l={};(function(){d.definitions.forEach(function(i){if(i.name){var n=new Set;t(i,n),l[i.name.value]=n}})})();export{d};
