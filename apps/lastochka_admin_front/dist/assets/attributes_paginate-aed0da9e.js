var u={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",name:{kind:"Name",value:"AttributesPaginate"},variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"first"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"page"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"attributes_paginate"},arguments:[{kind:"Argument",name:{kind:"Name",value:"first"},value:{kind:"Variable",name:{kind:"Name",value:"first"}}},{kind:"Argument",name:{kind:"Name",value:"page"},value:{kind:"Variable",name:{kind:"Name",value:"page"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"data"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"code"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"category_type_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"position"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"values"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"families"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}}]}},{kind:"Field",name:{kind:"Name",value:"paginatorInfo"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"currentPage"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"lastPage"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"total"},arguments:[],directives:[]}]}}]}}]}}],loc:{start:0,end:380}};u.loc.source={body:`query AttributesPaginate($first: Int, $page: Int) {
  attributes_paginate(first: $first, page: $page) {
    data {
      id
      title
      code
      type_id
      category_type_id
      position
      values {
        id
        title
      }
      families {
        id
        title
      }
    }
    paginatorInfo {
      currentPage
      lastPage
      total
    }
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function r(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){r(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){r(a,i)}),e.definitions&&e.definitions.forEach(function(a){r(a,i)})}var m={};(function(){u.definitions.forEach(function(i){if(i.name){var n=new Set;r(i,n),m[i.name.value]=n}})})();function c(e,i){for(var n=0;n<e.definitions.length;n++){var a=e.definitions[n];if(a.name&&a.name.value==i)return a}}function k(e,i){var n={kind:e.kind,definitions:[c(e,i)]};e.hasOwnProperty("loc")&&(n.loc=e.loc);var a=m[i]||new Set,s=new Set,d=new Set;for(a.forEach(function(t){d.add(t)});d.size>0;){var o=d;d=new Set,o.forEach(function(t){if(!s.has(t)){s.add(t);var l=m[t]||new Set;l.forEach(function(v){d.add(v)})}})}return s.forEach(function(t){var l=c(e,t);l&&n.definitions.push(l)}),n}k(u,"AttributesPaginate");export{u as d};
