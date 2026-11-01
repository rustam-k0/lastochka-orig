import{B as _}from"./BreadcrumbsComponent-4e9c6116.js";import{_ as h,a as y,q as f,r as d,o as s,c as r,d as o,b as m}from"./index-5da92eef-44242689.js";import{L as N}from"./LoadingIndicator-86875ddd.js";import{S as F}from"./StoryForm-8c1dfcc9.js";import{d as S}from"./StoryUpdate-15bc0dda.js";import"./DateInput-4bef2dec.js";import"./dayjs.min-ad38b417.js";import"./customParseFormat-1ac90020.js";import"./ImageUpload-01e9c12b.js";import"./admin_products_search-d9a39f16.js";import"./admin_shops_search-16436698.js";import"./CustomCheckboxesField-bbffc7b0.js";import"./FlagCheckboxComponent-557f9457.js";var u={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"admin_stories_item"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"expires_at"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"link_type"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"product_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"shop_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"external_url"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_active"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_quick_category"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"custom_checkboxes"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"position"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"thumbnailImage"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"images"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"product"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"shop"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}}]}}]}}],loc:{start:0,end:484}};u.loc.source={body:`query ($id: Int!) {
  admin_stories_item(id: $id) {
    id
    title
    expires_at
    link_type
    product_id
    shop_id
    external_url
    is_active
    is_quick_category
    custom_checkboxes
    position
    thumbnailImage {
      id
      path
      type
      alt
      size
      origin_name
    }
    images {
      id
      path
      type
      alt
      size
      origin_name
    }
    product {
      id
      title
    }
    shop {
      id
      title
    }
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function l(e,i){if(e.kind==="FragmentSpread")i.add(e.name.value);else if(e.kind==="VariableDefinition"){var n=e.type;n.kind==="NamedType"&&i.add(n.name.value)}e.selectionSet&&e.selectionSet.selections.forEach(function(t){l(t,i)}),e.variableDefinitions&&e.variableDefinitions.forEach(function(t){l(t,i)}),e.definitions&&e.definitions.forEach(function(t){l(t,i)})}var b={};(function(){u.definitions.forEach(function(i){if(i.name){var n=new Set;l(i,n),b[i.name.value]=n}})})();const D={name:"StoryEditPage",inject:["apollo"],components:{BreadcrumbsComponent:_,IconComponent:y,LoadingIndicator:N,StoryForm:F},data(){return{story:null,loading:!1,loadingData:!0}},computed:{storyId(){return parseInt(this.$route.params.id)},breadcrumbs(){var e;return[{to:{name:"stories"},title:"Истории"},{title:((e=this.story)==null?void 0:e.title)||"Редактирование"}]}},mounted(){this.fetchStory()},methods:{fetchStory(){this.loadingData=!0,this.apollo().admin.query({query:u,variables:{id:this.storyId},fetchPolicy:"network-only"}).then(({data:e})=>{this.story=e.admin_stories_item}).catch(e=>{console.error("Error fetching story:",e),f().showNotification({type:"error",message:"Ошибка загрузки истории"})}).finally(()=>{this.loadingData=!1})},handleSubmit(e){this.loading=!0,this.apollo().admin.mutate({mutation:S,variables:{id:this.storyId,...e}}).then(({data:i})=>{var n;if((n=i.StoryUpdate)!=null&&n.id)this.$router.push({name:"stories"});else throw new Error("Ошибка обновления")}).catch(i=>{console.error("Error updating story:",i),alert(i.message||"Ошибка обновления истории")}).finally(()=>{this.loading=!1})},handleCancel(){this.$router.push({name:"stories"})}}},I={class:"page--main story-edit-page"},C=m("div",{class:"story-edit-page__header"},[m("h2",null,"Редактирование истории")],-1),x={key:0,class:"story-edit-page__loading"},E={key:1,class:"story-edit-page__content"},q={key:2,class:"story-edit-page__error"},B=m("h3",null,"История не найдена",-1);function w(e,i,n,t,a,c){const k=d("BreadcrumbsComponent"),v=d("LoadingIndicator"),p=d("StoryForm"),g=d("IconComponent");return s(),r("main",I,[o(k,{breadcrumbs:c.breadcrumbs},null,8,["breadcrumbs"]),C,a.loadingData?(s(),r("div",x,[o(v)])):a.story?(s(),r("div",E,[o(p,{initialData:a.story,loading:a.loading,isEdit:!0,onSubmit:c.handleSubmit,onCancel:c.handleCancel},null,8,["initialData","loading","onSubmit","onCancel"])])):(s(),r("div",q,[o(g,{name:"error-warning-line"}),B,m("button",{onClick:i[0]||(i[0]=V=>e.$router.push({name:"stories"})),class:"btn btn--md"}," Вернуться к списку ")]))])}const J=h(D,[["render",w]]);export{J as default};
