import{_ as R,I as O,a as M,O as Q,N as L,S as G,q as Z,U as j,a0 as P,u as J,r as _,o as s,c as g,b as u,t as N,d as r,w as f,e as y,f as k,g as p,F as H,x as E}from"./index-5da92eef-44242689.js";import{S as K}from"./input-9848d4d8.js";import{d as W}from"./categories-0110da6a.js";import{C as X}from"./CheckboxComponent-f1c16832.js";import{C as Y}from"./CustomCheckboxesField-bbffc7b0.js";import{d as ee}from"./EntityDelete-76ab462d.js";import{I as ie}from"./ImageUpload-01e9c12b.js";import"./LoadingIndicator-86875ddd.js";import"./FlagCheckboxComponent-557f9457.js";var T={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"query",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"ordering"}},type:{kind:"ListType",type:{kind:"NamedType",name:{kind:"Name",value:"DefaultOrdering"}}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"admin_categories_item"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}},{kind:"Argument",name:{kind:"Name",value:"ordering"},value:{kind:"Variable",name:{kind:"Name",value:"ordering"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"link"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_cooking"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_perishable"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"show_on_main"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_active"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"without_blocking_price"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"custom_checkboxes"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"tag_text"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"tag_color"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"child_categories"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"link"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"parent"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"link"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"attributes"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"important"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"quantum_step"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"quantum_unit_type_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"stock_mode"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"fixed_stock_quantity"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"fixed_stock_unit_type_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_cooking"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"site_catalog_img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"site_square_img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"site_rect_img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"mobile_column_img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"mobile_square_img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"mobile_rect_img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"child_categories"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"link"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"parent"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"link"},arguments:[],directives:[]}]}}]}}]}},{kind:"Field",name:{kind:"Name",value:"parent"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"link"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"attributes"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"attribute_family"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"assembly_zone"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"important"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"quantum_step"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"quantum_unit_type_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"stock_mode"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"fixed_stock_quantity"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"fixed_stock_unit_type_id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"site_catalog_img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"site_square_img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"site_rect_img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"mobile_column_img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"mobile_square_img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"mobile_rect_img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"background_img"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"path"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"origin_name"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"alt"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"size"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"type"},arguments:[],directives:[]}]}}]}},{kind:"Field",name:{kind:"Name",value:"categories"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"attributes"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"attribute_families"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"is_system"},arguments:[],directives:[]}]}},{kind:"Field",name:{kind:"Name",value:"assembly_zones"},arguments:[],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"title"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:2627}};T.loc.source={body:`query (
  $id: Int
  $ordering: [DefaultOrdering]
) {
  admin_categories_item (
    id: $id
    ordering: $ordering
  ) {
    id
    title
    link
    is_cooking
    is_perishable
    show_on_main
    is_active
    without_blocking_price
    custom_checkboxes
    tag_text
    tag_color
    child_categories {
      id
      title
      link
      parent {
        id
        title
        link
      }
      attributes {
        id
        title
      }
      important
      quantum_step
      quantum_unit_type_id
      stock_mode
      fixed_stock_quantity
      fixed_stock_unit_type_id
      is_cooking
      site_catalog_img {
        id
        path
        origin_name
        alt
        size
        type
      }
      site_square_img {
        id
        path
        origin_name
        alt
        size
        type
      }
      site_rect_img {
        id
        path
        origin_name
        alt
        size
        type
      }
      mobile_column_img {
        id
        path
        origin_name
        alt
        size
        type
      }
      mobile_square_img {
        id
        path
        origin_name
        alt
        size
        type
      }
      mobile_rect_img {
        id
        path
        origin_name
        alt
        size
        type
      }
      child_categories {
        id
        title
        link
        parent {
          id
          title
          link
        }
      }
    }
    parent {
      id
      title
      link
    }
    attributes {
      id
      title
    }
    attribute_family {
      id
      title
    }
    assembly_zone {
      id
      title
    }
    important
    quantum_step
    quantum_unit_type_id
    stock_mode
    fixed_stock_quantity
    fixed_stock_unit_type_id
    site_catalog_img {
      id
      path
      origin_name
      alt
      size
      type
    }
    site_square_img {
      id
      path
      origin_name
      alt
      size
      type
    }
    site_rect_img {
      id
      path
      origin_name
      alt
      size
      type
    }
    mobile_column_img {
      id
      path
      origin_name
      alt
      size
      type
    }
    mobile_square_img {
      id
      path
      origin_name
      alt
      size
      type
    }
    mobile_rect_img {
      id
      path
      origin_name
      alt
      size
      type
    }
    background_img {
      id
      path
      origin_name
      alt
      size
      type
    }
  }
  categories {
    id
    title
  }
  attributes {
    id
    title
  }
  attribute_families {
    id
    title
    is_system
  }
  assembly_zones {
    id
    title
  }
}

`,name:"GraphQL request",locationOffset:{line:1,column:1}};function h(i,e){if(i.kind==="FragmentSpread")e.add(i.name.value);else if(i.kind==="VariableDefinition"){var n=i.type;n.kind==="NamedType"&&e.add(n.name.value)}i.selectionSet&&i.selectionSet.selections.forEach(function(t){h(t,e)}),i.variableDefinitions&&i.variableDefinitions.forEach(function(t){h(t,e)}),i.definitions&&i.definitions.forEach(function(t){h(t,e)})}var ne={};(function(){T.definitions.forEach(function(e){if(e.name){var n=new Set;h(e,n),ne[e.name.value]=n}})})();var z={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"title"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"parent"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"important"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"site_catalog_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"site_square_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"site_rect_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"mobile_column_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"mobile_square_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"mobile_rect_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"background_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_cooking"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_perishable"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"show_on_main"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_active"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"without_blocking_price"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"attribute_family_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"assembly_zone_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"tag_text"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"tag_color"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"CategoryUpdate"},arguments:[{kind:"Argument",name:{kind:"Name",value:"id"},value:{kind:"Variable",name:{kind:"Name",value:"id"}}},{kind:"Argument",name:{kind:"Name",value:"title"},value:{kind:"Variable",name:{kind:"Name",value:"title"}}},{kind:"Argument",name:{kind:"Name",value:"parent"},value:{kind:"Variable",name:{kind:"Name",value:"parent"}}},{kind:"Argument",name:{kind:"Name",value:"important"},value:{kind:"Variable",name:{kind:"Name",value:"important"}}},{kind:"Argument",name:{kind:"Name",value:"site_catalog_img"},value:{kind:"Variable",name:{kind:"Name",value:"site_catalog_img"}}},{kind:"Argument",name:{kind:"Name",value:"site_square_img"},value:{kind:"Variable",name:{kind:"Name",value:"site_square_img"}}},{kind:"Argument",name:{kind:"Name",value:"site_rect_img"},value:{kind:"Variable",name:{kind:"Name",value:"site_rect_img"}}},{kind:"Argument",name:{kind:"Name",value:"mobile_column_img"},value:{kind:"Variable",name:{kind:"Name",value:"mobile_column_img"}}},{kind:"Argument",name:{kind:"Name",value:"mobile_square_img"},value:{kind:"Variable",name:{kind:"Name",value:"mobile_square_img"}}},{kind:"Argument",name:{kind:"Name",value:"mobile_rect_img"},value:{kind:"Variable",name:{kind:"Name",value:"mobile_rect_img"}}},{kind:"Argument",name:{kind:"Name",value:"background_img"},value:{kind:"Variable",name:{kind:"Name",value:"background_img"}}},{kind:"Argument",name:{kind:"Name",value:"is_cooking"},value:{kind:"Variable",name:{kind:"Name",value:"is_cooking"}}},{kind:"Argument",name:{kind:"Name",value:"is_perishable"},value:{kind:"Variable",name:{kind:"Name",value:"is_perishable"}}},{kind:"Argument",name:{kind:"Name",value:"show_on_main"},value:{kind:"Variable",name:{kind:"Name",value:"show_on_main"}}},{kind:"Argument",name:{kind:"Name",value:"is_active"},value:{kind:"Variable",name:{kind:"Name",value:"is_active"}}},{kind:"Argument",name:{kind:"Name",value:"without_blocking_price"},value:{kind:"Variable",name:{kind:"Name",value:"without_blocking_price"}}},{kind:"Argument",name:{kind:"Name",value:"attribute_family_id"},value:{kind:"Variable",name:{kind:"Name",value:"attribute_family_id"}}},{kind:"Argument",name:{kind:"Name",value:"assembly_zone_id"},value:{kind:"Variable",name:{kind:"Name",value:"assembly_zone_id"}}},{kind:"Argument",name:{kind:"Name",value:"custom_checkboxes"},value:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}}},{kind:"Argument",name:{kind:"Name",value:"tag_text"},value:{kind:"Variable",name:{kind:"Name",value:"tag_text"}}},{kind:"Argument",name:{kind:"Name",value:"tag_color"},value:{kind:"Variable",name:{kind:"Name",value:"tag_color"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"code"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"message"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:1295}};z.loc.source={body:`mutation(
  $id: Int
  $title: String
  $parent: Int
  $important: Boolean
  $site_catalog_img: ImgInput
  $site_square_img: ImgInput
  $site_rect_img: ImgInput
  $mobile_column_img: ImgInput
  $mobile_square_img: ImgInput
  $mobile_rect_img: ImgInput
  $background_img: ImgInput
  $is_cooking: Boolean,
  $is_perishable: Boolean,
  $show_on_main: Boolean,
  $is_active: Boolean,
  $without_blocking_price: Boolean
  $attribute_family_id: Int
  $assembly_zone_id: Int
  $custom_checkboxes: String
  $tag_text: String
  $tag_color: String
) {
  CategoryUpdate(
    id: $id
    title: $title
    parent: $parent
    important: $important
    site_catalog_img: $site_catalog_img
    site_square_img: $site_square_img
    site_rect_img: $site_rect_img
    mobile_column_img: $mobile_column_img
    mobile_square_img: $mobile_square_img
    mobile_rect_img: $mobile_rect_img
    background_img: $background_img
    is_cooking: $is_cooking,
    is_perishable: $is_perishable,
    show_on_main: $show_on_main,
    is_active: $is_active,
    without_blocking_price: $without_blocking_price
    attribute_family_id: $attribute_family_id
    assembly_zone_id: $assembly_zone_id
    custom_checkboxes: $custom_checkboxes
    tag_text: $tag_text
    tag_color: $tag_color
  ) {
    code
    message
  }
}


`,name:"GraphQL request",locationOffset:{line:1,column:1}};function V(i,e){if(i.kind==="FragmentSpread")e.add(i.name.value);else if(i.kind==="VariableDefinition"){var n=i.type;n.kind==="NamedType"&&e.add(n.name.value)}i.selectionSet&&i.selectionSet.selections.forEach(function(t){V(t,e)}),i.variableDefinitions&&i.variableDefinitions.forEach(function(t){V(t,e)}),i.definitions&&i.definitions.forEach(function(t){V(t,e)})}var ae={};(function(){z.definitions.forEach(function(e){if(e.name){var n=new Set;V(e,n),ae[e.name.value]=n}})})();var A={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"title"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"parent"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"important"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"site_catalog_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"site_square_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"site_rect_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"mobile_column_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"mobile_square_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"mobile_rect_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"background_img"}},type:{kind:"NamedType",name:{kind:"Name",value:"ImgInput"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_cooking"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_perishable"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"show_on_main"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"is_active"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"without_blocking_price"}},type:{kind:"NamedType",name:{kind:"Name",value:"Boolean"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"attribute_family_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"assembly_zone_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"tag_text"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"tag_color"}},type:{kind:"NamedType",name:{kind:"Name",value:"String"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"CategoryCreate"},arguments:[{kind:"Argument",name:{kind:"Name",value:"title"},value:{kind:"Variable",name:{kind:"Name",value:"title"}}},{kind:"Argument",name:{kind:"Name",value:"parent"},value:{kind:"Variable",name:{kind:"Name",value:"parent"}}},{kind:"Argument",name:{kind:"Name",value:"important"},value:{kind:"Variable",name:{kind:"Name",value:"important"}}},{kind:"Argument",name:{kind:"Name",value:"site_catalog_img"},value:{kind:"Variable",name:{kind:"Name",value:"site_catalog_img"}}},{kind:"Argument",name:{kind:"Name",value:"site_square_img"},value:{kind:"Variable",name:{kind:"Name",value:"site_square_img"}}},{kind:"Argument",name:{kind:"Name",value:"site_rect_img"},value:{kind:"Variable",name:{kind:"Name",value:"site_rect_img"}}},{kind:"Argument",name:{kind:"Name",value:"mobile_column_img"},value:{kind:"Variable",name:{kind:"Name",value:"mobile_column_img"}}},{kind:"Argument",name:{kind:"Name",value:"mobile_square_img"},value:{kind:"Variable",name:{kind:"Name",value:"mobile_square_img"}}},{kind:"Argument",name:{kind:"Name",value:"mobile_rect_img"},value:{kind:"Variable",name:{kind:"Name",value:"mobile_rect_img"}}},{kind:"Argument",name:{kind:"Name",value:"background_img"},value:{kind:"Variable",name:{kind:"Name",value:"background_img"}}},{kind:"Argument",name:{kind:"Name",value:"is_cooking"},value:{kind:"Variable",name:{kind:"Name",value:"is_cooking"}}},{kind:"Argument",name:{kind:"Name",value:"is_perishable"},value:{kind:"Variable",name:{kind:"Name",value:"is_perishable"}}},{kind:"Argument",name:{kind:"Name",value:"show_on_main"},value:{kind:"Variable",name:{kind:"Name",value:"show_on_main"}}},{kind:"Argument",name:{kind:"Name",value:"is_active"},value:{kind:"Variable",name:{kind:"Name",value:"is_active"}}},{kind:"Argument",name:{kind:"Name",value:"without_blocking_price"},value:{kind:"Variable",name:{kind:"Name",value:"without_blocking_price"}}},{kind:"Argument",name:{kind:"Name",value:"attribute_family_id"},value:{kind:"Variable",name:{kind:"Name",value:"attribute_family_id"}}},{kind:"Argument",name:{kind:"Name",value:"assembly_zone_id"},value:{kind:"Variable",name:{kind:"Name",value:"assembly_zone_id"}}},{kind:"Argument",name:{kind:"Name",value:"custom_checkboxes"},value:{kind:"Variable",name:{kind:"Name",value:"custom_checkboxes"}}},{kind:"Argument",name:{kind:"Name",value:"tag_text"},value:{kind:"Variable",name:{kind:"Name",value:"tag_text"}}},{kind:"Argument",name:{kind:"Name",value:"tag_color"},value:{kind:"Variable",name:{kind:"Name",value:"tag_color"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"code"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"message"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:1268}};A.loc.source={body:`mutation(
  $title: String
  $parent: Int
  $important: Boolean
  $site_catalog_img: ImgInput
  $site_square_img: ImgInput
  $site_rect_img: ImgInput
  $mobile_column_img: ImgInput
  $mobile_square_img: ImgInput
  $mobile_rect_img: ImgInput
  $background_img: ImgInput
  $is_cooking: Boolean,
  $is_perishable: Boolean,
  $show_on_main: Boolean,
  $is_active: Boolean,
  $without_blocking_price: Boolean
  $attribute_family_id: Int
  $assembly_zone_id: Int
  $custom_checkboxes: String
  $tag_text: String
  $tag_color: String
) {
  CategoryCreate(
    title: $title
    parent: $parent
    important: $important
    site_catalog_img: $site_catalog_img
    site_square_img: $site_square_img
    site_rect_img: $site_rect_img
    mobile_column_img: $mobile_column_img
    mobile_square_img: $mobile_square_img
    mobile_rect_img: $mobile_rect_img
    background_img: $background_img
    is_cooking: $is_cooking
    is_perishable: $is_perishable
    show_on_main: $show_on_main
    is_active: $is_active
    without_blocking_price: $without_blocking_price
    attribute_family_id: $attribute_family_id
    assembly_zone_id: $assembly_zone_id
    custom_checkboxes: $custom_checkboxes
    tag_text: $tag_text
    tag_color: $tag_color
  ) {
    code
    message
  }
}


`,name:"GraphQL request",locationOffset:{line:1,column:1}};function S(i,e){if(i.kind==="FragmentSpread")e.add(i.name.value);else if(i.kind==="VariableDefinition"){var n=i.type;n.kind==="NamedType"&&e.add(n.name.value)}i.selectionSet&&i.selectionSet.selections.forEach(function(t){S(t,e)}),i.variableDefinitions&&i.variableDefinitions.forEach(function(t){S(t,e)}),i.definitions&&i.definitions.forEach(function(t){S(t,e)})}var te={};(function(){A.definitions.forEach(function(e){if(e.name){var n=new Set;S(e,n),te[e.name.value]=n}})})();var q={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",name:{kind:"Name",value:"SetCategoryQuantum"},variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"category_id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"quantum_step"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"quantum_unit_type_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"SetCategoryQuantum"},arguments:[{kind:"Argument",name:{kind:"Name",value:"category_id"},value:{kind:"Variable",name:{kind:"Name",value:"category_id"}}},{kind:"Argument",name:{kind:"Name",value:"quantum_step"},value:{kind:"Variable",name:{kind:"Name",value:"quantum_step"}}},{kind:"Argument",name:{kind:"Name",value:"quantum_unit_type_id"},value:{kind:"Variable",name:{kind:"Name",value:"quantum_unit_type_id"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"quantum_step"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"quantum_unit_type_id"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:277}};q.loc.source={body:`mutation SetCategoryQuantum($category_id: Int!, $quantum_step: Int, $quantum_unit_type_id: Int) {
  SetCategoryQuantum(category_id: $category_id, quantum_step: $quantum_step, quantum_unit_type_id: $quantum_unit_type_id) {
    id
    quantum_step
    quantum_unit_type_id
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function F(i,e){if(i.kind==="FragmentSpread")e.add(i.name.value);else if(i.kind==="VariableDefinition"){var n=i.type;n.kind==="NamedType"&&e.add(n.name.value)}i.selectionSet&&i.selectionSet.selections.forEach(function(t){F(t,e)}),i.variableDefinitions&&i.variableDefinitions.forEach(function(t){F(t,e)}),i.definitions&&i.definitions.forEach(function(t){F(t,e)})}var C={};(function(){q.definitions.forEach(function(e){if(e.name){var n=new Set;F(e,n),C[e.name.value]=n}})})();function U(i,e){for(var n=0;n<i.definitions.length;n++){var t=i.definitions[n];if(t.name&&t.name.value==e)return t}}function le(i,e){var n={kind:i.kind,definitions:[U(i,e)]};i.hasOwnProperty("loc")&&(n.loc=i.loc);var t=C[e]||new Set,a=new Set,d=new Set;for(t.forEach(function(m){d.add(m)});d.size>0;){var c=d;d=new Set,c.forEach(function(m){if(!a.has(m)){a.add(m);var o=C[m]||new Set;o.forEach(function(b){d.add(b)})}})}return a.forEach(function(m){var o=U(i,m);o&&n.definitions.push(o)}),n}le(q,"SetCategoryQuantum");var I={kind:"Document",definitions:[{kind:"OperationDefinition",operation:"mutation",name:{kind:"Name",value:"SetCategoryStockMode"},variableDefinitions:[{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"category_id"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"Int"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"stock_mode"}},type:{kind:"NonNullType",type:{kind:"NamedType",name:{kind:"Name",value:"String"}}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"fixed_stock_quantity"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]},{kind:"VariableDefinition",variable:{kind:"Variable",name:{kind:"Name",value:"fixed_stock_unit_type_id"}},type:{kind:"NamedType",name:{kind:"Name",value:"Int"}},directives:[]}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"SetCategoryStockMode"},arguments:[{kind:"Argument",name:{kind:"Name",value:"category_id"},value:{kind:"Variable",name:{kind:"Name",value:"category_id"}}},{kind:"Argument",name:{kind:"Name",value:"stock_mode"},value:{kind:"Variable",name:{kind:"Name",value:"stock_mode"}}},{kind:"Argument",name:{kind:"Name",value:"fixed_stock_quantity"},value:{kind:"Variable",name:{kind:"Name",value:"fixed_stock_quantity"}}},{kind:"Argument",name:{kind:"Name",value:"fixed_stock_unit_type_id"},value:{kind:"Variable",name:{kind:"Name",value:"fixed_stock_unit_type_id"}}}],directives:[],selectionSet:{kind:"SelectionSet",selections:[{kind:"Field",name:{kind:"Name",value:"id"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"stock_mode"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"fixed_stock_quantity"},arguments:[],directives:[]},{kind:"Field",name:{kind:"Name",value:"fixed_stock_unit_type_id"},arguments:[],directives:[]}]}}]}}],loc:{start:0,end:391}};I.loc.source={body:`mutation SetCategoryStockMode($category_id: Int!, $stock_mode: String!, $fixed_stock_quantity: Int, $fixed_stock_unit_type_id: Int) {
  SetCategoryStockMode(category_id: $category_id, stock_mode: $stock_mode, fixed_stock_quantity: $fixed_stock_quantity, fixed_stock_unit_type_id: $fixed_stock_unit_type_id) {
    id
    stock_mode
    fixed_stock_quantity
    fixed_stock_unit_type_id
  }
}
`,name:"GraphQL request",locationOffset:{line:1,column:1}};function x(i,e){if(i.kind==="FragmentSpread")e.add(i.name.value);else if(i.kind==="VariableDefinition"){var n=i.type;n.kind==="NamedType"&&e.add(n.name.value)}i.selectionSet&&i.selectionSet.selections.forEach(function(t){x(t,e)}),i.variableDefinitions&&i.variableDefinitions.forEach(function(t){x(t,e)}),i.definitions&&i.definitions.forEach(function(t){x(t,e)})}var w={};(function(){I.definitions.forEach(function(e){if(e.name){var n=new Set;x(e,n),w[e.name.value]=n}})})();function B(i,e){for(var n=0;n<i.definitions.length;n++){var t=i.definitions[n];if(t.name&&t.name.value==e)return t}}function de(i,e){var n={kind:i.kind,definitions:[B(i,e)]};i.hasOwnProperty("loc")&&(n.loc=i.loc);var t=w[e]||new Set,a=new Set,d=new Set;for(t.forEach(function(m){d.add(m)});d.size>0;){var c=d;d=new Set,c.forEach(function(m){if(!a.has(m)){a.add(m);var o=w[m]||new Set;o.forEach(function(b){d.add(b)})}})}return a.forEach(function(m){var o=B(i,m);o&&n.definitions.push(o)}),n}de(I,"SetCategoryStockMode");const D="#3B82F6",me={name:"CategoryPage",inject:["apollo"],components:{ImageUploadComponent:ie,CheckboxComponent:X,CustomCheckboxesField:Y,SelectComponent:K,InputComponent:O,IconComponent:M},data(){return{parentCategories:[],attributeFamilies:[],assemblyZones:[],quantumUnits:[{id:2,title:"Грамм"},{id:1,title:"Штука"}],stockModes:[{id:"normal",title:"Стандартный учёт"},{id:"ignore",title:"Не учитывать остатки"},{id:"fixed",title:"Фиксированный дневной лимит"}],stockUnits:[{id:2,title:"Грамм"},{id:1,title:"Штука"}],form:{title:{value:null,errors:[]},parent:{value:null,errors:[]},attribute_family_id:{value:null,errors:[]},without_blocking_price:{value:!1,errors:[]},important:{value:!1,errors:[]},quantum_step:{value:null,errors:[]},quantum_unit_type_id:{value:null,errors:[]},is_cooking:{value:null,errors:null},is_perishable:{value:null,errors:null},show_on_main:{value:!1,errors:[]},tag_text:{value:null,errors:[]},tag_color:{value:D,errors:[]},is_active:{value:!0,errors:[]},custom_checkboxes:{value:{},errors:[]},site_catalog_img:{value:null,errors:[]},site_square_img:{value:null,errors:[]},site_rect_img:{value:null,errors:[]},mobile_column_img:{value:null,errors:[]},mobile_square_img:{value:null,errors:[]},mobile_rect_img:{value:null,errors:[]},background_img:{value:null,errors:[]},assembly_zone:{value:null,errors:[]},stock_mode:{value:null,errors:[]},fixed_stock_quantity:{value:null,errors:[]},fixed_stock_unit_type_id:{value:null,errors:[]}}}},mounted(){this.get()},methods:{parseGqlErrors:Q,resetErrors:L,get(){let i={id:parseInt(this.id)};this.apollo().admin.query({query:this.id?T:W,variables:i}).then(({data:e})=>{if(e.admin_categories_item&&this.id){let n=e.admin_categories_item;Object.keys(this.form).forEach(t=>{this.form[t].value=n[t]}),this.form.custom_checkboxes.value=G(n.custom_checkboxes),n.attribute_family&&(this.form.attribute_family_id.value=n.attribute_family),this.form.quantum_unit_type_id.value=this.quantumUnits.find(t=>t.id===n.quantum_unit_type_id)??null,this.form.stock_mode.value=this.stockModes.find(t=>t.id===(n.stock_mode||"normal"))??this.stockModes[0],this.form.fixed_stock_quantity.value=n.fixed_stock_quantity??null,this.form.fixed_stock_unit_type_id.value=this.stockUnits.find(t=>t.id===n.fixed_stock_unit_type_id)??null,this.form.tag_color.value=n.tag_color||D}this.parentCategories=e.categories,this.attributeFamilies=e.attribute_families||[],this.assemblyZones=e.assembly_zones||[]}).catch(()=>{})},submit(){this.resetErrors();let i={};Object.keys(this.form).forEach(n=>{i[n]=this.form[n].value??void 0}),i.id=parseInt(this.id)||void 0,i.parent=this.form.parent.value?this.form.parent.value.id:null,i.attribute_family_id=this.form.attribute_family_id.value?this.form.attribute_family_id.value.id:null,i.important=this.isChild?!1:this.form.important.value,i.assembly_zone_id=this.form.assembly_zone.value?this.form.assembly_zone.value.id:null,delete i.assembly_zone,delete i.quantum_step,delete i.quantum_unit_type_id,delete i.stock_mode,delete i.fixed_stock_quantity,delete i.fixed_stock_unit_type_id,i.custom_checkboxes=JSON.stringify(this.form.custom_checkboxes.value||{});const e=(this.form.tag_text.value||"").trim();i.tag_text=e||null,i.tag_color=e?this.form.tag_color.value||D:null,this.apollo().admin.mutate({mutation:this.id?z:A,variables:i}).then(async()=>{this.id&&(await this.saveQuantum(),await this.saveStockMode()),this.$router.push({name:"categories"})}).catch(({graphQLErrors:n})=>{this.parseGqlErrors(n)})},async saveQuantum(){const i=this.form.quantum_step.value,e=i===null||i===""?null:parseInt(i),n=this.form.quantum_unit_type_id.value,t=e===null?null:(n==null?void 0:n.id)??n??null;try{await this.apollo().admin.mutate({mutation:q,variables:{category_id:parseInt(this.id),quantum_step:e,quantum_unit_type_id:t}})}catch(a){console.error("SetCategoryQuantum",a)}},async saveStockMode(){var n,t;const i=((n=this.form.stock_mode.value)==null?void 0:n.id)??"normal",e={category_id:parseInt(this.id),stock_mode:i,fixed_stock_quantity:i==="fixed"&&parseInt(this.form.fixed_stock_quantity.value)||null,fixed_stock_unit_type_id:i==="fixed"?((t=this.form.fixed_stock_unit_type_id.value)==null?void 0:t.id)??null:null};try{await this.apollo().admin.mutate({mutation:I,variables:e})}catch(a){console.error("SetCategoryStockMode",a)}},remove(){confirm("Вы уверены?")&&this.apollo().admin.mutate({mutation:ee,variables:{id:parseInt(this.id),table:"categories"}}).then(()=>{this.callback&&this.callback(),this.$router.push({name:"categories"}),Z().modals=[]})}},computed:{checkboxStore(){return j()},fieldsStore(){return P()},assemblyZonesEnabled(){return J().isModuleEnabled("assembly_zones")},isChild(){return!!this.form.parent.value},id(){return this.$route.params.id}}},re={class:"page category-page"},oe={class:"default-modal__body category-page__body"},se={class:"category-page__tag"},ue={class:"category-page_msg"},ke={class:"category-page__wrapper"},ce=u("p",null,"Фото категории",-1),ve={class:"category-page__images"},_e={key:4,class:"category-page__wrapper"},ge=u("p",null,"Фоновое изображение",-1),pe={class:"category-page__images"},be={key:5,class:"category-page__quantum"},Ne={key:6,class:"category-page__stock"},fe={class:"category-page__footer"};function ye(i,e,n,t,a,d){const c=_("InputComponent"),m=_("CheckboxComponent"),o=_("CustomCheckboxesField"),b=_("IconComponent"),$=_("ImageUploadComponent"),v=_("SelectComponent");return s(),g("div",re,[u("h2",null,N(d.id?"Редактирование категории":"Новая категория"),1),u("div",oe,[r(c,{modelValue:a.form.title.value,"onUpdate:modelValue":e[0]||(e[0]=l=>a.form.title.value=l),errors:a.form.title.errors,title:"Название категории",placeholder:"Название категории",required:""},null,8,["modelValue","errors"]),r(m,{modelValue:a.form.is_active.value,"onUpdate:modelValue":e[1]||(e[1]=l=>a.form.is_active.value=l),green:"",class:"category-page__important"},{default:f(()=>[p("Активна (выводится на витрине)")]),_:1},8,["modelValue"]),!d.isChild&&d.checkboxStore.isEnabled("Category","important")?(s(),y(m,{key:0,modelValue:a.form.important.value,"onUpdate:modelValue":e[2]||(e[2]=l=>a.form.important.value=l),green:"",class:"category-page__important"},{default:f(()=>[p(N(d.checkboxStore.getLabel("Category","important")),1)]),_:1},8,["modelValue"])):k("",!0),d.checkboxStore.isEnabled("Category","is_cooking")?(s(),y(m,{key:1,modelValue:a.form.is_cooking.value,"onUpdate:modelValue":e[3]||(e[3]=l=>a.form.is_cooking.value=l),green:"",class:"category-page__important"},{default:f(()=>[p(N(d.checkboxStore.getLabel("Category","is_cooking")),1)]),_:1},8,["modelValue"])):k("",!0),d.checkboxStore.isEnabled("Category","is_perishable")?(s(),y(m,{key:2,modelValue:a.form.is_perishable.value,"onUpdate:modelValue":e[4]||(e[4]=l=>a.form.is_perishable.value=l),green:"",class:"category-page__important"},{default:f(()=>[p(N(d.checkboxStore.getLabel("Category","is_perishable")),1)]),_:1},8,["modelValue"])):k("",!0),d.checkboxStore.isEnabled("Category","show_on_main")?(s(),y(m,{key:3,modelValue:a.form.show_on_main.value,"onUpdate:modelValue":e[5]||(e[5]=l=>a.form.show_on_main.value=l),green:"",class:"category-page__important"},{default:f(()=>[p(N(d.checkboxStore.getLabel("Category","show_on_main")),1)]),_:1},8,["modelValue"])):k("",!0),r(o,{entity:"Category",modelValue:a.form.custom_checkboxes.value,"onUpdate:modelValue":e[6]||(e[6]=l=>a.form.custom_checkboxes.value=l),class:"category-page__important"},null,8,["modelValue"]),u("div",se,[r(c,{modelValue:a.form.tag_text.value,"onUpdate:modelValue":e[7]||(e[7]=l=>a.form.tag_text.value=l),errors:a.form.tag_text.errors,title:"Текст тега",placeholder:"Например: Готовим свежие каждый день",messages:["Тег отображается только у конечных категорий, к которым привязаны товары"]},null,8,["modelValue","errors"]),r(c,{modelValue:a.form.tag_color.value,"onUpdate:modelValue":e[8]||(e[8]=l=>a.form.tag_color.value=l),errors:a.form.tag_color.errors,title:"Цвет тега",type:"color",class:"category-page__tag-color"},null,8,["modelValue","errors"])]),u("p",ue,[r(b,{name:"information-line"}),p(" Вы можете загрузить лишь один формат фото из представленных ")]),u("div",ke,[ce,u("div",ve,[r($,{modelValue:a.form.site_square_img.value,"onUpdate:modelValue":e[9]||(e[9]=l=>a.form.site_square_img.value=l),text:"Загрузить фото",class:"category-square-site"},null,8,["modelValue"])])]),d.fieldsStore.isEnabled("Category","background_img")?(s(),g("div",_e,[ge,u("div",pe,[r($,{modelValue:a.form.background_img.value,"onUpdate:modelValue":e[10]||(e[10]=l=>a.form.background_img.value=l),text:"Загрузить фото",class:"category-background"},null,8,["modelValue"])])])):k("",!0),d.id?(s(),g("div",be,[r(c,{modelValue:a.form.quantum_step.value,"onUpdate:modelValue":e[11]||(e[11]=l=>a.form.quantum_step.value=l),errors:a.form.quantum_step.errors,title:"Шаг кванта (продавать порциями по)",placeholder:"—",messages:["Цена пересчитывается из цены 1С автоматически. Пусто — продажа за базовую единицу. Применяется к товарам совпадающего семейства (масса/объём/штуки)."]},null,8,["modelValue","errors","messages"]),r(v,{title:"Единица",placeholder:"Единица","label-name":"title",modelValue:a.form.quantum_unit_type_id.value,"onUpdate:modelValue":e[12]||(e[12]=l=>a.form.quantum_unit_type_id.value=l),errors:a.form.quantum_unit_type_id.errors,options:a.quantumUnits,clearable:""},null,8,["modelValue","errors","options"])])):k("",!0),d.id?(s(),g("div",Ne,[r(v,{title:"Учёт остатков",placeholder:"Режим","label-name":"title",modelValue:a.form.stock_mode.value,"onUpdate:modelValue":e[13]||(e[13]=l=>a.form.stock_mode.value=l),errors:a.form.stock_mode.errors,options:a.stockModes},null,8,["modelValue","errors","options"]),a.form.stock_mode.value&&a.form.stock_mode.value.id==="fixed"?(s(),g(H,{key:0},[r(c,{modelValue:a.form.fixed_stock_quantity.value,"onUpdate:modelValue":e[14]||(e[14]=l=>a.form.fixed_stock_quantity.value=l),errors:a.form.fixed_stock_quantity.errors,title:"Дневной лимит",placeholder:"Например, 100"},null,8,["modelValue","errors"]),r(v,{title:"Единица",placeholder:"Единица","label-name":"title",modelValue:a.form.fixed_stock_unit_type_id.value,"onUpdate:modelValue":e[15]||(e[15]=l=>a.form.fixed_stock_unit_type_id.value=l),errors:a.form.fixed_stock_unit_type_id.errors,options:a.stockUnits},null,8,["modelValue","errors","options"])],64)):k("",!0)])):k("",!0),r(v,{modelValue:a.form.attribute_family_id.value,"onUpdate:modelValue":e[16]||(e[16]=l=>a.form.attribute_family_id.value=l),errors:a.form.attribute_family_id.errors,options:a.attributeFamilies,"label-name":"title",title:"Семейство атрибутов",placeholder:"Выберите семейство атрибутов",clearable:""},null,8,["modelValue","errors","options"]),r(v,{modelValue:a.form.parent.value,"onUpdate:modelValue":e[17]||(e[17]=l=>a.form.parent.value=l),errors:a.form.parent.errors,options:a.parentCategories,"label-name":"title",title:"Родительская подкатегория",placeholder:"Родительская подкатегория",clearable:""},null,8,["modelValue","errors","options"]),d.assemblyZonesEnabled?(s(),y(v,{key:7,modelValue:a.form.assembly_zone.value,"onUpdate:modelValue":e[18]||(e[18]=l=>a.form.assembly_zone.value=l),errors:a.form.assembly_zone.errors,options:a.assemblyZones,"label-name":"title",title:"Зона сборки",placeholder:"Не выбрана",clearable:""},null,8,["modelValue","errors","options"])):k("",!0)]),u("div",fe,[d.id?(s(),g("button",{key:0,onClick:e[19]||(e[19]=E((...l)=>d.remove&&d.remove(...l),["prevent"])),class:"btn btn--danger btn--md"},"Удалить")):k("",!0),u("button",{onClick:e[20]||(e[20]=E((...l)=>d.submit&&d.submit(...l),["prevent"])),class:"btn btn--main btn--md btn--save"},"Сохранить")])])}const we=R(me,[["render",ye]]);export{we as default};
