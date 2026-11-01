import{ah as S,E as b}from"./index-5da92eef-44242689.js";var N={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",name:{kind:"Name",value:"AnnouncementUpdate"},variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"title"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"short_description"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"description"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"promocode_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_active"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"image"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"announcementUpdate"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}},{kind:"Argument",name:{kind:"Name",value:"title"},value:{kind:"Variable",name:{kind:"Name",value:"title"}}},{kind:"Argument",name:{kind:"Name",value:"short_description"},value:{kind:"Variable",name:{kind:"Name",value:"short_description"}}},{kind:"Argument",name:{kind:"Name",value:"description"},value:{kind:"Variable",name:{kind:"Name",value:"description"}}},{kind:"Argument",name:{kind:"Name",value:"promocode_id"},value:{kind:"Variable",name:{kind:"Name",value:"promocode_id"}}},{kind:"Argument",name:{kind:"Name",value:"is_active"},value:{kind:"Variable",name:{kind:"Name",value:"is_active"}}},{kind:"Argument",name:{kind:"Name",value:"image"},value:{kind:"Variable",name:{kind:"Name",value:"image"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"short_description"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"description"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"promocode_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_active"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"created_at"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"updated_at"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"promocode"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"discount_percent"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"image"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]}]}}]}}]}}],loc:{start:0,end:625}};N.loc.source={body:`mutation AnnouncementUpdate(
  $id: Int!
  $title: String
  $short_description: String
  $description: String
  $promocode_id: Int
  $is_active: Boolean
  $image: ImgInput
) {
  announcementUpdate(
    id: $id
    title: $title
    short_description: $short_description
    description: $description
    promocode_id: $promocode_id
    is_active: $is_active
    image: $image
  ) {
    id
    title
    short_description
    description
    promocode_id
    is_active
    created_at
    updated_at
    promocode {
      id
      title
      discount_percent
    }
    image {
      id
      path
      origin_name
    }
  }
}`,name:"GraphQL request",locationOffset:{line:1,column:1}};function u(e,n){if(e.kind==="FragmentSpread")n.add(e.name.value);else if(e.kind==="VariableDefinition"){var i=e.type;i.kind==="NamedType"&&n.add(i.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){u(a,n)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){u(a,n)}),e.definitions&&e.definitions.forEach(function(a){u(a,n)})}var f={};(function(){N.definitions.forEach(function(n){if(n.name){var i=new Set;u(n,i),f[n.name.value]=i}})})();function h(e,n){for(var i=0;i<e.definitions.length;i++){var a=e.definitions[i];if(a.name&&a.name.value==n)return a}}function y(e,n){var i={kind:e.kind,definitions:[h(e,n)]};e.hasOwnProperty("loc")&&(i.loc=e.loc);var a=f[n]||new Set,o=new Set,d=new Set;for(a.forEach(function(t){d.add(t)});d.size>0;){var l=d;d=new Set,l.forEach(function(t){if(!o.has(t)){o.add(t);var r=f[t]||new Set;r.forEach(function(c){d.add(c)})}})}return o.forEach(function(t){var r=h(e,t);r&&i.definitions.push(r)}),i}y(N,"AnnouncementUpdate");var k={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",name:{kind:"Name",value:"SendAnnouncementNotification"},variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"announcementId"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"ID"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"target"}},type:{kind:"NamedType",name:{kind:"Name",value:"NotificationTarget"}},defaultValue:{kind:"EnumValue",value:"ALL_USERS"},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"segmentCriteria"}},type:{kind:"NamedType",name:{kind:"Name",value:"NotificationSegmentInput"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"sendAnnouncementNotification"},arguments:[{kind:"Argument",name:{kind:"Name",value:"announcement_id"},value:{kind:"Variable",name:{kind:"Name",value:"announcementId"}}},{kind:"Argument",name:{kind:"Name",value:"target"},value:{kind:"Variable",name:{kind:"Name",value:"target"}}},{kind:"Argument",name:{kind:"Name",value:"segment_criteria"},value:{kind:"Variable",name:{kind:"Name",value:"segmentCriteria"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"success"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"message"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"target"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"announcement"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"short_description"},arguments:[],directives:[]}]}}]}}]}}],loc:{start:0,end:397}};k.loc.source={body:`mutation SendAnnouncementNotification(
  $announcementId: ID!
  $target: NotificationTarget = ALL_USERS
  $segmentCriteria: NotificationSegmentInput
) {
  sendAnnouncementNotification(
    announcement_id: $announcementId
    target: $target
    segment_criteria: $segmentCriteria
  ) {
    success
    message
    target
    announcement {
      id
      title
      short_description
    }
  }
}`,name:"GraphQL request",locationOffset:{line:1,column:1}};function v(e,n){if(e.kind==="FragmentSpread")n.add(e.name.value);else if(e.kind==="VariableDefinition"){var i=e.type;i.kind==="NamedType"&&n.add(i.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){v(a,n)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){v(a,n)}),e.definitions&&e.definitions.forEach(function(a){v(a,n)})}var g={};(function(){k.definitions.forEach(function(n){if(n.name){var i=new Set;v(n,i),g[n.name.value]=i}})})();function _(e,n){for(var i=0;i<e.definitions.length;i++){var a=e.definitions[i];if(a.name&&a.name.value==n)return a}}function V(e,n){var i={kind:e.kind,definitions:[_(e,n)]};e.hasOwnProperty("loc")&&(i.loc=e.loc);var a=g[n]||new Set,o=new Set,d=new Set;for(a.forEach(function(t){d.add(t)});d.size>0;){var l=d;d=new Set,l.forEach(function(t){if(!o.has(t)){o.add(t);var r=g[t]||new Set;r.forEach(function(c){d.add(c)})}})}return o.forEach(function(t){var r=_(e,t);r&&i.definitions.push(r)}),i}V(k,"SendAnnouncementNotification");function $(){const e=S(!1),n=S(null),i=async(a,o,d={})=>{var l,t,r,c;try{e.value=!0,n.value=null;const s={announcementId:String(o),target:d.target||"ALL_USERS"};d.segmentCriteria&&Object.keys(d.segmentCriteria).length>0&&(s.segmentCriteria=d.segmentCriteria);const m=await a.admin.mutate({mutation:k,variables:s});if((t=(l=m==null?void 0:m.data)==null?void 0:l.sendAnnouncementNotification)!=null&&t.success){const p=m.data.sendAnnouncementNotification;return alert(`Успешно: ${p.message}`),p}throw new Error(((c=(r=m==null?void 0:m.data)==null?void 0:r.sendAnnouncementNotification)==null?void 0:c.message)||"Ошибка отправки уведомления")}catch(s){const m=s.message||"Не удалось отправить уведомление";throw n.value=m,alert(`Ошибка: ${m}`),s}finally{e.value=!1}};return{isLoading:b(()=>e.value),error:b(()=>n.value),sendAnnouncementNotification:i}}export{N as d,$ as u};
