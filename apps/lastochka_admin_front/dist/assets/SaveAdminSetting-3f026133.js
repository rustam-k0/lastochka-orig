var u={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",name:{kind:"Name",value:"SaveAdminSetting"},variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"key"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"String"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"value"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"file"}},type:{kind:"NamedType",name:{kind:"Name",value:"Upload"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"type"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"String"}}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"saveAdminSetting"},arguments:[{kind:"Argument",name:{kind:"Name",value:"key"},value:{kind:"Variable",name:{kind:"Name",value:"key"}}},{kind:"Argument",name:{kind:"Name",value:"value"},value:{kind:"Variable",name:{kind:"Name",value:"value"}}},{kind:"Argument",name:{kind:"Name",value:"file"},value:{kind:"Variable",name:{kind:"Name",value:"file"}}},{kind:"Argument",name:{kind:"Name",value:"type"},value:{kind:"Variable",name:{kind:"Name",value:"type"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"key"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"value"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:273}};u.loc.source={body:`mutation SaveAdminSetting(
    $key: String!
    $value: String
    $file: Upload
    $type: String!
) {
    saveAdminSetting(
        key: $key
        value: $value
        file: $file
        type: $type
    ) {
        id
        key
        value
        type
    }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function r(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){r(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){r(a,i)}),e.definitions&&e.definitions.forEach(function(a){r(a,i)})}var m={};(function(){u.definitions.forEach(function(i){if(i.name){var n=new Set;r(i,n),m[i.name.value]=n}})})();function k(e,i){for(var n=0;n<e.definitions.length;n++){var a=e.definitions[n];if(a.name&&a.name.value==i)return a}}function c(e,i){var n={kind:e.kind,definitions:[k(e,i)]};e.hasOwnProperty("loc")&&(n.loc=e.loc);var a=m[i]||new Set,v=new Set,l=new Set;for(a.forEach(function(t){l.add(t)});l.size>0;){var o=l;l=new Set,o.forEach(function(t){if(!v.has(t)){v.add(t);var d=m[t]||new Set;d.forEach(function(f){l.add(f)})}})}return v.forEach(function(t){var d=k(e,t);d&&n.definitions.push(d)}),n}c(u,"SaveAdminSetting");export{u as d};
