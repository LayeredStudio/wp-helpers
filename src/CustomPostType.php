<?php
namespace Layered\Wp;

final class CustomPostType {

	protected $postType;
	protected $args;
	protected $taxonomies = [];

	public static function add(string $postType, array $args = []): self {
		return new static($postType, $args);
	}

	public function __construct(string $postType, array $args = []) {
		$this->postType = sanitize_key($postType);
		$args['labels'] = $args['labels'] ?? [];

		// TODO use inflector for nice name & pluralize
		$args['labels']['singular_name'] = $args['labels']['singular_name'] ?? ucwords(str_replace(['-', '_'], ' ', $this->postType));
		$args['labels']['name'] = $args['labels']['name'] ?? $args['labels']['singular_name'] . 's';

		$labels = wp_parse_args($args['labels'], [
			'name'					=>	$args['labels']['name'],
			'singular_name'			=>	$args['labels']['singular_name'],
			'add_new_item'			=>	sprintf(__('Add New %s', 'layered'), $args['labels']['singular_name']),
			'edit_item'				=>	sprintf(__('Edit %s', 'layered'), $args['labels']['singular_name']),
			'new_item'				=>	sprintf(__('New %s', 'layered'), $args['labels']['singular_name']),
			'view_item'				=>	sprintf(__('View %s', 'layered'), $args['labels']['singular_name']),
			'search_items'			=>	sprintf(__('Search %s', 'layered'), $args['labels']['name']),
			'not_found'				=>	sprintf(__('No %s found', 'layered'), $args['labels']['name']),
			'not_found_in_trash'	=>	sprintf(__('No %s found in Trash', 'layered'), $args['labels']['name'])
		]);

		$this->args = wp_parse_args($args, [
			'public'			=>	true,
			'supports'			=>	['title', 'editor', 'thumbnail'],
			'has_archive'		=>	true,
			'show_in_rest'		=>	true
		]);
		$this->args['labels'] = $labels;

		register_post_type($this->postType, $this->args);
	}

	public function addTaxonomy(string $taxonomy, array $args = []): self {
		$taxonomy = sanitize_key($taxonomy);
		$args['labels'] = $args['labels'] ?? [];

		// TODO use inflector for nice name & pluralize
		$args['labels']['singular_name'] = $args['labels']['singular_name'] ?? ucwords(str_replace(['-', '_'], ' ', $taxonomy));
		$args['labels']['name'] = $args['labels']['name'] ?? $args['labels']['singular_name'] . 's';

		$labels = wp_parse_args($args['labels'], [
			'name'				=>	$args['labels']['name'],
			'singular_name'		=>	$args['labels']['singular_name'],
			'search_items'		=>	sprintf(__('Search %s', 'layered'), $args['labels']['name']),
			'all_items'			=>	sprintf(__('All %s', 'layered'), $args['labels']['name']),
			'parent_item'		=>	sprintf(__('Parent %s', 'layered'), $args['labels']['singular_name']),
			'parent_item_colon'	=>	sprintf(__('Parent %s:', 'layered'), $args['labels']['singular_name']),
			'edit_item'			=>	sprintf(__('Edit %s', 'layered'),  $args['labels']['singular_name']),
			'update_item'		=>	sprintf(__('Update %s', 'layered'), $args['labels']['singular_name']),
			'add_new_item'		=>	sprintf(__('Add New %s', 'layered'), $args['labels']['singular_name']),
			'new_item_name'		=>	sprintf(__('New %s Name', 'layered'), $args['labels']['singular_name'])
		]);

		$args = wp_parse_args($args, [
			'public'			=>	$this->args['public'],	// Inherited from Post Type
			'hierarchical'		=>	true,
			'show_in_rest'		=>	true
		]);
		$args['labels'] = $labels;

		$taxonomy = $this->postType . '-' . $taxonomy;
		$this->taxonomies[$taxonomy] = $args;

		register_taxonomy($taxonomy, $this->postType, $args);
		$this->addColumns($taxonomy);

		return $this;
	}

	public function addThumbnails(array $sizes): self {
		add_theme_support('post-thumbnails');

		foreach ($sizes as $size => $options) {
			add_image_size($size, $options[0], isset($options[1]) ? $options[1] : 0, isset($options[2]) && $options[2] == true);
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
            'name'  =>  __('Author', 'layered')
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
              printf('<i>%s</i>', __('undefined value', 'layered'));
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

	function addMetaFields(array $metaFields): self {

		foreach ($metaFields as $metaKey => $metaField) {
			$this->addMetaField($metaKey, $metaField);
		}

		return $this;
	}

	function addMetaField(string $metaKey, array $args): self {

		MetaFields::instance()->addPostMeta($this->postType, $metaKey, $args);

		return $this;
	}

}
