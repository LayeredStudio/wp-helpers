<?php

namespace Layered\Wp;

class CustomPostType {

  public $postType;
  public $taxonomies = [];
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
    $niceName = ucwords(str_replace('-', ' ', $this->postType));

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
      'has_archive'       =>  true,
      'show_in_rest'      =>  true
    ], $args);
    $this->args['labels'] = $labels;

    register_post_type($this->postType, $this->args);

    return $this;
  }

  public function addTaxonomy($taxonomy, $pluralName = null, $args = []) {

    if (is_array($pluralName)) {
      $args = $pluralName;
      $pluralName = null;
    }

    $niceName = ucwords(str_replace('-', ' ', $taxonomy));

    $taxonomy = $this->postType . '-' . strtolower($taxonomy);

    if (!isset( $args['labels'])) {
      $args['labels'] = array();
    }

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

    $this->taxonomies[$taxonomy] = $args;
    $this->addColumns($taxonomy);

    return $this;
  }

  public function addThumbnails($sizes) {
    add_theme_support('post-thumbnails');

    foreach ($sizes as $size => $options) {
      add_image_size( $size, $options[0], isset( $options[1] ) ? $options[1] : 0, isset( $options[2] ) && $options[2] == true );
    }

    return $this;
  }

  public function addColumns($columns) {

    if (!is_array($columns)) {
      $columns = [$columns];
    }

    $newColumns = [];

    // process new columns
    foreach ($columns as $column) {

      if (is_string($column)) {
        
        if ($column == 'author') {
          $column = [
            'id'    =>  'author',
            'name'  =>  __('Author', self::$i18n)
          ];
        } elseif (isset($this->taxonomies[$column])) {
          $column = [
            'id'        =>  $column,
            'sortable'  =>  true,
            'name'      =>  $this->taxonomies[$column]['labels']['name'],
            'value'     =>  function() use($column) {
              global $post;

              $terms = get_the_terms($post->ID, $column);

              if (is_wp_error($terms)) {
                printf('<i>Error: %s</i>', $terms->get_error_message());
              } elseif ($terms) {

                $terms = array_map(function($term) use($column) {

                  $name = $term->name;

                  if (function_exists('qtranxf_useCurrentLanguageIfNotFoundShowEmpty')) {
                    $name = qtranxf_useCurrentLanguageIfNotFoundShowEmpty($term->name);
                  }

                  return '<a href="' . admin_url('edit.php?post_type=' . $this->postType . '&' . $column . '=' . $term->slug) . '">' . $name . '</a>';
                }, $terms);

                echo implode(', ', $terms);
              }
            }
          ];
        } else {
          $column = [
            'id'    =>  $column,
            'name'  =>  $column,
            'value' =>  function() {
              printf('<i>%s</i>', __('undefined value', self::$i18n));
            }
          ];
        }

      }

      $newColumns[$column['id']] = $column;
    }

    add_filter('manage_edit-' . $this->postType . '_columns', function($columns) use($newColumns) {

      foreach ($newColumns as $id => $column) {
        //$columns[$id] = $column['name'];

        if (!isset($column['position'])) {
          $column['position'] = -1;
        } elseif (is_string($column['position'])) {
          $column['position'] = array_search('date', array_keys($columns));
        }

        $newCol = [];
        $newCol[$id] = $column['name'];

        $columns = array_merge(array_slice($columns, 0, $column['position']), $newCol, array_slice($columns, $column['position']));

      }

      return $columns;
    });

    add_action('manage_' . $this->postType . '_posts_custom_column', function($column) use($newColumns) {
      global $post;

      if (isset($newColumns[$column]) && isset($newColumns[$column]['value'])) {
        call_user_func($newColumns[$column]['value']);
      }

    });

    return $this;
  }

  function addMetaBox($title, $fields, $context = 'normal', $priority = 'default') {

    new \Layered\Wp\PostMetaBox($this->postType, $title, $fields, $context, $priority);

    return $this;
  }

}
