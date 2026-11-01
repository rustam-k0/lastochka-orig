import{B as N}from"./BreadcrumbsComponent-4e9c6116.js";import{_ as F,a as b,r as o,o as r,c as m,d as s,b as u}from"./index-5da92eef-44242689.js";import{L as S}from"./LoadingIndicator-86875ddd.js";import{M as y}from"./MerchantPromocodeForm-ada69ef3.js";import"./ShopSelectComponent-a43f5e3c.js";import"./input-9848d4d8.js";import"./CheckboxComponent-f1c16832.js";import"./admin_shops_search-16436698.js";import"./DateInput-4bef2dec.js";import"./dayjs.min-ad38b417.js";import"./customParseFormat-1ac90020.js";import"./CategoryTreeSelect-ca9b0f35.js";import"./CategoryTreeNode-29d57bdb.js";import"./shops_paginate-191fe85b.js";var v={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"merchantPromocode"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"shop_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"scope"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"code"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"discount_percent"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"discount_type"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"discount_value"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"binding_type"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"valid_from"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"valid_to"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_active"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_valid"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"status"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"max_uses"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"max_uses_per_client"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"used_count"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"created_at"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"updated_at"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"shop"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"products"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"categories"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}}]}}]}}],loc:{start:0,end:452}};v.loc.source={body:`query ($id: Int!) {
  merchantPromocode(id: $id) {
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
    updated_at
    shop {
      id
      title
    }
    products {
      id
      title
    }
    categories {
      id
      title
    }
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function c(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){c(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){c(a,i)}),e.definitions&&e.definitions.forEach(function(a){c(a,i)})}var D={};(function(){v.definitions.forEach(function(i){if(i.name){var n=new Set;c(i,n),D[i.name.value]=n}})})();var k={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"input"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"UpdateMerchantPromocodeInput"}}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"updateMerchantPromocode"},arguments:[{kind:"Argument",name:{kind:"Name",value:"input"},value:{kind:"Variable",name:{kind:"Name",value:"input"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"code"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"scope"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"shop_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"discount_percent"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"discount_type"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"discount_value"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"binding_type"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"valid_from"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"valid_to"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_active"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"max_uses"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"max_uses_per_client"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"used_count"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"status"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"shop"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}}]}}]}}],loc:{start:0,end:366}};k.loc.source={body:`mutation ($input: UpdateMerchantPromocodeInput!) {
  updateMerchantPromocode(input: $input) {
    id
    title
    code
    scope
    shop_id
    discount_percent
    discount_type
    discount_value
    binding_type
    valid_from
    valid_to
    is_active
    max_uses
    max_uses_per_client
    used_count
    status
    shop {
      id
      title
    }
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function l(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){l(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){l(a,i)}),e.definitions&&e.definitions.forEach(function(a){l(a,i)})}var P={};(function(){k.definitions.forEach(function(i){if(i.name){var n=new Set;l(i,n),P[i.name.value]=n}})})();const E={name:"MerchantPromocodeEditPage",inject:["apollo"],components:{BreadcrumbsComponent:N,IconComponent:b,LoadingIndicator:S,MerchantPromocodeForm:y},data(){return{promocodeData:null,pageLoading:!0,loading:!1,breadcrumbs:[{to:{name:"merchant-promocodes"},title:"Промокоды"},{title:"Редактирование"}]}},computed:{promocodeId(){return this.$route.params.id}},mounted(){this.fetchPromocode()},methods:{fetchPromocode(){this.pageLoading=!0,this.apollo().admin.query({query:v,variables:{id:parseInt(this.promocodeId)},fetchPolicy:"network-only"}).then(({data:e})=>{e.merchantPromocode&&(this.promocodeData=e.merchantPromocode)}).catch(e=>{console.error("Error fetching promocode:",e)}).finally(()=>{this.pageLoading=!1})},handleSubmit(e){this.loading=!0,this.apollo().admin.mutate({mutation:k,variables:{input:{id:parseInt(this.promocodeId),...e}}}).then(({data:i})=>{var n;if((n=i.updateMerchantPromocode)!=null&&n.id)this.$router.push({name:"merchant-promocodes"});else throw new Error("Ошибка сохранения")}).catch(i=>{var a,t;console.error("Error updating merchant promocode:",i);const n=((t=(a=i.graphQLErrors)==null?void 0:a[0])==null?void 0:t.message)||i.message||"Ошибка сохранения промокода";alert(n)}).finally(()=>{this.loading=!1})},handleCancel(){this.$router.push({name:"merchant-promocodes"})},goBack(){this.$router.push({name:"merchant-promocodes"})}}},I={class:"page--main merchant-promocode-edit-page"},x=u("div",{class:"merchant-promocode-edit-page__header"},[u("h2",null,"Редактирование промокода")],-1),C={key:0,class:"merchant-promocode-edit-page__loading"},L={key:1,class:"merchant-promocode-edit-page__error"},B=u("h3",null,"Промокод не найден",-1),M={key:2,class:"merchant-promocode-edit-page__content"};function V(e,i,n,a,t,d){const p=o("BreadcrumbsComponent"),_=o("LoadingIndicator"),g=o("IconComponent"),h=o("MerchantPromocodeForm");return r(),m("main",I,[s(p,{breadcrumbs:t.breadcrumbs},null,8,["breadcrumbs"]),x,t.pageLoading?(r(),m("div",C,[s(_)])):t.promocodeData?(r(),m("div",M,[s(h,{initialData:t.promocodeData,loading:t.loading,isEdit:!0,onSubmit:d.handleSubmit,onCancel:d.handleCancel},null,8,["initialData","loading","onSubmit","onCancel"])])):(r(),m("div",L,[s(g,{name:"error-warning-line"}),B,u("button",{onClick:i[0]||(i[0]=(...f)=>d.goBack&&d.goBack(...f)),class:"btn btn--md"}," Вернуться к списку ")]))])}const K=F(E,[["render",V]]);export{K as default};
