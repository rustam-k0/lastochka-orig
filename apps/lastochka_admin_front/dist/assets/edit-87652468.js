import{_ as h,a as f,bR as N,r as d,o,c as s,d as m,b as l}from"./index-5da92eef-44242689.js";import{B as F}from"./BreadcrumbsComponent-4e9c6116.js";import{L as S}from"./LoadingIndicator-86875ddd.js";import{M as b}from"./MerchantProductGroupForm-290d4cb9.js";import{d as y}from"./MerchantProductGroupUpdate-96afcfc1.js";import"./ImageUpload-01e9c12b.js";import"./product_group_filters-4f6a90b3.js";import"./CustomCheckboxesField-bbffc7b0.js";import"./FlagCheckboxComponent-557f9457.js";import"./ShopMultiTargetField-dec4b7ea.js";import"./CheckboxComponent-f1c16832.js";import"./input-9848d4d8.js";import"./ShopSelectComponent-a43f5e3c.js";import"./admin_shops_search-16436698.js";import"./shops_paginate-191fe85b.js";import"./fillTypes-f3d97db3.js";var u={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"merchantProductGroup"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"shop_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"description"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"meta"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"show_on_main"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"show_on_merchant_page"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"show_in_merchant_catalog"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"show_on_search"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"sort_order"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_active"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_recommended"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_cart_page"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_complex_solution"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"custom_checkboxes"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"fill_type"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"image"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"shop"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"products"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"pivot_quantity"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"default_price"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"value"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"images"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]}]}}]}},{kind:"Field",name:{kind:"Name",value:"filterValues"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"value"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"filter"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"name"},arguments:[],directives:[]}]}}]}}]}}]}}],loc:{start:0,end:642}};u.loc.source={body:`query ($id: Int!) {
  merchantProductGroup(id: $id) {
    id
    shop_id
    title
    description
    meta
    show_on_main
    show_on_merchant_page
    show_in_merchant_catalog
    show_on_search
    sort_order
    is_active
    is_recommended
    is_cart_page
    is_complex_solution
    custom_checkboxes
    fill_type
    image {
      id
      path
    }
    shop {
      id
      title
    }
    products {
      id
      title
      pivot_quantity
      default_price {
        value
      }
      images {
        path
      }
    }
    filterValues {
      id
      value
      filter {
        id
        name
      }
    }
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function c(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(t){c(t,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(t){c(t,i)}),e.definitions&&e.definitions.forEach(function(t){c(t,i)})}var w={};(function(){u.definitions.forEach(function(i){if(i.name){var n=new Set;c(i,n),w[i.name.value]=n}})})();const D={name:"MerchantProductGroupEditPage",inject:["apollo"],components:{BreadcrumbsComponent:F,IconComponent:f,LoadingIndicator:S,MerchantProductGroupForm:b},data(){return{groupData:null,pageLoading:!0,loading:!1,breadcrumbs:[{to:{name:"merchant-product-groups"},title:"Товарные группы"},{title:"Редактирование"}]}},computed:{groupId(){return this.$route.params.id}},mounted(){this.fetchGroup()},methods:{fetchGroup(){this.pageLoading=!0,this.apollo().admin.query({query:u,variables:{id:parseInt(this.groupId)},fetchPolicy:"network-only"}).then(({data:e})=>{e.merchantProductGroup&&(this.groupData=e.merchantProductGroup)}).catch(e=>{console.error("Error fetching group:",e)}).finally(()=>{this.pageLoading=!1})},handleSubmit(e){this.loading=!0,this.apollo().admin.mutate({mutation:y,variables:{id:parseInt(this.groupId),...e}}).then(({data:i})=>{var n;if((n=i.updateMerchantProductGroup)!=null&&n.id)this.$router.push({name:"merchant-product-groups"});else throw new Error("Ошибка сохранения")}).catch(i=>{console.error("Error updating merchant product group:",i),alert(N(i,"Ошибка сохранения группы"))}).finally(()=>{this.loading=!1})},handleCancel(){this.$router.push({name:"merchant-product-groups"})},goBack(){this.$router.push({name:"merchant-product-groups"})}}},I={class:"page--main merchant-product-group-edit-page"},C=l("div",{class:"merchant-product-group-edit-page__header"},[l("h2",null,"Редактирование товарной группы")],-1),G={key:0,class:"merchant-product-group-edit-page__loading"},E={key:1,class:"merchant-product-group-edit-page__error"},P=l("h3",null,"Группа не найдена",-1),x={key:2,class:"merchant-product-group-edit-page__content"};function B(e,i,n,t,a,r){const p=d("BreadcrumbsComponent"),k=d("LoadingIndicator"),v=d("IconComponent"),g=d("MerchantProductGroupForm");return o(),s("main",I,[m(p,{breadcrumbs:a.breadcrumbs},null,8,["breadcrumbs"]),C,a.pageLoading?(o(),s("div",G,[m(k)])):a.groupData?(o(),s("div",x,[m(g,{initialData:a.groupData,loading:a.loading,isEdit:!0,onSubmit:r.handleSubmit,onCancel:r.handleCancel},null,8,["initialData","loading","onSubmit","onCancel"])])):(o(),s("div",E,[m(v,{name:"error-warning-line"}),P,l("button",{onClick:i[0]||(i[0]=(..._)=>r.goBack&&r.goBack(..._)),class:"btn btn--md"}," Вернуться к списку ")]))])}const W=h(D,[["render",B]]);export{W as default};
