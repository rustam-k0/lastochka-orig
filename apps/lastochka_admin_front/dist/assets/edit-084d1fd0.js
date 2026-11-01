import{_,a as f,bR as S,r as s,o as r,c as l,d as o,b as c}from"./index-5da92eef-44242689.js";import{B as N}from"./BreadcrumbsComponent-4e9c6116.js";import{L as b}from"./LoadingIndicator-86875ddd.js";import{M as F}from"./MerchantSaleForm-58096105.js";import{d as y}from"./MerchantSaleUpdate-0d34118c.js";import"./ShopSelectComponent-a43f5e3c.js";import"./input-9848d4d8.js";import"./CheckboxComponent-f1c16832.js";import"./admin_shops_search-16436698.js";import"./DateInput-4bef2dec.js";import"./dayjs.min-ad38b417.js";import"./customParseFormat-1ac90020.js";import"./ImageUpload-01e9c12b.js";import"./CustomCheckboxesField-bbffc7b0.js";import"./FlagCheckboxComponent-557f9457.js";import"./ShopMultiTargetField-dec4b7ea.js";import"./shops_paginate-191fe85b.js";var u={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"merchantSale"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"description"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"shop_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"discount_percent"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"starts_at"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"ends_at"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_active"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"custom_checkboxes"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"image"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"shop"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"products"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"default_price"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"value"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"images"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]}]}}]}}]}}]}}],loc:{start:0,end:439}};u.loc.source={body:`query ($id: Int!) {
  merchantSale(id: $id) {
    id
    title
    description
    shop_id
    discount_percent
    starts_at
    ends_at
    is_active
    custom_checkboxes
    image {
      id
      path
      type
      alt
      size
      origin_name
    }
    shop {
      id
      title
    }
    products {
      id
      title
      default_price {
        value
      }
      images {
        id
        path
      }
    }
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function m(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(a){m(a,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(a){m(a,i)}),e.definitions&&e.definitions.forEach(function(a){m(a,i)})}var I={};(function(){u.definitions.forEach(function(i){if(i.name){var n=new Set;m(i,n),I[i.name.value]=n}})})();const D={name:"MerchantSaleEditPage",inject:["apollo"],components:{BreadcrumbsComponent:N,IconComponent:f,LoadingIndicator:b,MerchantSaleForm:F},data(){return{saleData:null,pageLoading:!0,loading:!1,breadcrumbs:[{to:{name:"merchant-sales"},title:"Акции магазинов"},{title:"Редактирование"}]}},computed:{saleId(){return this.$route.params.id}},mounted(){this.fetchSale()},methods:{fetchSale(){this.pageLoading=!0,this.apollo().admin.query({query:u,variables:{id:parseInt(this.saleId)},fetchPolicy:"network-only"}).then(({data:e})=>{e.merchantSale&&(this.saleData=e.merchantSale)}).catch(e=>{console.error("Error fetching sale:",e)}).finally(()=>{this.pageLoading=!1})},handleSubmit(e){this.loading=!0,this.apollo().admin.mutate({mutation:y,variables:{id:parseInt(this.saleId),...e}}).then(({data:i})=>{var n;if((n=i.updateMerchantSale)!=null&&n.id)this.$router.push({name:"merchant-sales"});else throw new Error("Ошибка сохранения")}).catch(i=>{console.error("Error updating merchant sale:",i),alert(S(i,"Ошибка сохранения акции"))}).finally(()=>{this.loading=!1})},handleCancel(){this.$router.push({name:"merchant-sales"})},goBack(){this.$router.push({name:"merchant-sales"})}}},C={class:"page--main merchant-sale-edit-page"},E=c("div",{class:"merchant-sale-edit-page__header"},[c("h2",null,"Редактирование акции")],-1),x={key:0,class:"merchant-sale-edit-page__loading"},B={key:1,class:"merchant-sale-edit-page__error"},L=c("h3",null,"Акция не найдена",-1),q={key:2,class:"merchant-sale-edit-page__content"};function M(e,i,n,a,t,d){const k=s("BreadcrumbsComponent"),v=s("LoadingIndicator"),p=s("IconComponent"),h=s("MerchantSaleForm");return r(),l("main",C,[o(k,{breadcrumbs:t.breadcrumbs},null,8,["breadcrumbs"]),E,t.pageLoading?(r(),l("div",x,[o(v)])):t.saleData?(r(),l("div",q,[o(h,{initialData:t.saleData,loading:t.loading,isEdit:!0,excludeSaleId:d.saleId,onSubmit:d.handleSubmit,onCancel:d.handleCancel},null,8,["initialData","loading","excludeSaleId","onSubmit","onCancel"])])):(r(),l("div",B,[o(p,{name:"error-warning-line"}),L,c("button",{onClick:i[0]||(i[0]=(...g)=>d.goBack&&d.goBack(...g)),class:"btn btn--md"}," Вернуться к списку ")]))])}const X=_(D,[["render",M]]);export{X as default};
