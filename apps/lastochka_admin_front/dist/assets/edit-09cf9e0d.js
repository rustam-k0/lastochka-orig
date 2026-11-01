import{_,a as b,bR as f,r as d,o,c as s,d as c,b as m}from"./index-5da92eef-44242689.js";import{B as N}from"./BreadcrumbsComponent-4e9c6116.js";import{L as y}from"./LoadingIndicator-86875ddd.js";import{M as F}from"./MerchantBannerForm-00df18d5.js";import{d as S}from"./MerchantBannerUpdate-f5da4f21.js";import"./ShopSelectComponent-a43f5e3c.js";import"./input-9848d4d8.js";import"./CheckboxComponent-f1c16832.js";import"./admin_shops_search-16436698.js";import"./ImageUpload-01e9c12b.js";import"./CustomCheckboxesField-bbffc7b0.js";import"./FlagCheckboxComponent-557f9457.js";import"./ShopMultiTargetField-dec4b7ea.js";import"./shops_paginate-191fe85b.js";import"./categories-0110da6a.js";var u={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"merchantBanner"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"link_type"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"product_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"category_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"display_category_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"shop_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_active"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"position"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"custom_checkboxes"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"shop"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"product"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"category"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"display_category"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"image"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]}]}}]}}]}}],loc:{start:0,end:410}};u.loc.source={body:`query ($id: Int!) {
  merchantBanner(id: $id) {
    id
    title
    link_type
    product_id
    category_id
    display_category_id
    shop_id
    is_active
    position
    custom_checkboxes
    shop {
      id
      title
    }
    product {
      id
      title
    }
    category {
      id
      title
    }
    display_category {
      id
      title
    }
    image {
      id
      path
    }
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function l(e,n){if(e.kind==="FragmentSpread")n.add(e.name.value);else if(e.kind==="VariableDefinition"){var a=e.type;a.kind==="NamedType"&&n.add(a.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(i){l(i,n)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(i){l(i,n)}),e.definitions&&e.definitions.forEach(function(i){l(i,n)})}var B={};(function(){u.definitions.forEach(function(n){if(n.name){var a=new Set;l(n,a),B[n.name.value]=a}})})();const D={name:"MerchantBannerEditPage",inject:["apollo"],components:{BreadcrumbsComponent:N,IconComponent:b,LoadingIndicator:y,MerchantBannerForm:F},data(){return{bannerData:null,pageLoading:!0,loading:!1,breadcrumbs:[{to:{name:"merchant-banners"},title:"Баннеры"},{title:"Редактирование"}]}},computed:{bannerId(){return this.$route.params.id}},mounted(){this.fetchBanner()},methods:{fetchBanner(){this.pageLoading=!0,this.apollo().admin.query({query:u,variables:{id:parseInt(this.bannerId)},fetchPolicy:"network-only"}).then(({data:e})=>{e.merchantBanner&&(this.bannerData=e.merchantBanner)}).catch(e=>{console.error("Error fetching banner:",e)}).finally(()=>{this.pageLoading=!1})},handleSubmit(e){this.loading=!0;const{shop_id:n,...a}=e;this.apollo().admin.mutate({mutation:S,variables:{id:parseInt(this.bannerId),...a}}).then(({data:i})=>{var t;if((t=i.merchantBannerUpdate)!=null&&t.id)this.$router.push({name:"merchant-banners"});else throw new Error("Ошибка сохранения")}).catch(i=>{console.error("Error updating merchant banner:",i),alert(f(i,"Ошибка сохранения баннера"))}).finally(()=>{this.loading=!1})},handleCancel(){this.$router.push({name:"merchant-banners"})},goBack(){this.$router.push({name:"merchant-banners"})}}},I={class:"page--main merchant-banner-edit-page"},C=m("div",{class:"merchant-banner-edit-page__header"},[m("h2",null,"Редактирование баннера")],-1),E={key:0,class:"merchant-banner-edit-page__loading"},L={key:1,class:"merchant-banner-edit-page__error"},x=m("h3",null,"Баннер не найден",-1),q={key:2,class:"merchant-banner-edit-page__content"};function V(e,n,a,i,t,r){const k=d("BreadcrumbsComponent"),p=d("LoadingIndicator"),v=d("IconComponent"),g=d("MerchantBannerForm");return o(),s("main",I,[c(k,{breadcrumbs:t.breadcrumbs},null,8,["breadcrumbs"]),C,t.pageLoading?(o(),s("div",E,[c(p)])):t.bannerData?(o(),s("div",q,[c(g,{initialData:t.bannerData,loading:t.loading,isEdit:!0,onSubmit:r.handleSubmit,onCancel:r.handleCancel},null,8,["initialData","loading","onSubmit","onCancel"])])):(o(),s("div",L,[c(v,{name:"error-warning-line"}),x,m("button",{onClick:n[0]||(n[0]=(...h)=>r.goBack&&r.goBack(...h)),class:"btn btn--md"}," Вернуться к списку ")]))])}const K=_(D,[["render",V]]);export{K as default};
