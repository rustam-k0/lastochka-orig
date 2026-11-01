var l={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"parent_categories"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_cooking"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"child_categories"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_cooking"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"child_categories"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_cooking"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"child_categories"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_cooking"},arguments:[],directives:[]}]}}]}}]}}]}}]}}],loc:{start:0,end:675}};l.loc.source={body:`# Глобальное дерево категорий — для карточки товара без выбранного магазина
# (сетевой каталог). Обычный options.graphql в этом случае непригоден:
# shop(id: $shop_id) без id — это @find без фильтра, и при более чем одном
# магазине Lighthouse отвечает ошибкой «Query returned more than one result»,
# а shop_categories(shop_id: null) возвращает null (см. GetShopCategories).
query {
  parent_categories {
    id
    title
    is_cooking
    child_categories {
      id
      title
      is_cooking
      child_categories {
        id
        title
        is_cooking
        child_categories {
          id
          title
          is_cooking
        }
      }
    }
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function t(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){t(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){t(a,i)}),e.definitions&&e.definitions.forEach(function(a){t(a,i)})}var c={};(function(){l.definitions.forEach(function(i){if(i.name){var n=new Set;t(i,n),c[i.name.value]=n}})})();var s={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_active"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"admin_brands"},arguments:[{kind:"Argument",name:{kind:"Name",value:"is_active"},value:{kind:"Variable",name:{kind:"Name",value:"is_active"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:93}};s.loc.source={body:`query ($is_active: Boolean) {
  admin_brands(is_active: $is_active) {
    id
    title
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function d(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){d(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){d(a,i)}),e.definitions&&e.definitions.forEach(function(a){d(a,i)})}var o={};(function(){s.definitions.forEach(function(i){if(i.name){var n=new Set;d(i,n),o[i.name.value]=n}})})();export{s as a,l as d};
