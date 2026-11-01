var u={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",name:{kind:"Name",value:"merchant_promocodes_paginate"},variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"first"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"page"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"shop_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_active"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"status"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"search"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"ordering"}},type:{kind:"ListType",type:{kind:"NamedType",name:{kind:"Name",value:"DefaultOrdering"}}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"merchantPromocodes"},arguments:[{kind:"Argument",name:{kind:"Name",value:"first"},value:{kind:"Variable",name:{kind:"Name",value:"first"}}},{kind:"Argument",name:{kind:"Name",value:"page"},value:{kind:"Variable",name:{kind:"Name",value:"page"}}},{kind:"Argument",name:{kind:"Name",value:"shop_id"},value:{kind:"Variable",name:{kind:"Name",value:"shop_id"}}},{kind:"Argument",name:{kind:"Name",value:"is_active"},value:{kind:"Variable",name:{kind:"Name",value:"is_active"}}},{kind:"Argument",name:{kind:"Name",value:"status"},value:{kind:"Variable",name:{kind:"Name",value:"status"}}},{kind:"Argument",name:{kind:"Name",value:"search"},value:{kind:"Variable",name:{kind:"Name",value:"search"}}},{kind:"Argument",name:{kind:"Name",value:"ordering"},value:{kind:"Variable",name:{kind:"Name",value:"ordering"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"data"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"shop_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"scope"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"code"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"discount_percent"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"discount_type"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"discount_value"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"binding_type"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"valid_from"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"valid_to"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_active"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_valid"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"status"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"max_uses"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"max_uses_per_client"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"used_count"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"created_at"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"shop"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}}]}},{kind:"Field",name:{kind:"Name",value:"paginatorInfo"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"currentPage"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"lastPage"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"total"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"perPage"},arguments:[],directives:[]}]}}]}}]}}],loc:{start:0,end:792}};u.loc.source={body:`query merchant_promocodes_paginate(
  $first: Int
  $page: Int
  $shop_id: Int
  $is_active: Boolean
  $status: String
  $search: String
  $ordering: [DefaultOrdering]
) {
  merchantPromocodes(
    first: $first
    page: $page
    shop_id: $shop_id
    is_active: $is_active
    status: $status
    search: $search
    ordering: $ordering
  ) {
    data {
      id
      shop_id
      scope
      title
      code
      discount_percent
      discount_type
      discount_value
      binding_type
      valid_from
      valid_to
      is_active
      is_valid
      status
      max_uses
      max_uses_per_client
      used_count
      created_at
      shop {
        id
        title
      }
    }
    paginatorInfo {
      currentPage
      lastPage
      total
      perPage
    }
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function l(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){l(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){l(a,i)}),e.definitions&&e.definitions.forEach(function(a){l(a,i)})}var s={};(function(){u.definitions.forEach(function(i){if(i.name){var n=new Set;l(i,n),s[i.name.value]=n}})})();function v(e,i){for(var n=0;n<e.definitions.length;n++){var a=e.definitions[n];if(a.name&&a.name.value==i)return a}}function o(e,i){var n={kind:e.kind,definitions:[v(e,i)]};e.hasOwnProperty("loc")&&(n.loc=e.loc);var a=s[i]||new Set,m=new Set,t=new Set;for(a.forEach(function(d){t.add(d)});t.size>0;){var k=t;t=new Set,k.forEach(function(d){if(!m.has(d)){m.add(d);var r=s[d]||new Set;r.forEach(function(c){t.add(c)})}})}return m.forEach(function(d){var r=v(e,d);r&&n.definitions.push(r)}),n}o(u,"merchant_promocodes_paginate");export{u as d};
