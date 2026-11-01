var c={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",name:{kind:"Name",value:"productGroupFilters"},variableDefinitions:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"productGroupFilters"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"position"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_active"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"values"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"value"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"image_path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"position"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_active"},arguments:[],directives:[]}]}}]}}]}}],loc:{start:0,end:189}};c.loc.source={body:`query productGroupFilters {
  productGroupFilters {
    id
    name
    position
    is_active
    values {
      id
      value
      image_path
      position
      is_active
    }
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function s(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){s(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){s(a,i)}),e.definitions&&e.definitions.forEach(function(a){s(a,i)})}var l={};(function(){c.definitions.forEach(function(i){if(i.name){var n=new Set;s(i,n),l[i.name.value]=n}})})();function u(e,i){for(var n=0;n<e.definitions.length;n++){var a=e.definitions[n];if(a.name&&a.name.value==i)return a}}function m(e,i){var n={kind:e.kind,definitions:[u(e,i)]};e.hasOwnProperty("loc")&&(n.loc=e.loc);var a=l[i]||new Set,o=new Set,r=new Set;for(a.forEach(function(t){r.add(t)});r.size>0;){var v=r;r=new Set,v.forEach(function(t){if(!o.has(t)){o.add(t);var d=l[t]||new Set;d.forEach(function(f){r.add(f)})}})}return o.forEach(function(t){var d=u(e,t);d&&n.definitions.push(d)}),n}m(c,"productGroupFilters");export{c as d};
