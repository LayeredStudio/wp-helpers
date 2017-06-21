<?php

namespace Layered;

class CustomPostType {

  public $postType;
  public $args;
  public static $i18n = 'layered';

  public static function add($postType, $args = []) {
    return new static($postType, $args);
  }

  function __construct($postType, $args = array()) {
    $this->postType = strtolower($postType);
    if (!isset($args['labels'])) {
      $args['labels'] = array();
    }
    $niceName = ucwords($this->postType);

    $labels = array_merge([
      'name'                =>  $niceName . 's',
      'singular_name'       =>  $niceName,
      'add_new_item'        =>  sprintf( __('Add New %s', self::$i18n), $niceName),
      'edit_item'           =>  sprintf( __('Edit %s', self::$i18n), $niceName),
      'new_item'            =>  sprintf( __('New %s', self::$i18n), $niceName),
      'view_item'           =>  sprintf( __('View %s', self::$i18n), $niceName),
      'search_items'        =>  sprintf( __('Search %ss', self::$i18n), $niceName),
      'not_found'           =>  sprintf( __('No %ss found', self::$i18n), $niceName),
      'not_found_in_trash'  =>  sprintf( __('No %ss found in Trash', self::$i18n), $niceName),
    ], $args['labels']);

    $this->args = array_merge([
      'public'            =>  true,
      'query_var'         =>  true,
      'rewrite'           =>  true,
      'capability_type'   =>  'post',
      'supports'          =>  ['title', 'editor', 'thumbnail', 'excerpt'],
      'has_archive'       =>  true
    ], $args);
    $this->args['labels'] = $labels;

    register_post_type($this->postType, $this->args);

    return $this;
  }

  function addTaxonomy($taxonomy, $pluralName = null, $args = []) {

    if (is_array($pluralName)) {
      $args = $pluralName;
      $pluralName = null;
    }

    $taxonomy = $this->postType . '-' . strtolower($taxonomy);
    if (!isset( $args['labels'])) {
      $args['labels'] = array();
    }
    $niceName = ucwords(str_replace('-', ' ', $taxonomy));
    if (!$pluralName) {
      $pluralName = isset($args['labels']) && isset($args['labels_name']) ? $args['labels']['name'] : $niceName . 's';
    }

    $labels = array_merge([
      'name'              =>  $pluralName,
      'singular_name'     =>  $niceName,
      'search_items'      =>  sprintf(__('Search %s', self::$i18n), $pluralName),
      'all_items'         =>  sprintf(__('All %s', self::$i18n), $pluralName),
      'parent_item'       =>  sprintf(__('Parent %s', self::$i18n), $niceName),
      'parent_item_colon' =>  sprintf(__('Parent %s:', self::$i18n), $niceName),
      'edit_item'         =>  sprintf(__('Edit %s', self::$i18n),  $niceName),
      'update_item'       =>  sprintf(__('Update %s', self::$i18n), $niceName),
      'add_new_item'      =>  sprintf(__('Add New %s', self::$i18n), $niceName),
      'new_item_name'     =>  sprintf(__('New %s Name', self::$i18n), $niceName)
    ], $args['labels'] );

    $args = array_merge([
      'public'            =>  $this->args['public'],
      'hierarchical'      =>  true
    ], $args );
    $args['labels'] = $labels;

    register_taxonomy($taxonomy, $this->postType, $args);

    return $this;
  }

  function addThumbnails($sizes) {
    add_theme_support('post-thumbnails');

    foreach ($sizes as $size => $options) {
      add_image_size( $size, $options[0], isset( $options[1] ) ? $options[1] : 0, isset( $options[2] ) && $options[2] == true );
    }

    return $this;
  }

  function addMetaBox( $title, $fields, $context = 'normal', $priority = 'default' ) {
      if( function_exists( 'rkm_add_meta_box' ) ) {
          rkm_add_meta_box( $this->postType, $title, $fields, $context = 'normal', $priority = 'default' );
      }

      return $this;
  }

}
