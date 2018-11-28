<?php
namespace Layered\Wp;

use WP_User;

final class MetaFields {

	protected static $_instance = null;

	protected $fields = [];
	protected $metaFields = [
		'post'	=>	[],
		'term'	=>	[],
		'user'	=>	[]
	];

	/**
	 * Main MetaFields Instance. Ensures only one instance of MetaFields can be loaded.
	 */
	public static function instance(): self {
		if (is_null(self::$_instance)) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Cloning is forbidden.
	 */
	public function __clone() {
		_doing_it_wrong(__FUNCTION__, __('Cloning is forbidden.', 'layered'), null);
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 */
	public function __wakeup() {
		_doing_it_wrong(__FUNCTION__, __('Unserializing instances of this class is forbidden.', 'layered'), null);
	}

	protected function __construct() {
		global $pagenow;

		// register meta field types
		$this->fields = array_map([$this, 'prepareFieldTypes'], apply_filters('meta_field_types', []));

		if (in_array($pagenow, ['term.php', 'edit-tags.php', 'user-edit.php'])) {
			wp_enqueue_media();
		}

		// load assets
		add_action('admin_head', [$this, 'adminHeadAssets']);
		add_action('admin_footer', [$this, 'adminFooterAssets']);

		// handle post types
		add_action('add_meta_boxes', [$this, 'addPostMetaBoxes'], 100, 2);
		add_action('save_post', [$this, 'savePostMetaFields'], 100, 2);
		add_action('init', [$this, 'savePostBulkEdit'], 100, 3);

		// handle taxonomies
		add_action('edited_term', [$this, 'saveTaxonomyMetaFields'], 100, 3);
		add_action('created_term', [$this, 'saveTaxonomyMetaFields'], 100, 3);

		// handle user
		add_action('show_user_profile', [$this, 'addUserMetaBoxes']);
		add_action('edit_user_profile', [$this, 'addUserMetaBoxes']);
		add_action('personal_options_update', [$this, 'saveUserMetaFields']);
		add_action('edit_user_profile_update', [$this, 'saveUserMetaFields']);

	}


	public function prepareFieldTypes(array $field): array {

		$field = wp_parse_args($field, [
			'name'				=>	'Field',
			'type'				=>	'string',
			'sanitize_callback'	=>	null,
			'renderValue'		=>	function($obj) {
				return $obj;
			},
			'renderReadable'	=>	function($obj) {
				return $obj;
			},
			'renderEditableField'		=>	[MetaFields::class, 'notEditable'],
			'renderEditableFieldBulk'	=>	null,
			'prepareMetaArgs'			=>	null
		]);

		return $field;
	}


	/* 1. Register meta fields */

	protected function prepareMetaArgs(string $metaKey, array $args = []): array {
		$args['type'] = $args['type'] ?? 'text';

		if (!isset($args['name']) || !strlen($args['name'])) {
			_doing_it_wrong(__FUNCTION__, sprintf(__('Field "%s" is required when registering a custom meta field', 'layered'), $args['name']), null);
		}

		if (!isset($this->fields[$args['type']])) {
			_doing_it_wrong(__FUNCTION__, sprintf(__('Field type "%s" is not available as meta field', 'layered'), $args['type']), null);
		}

		$args = wp_parse_args($args, [
			'type'				=>	'text',
			'description'		=>	'',
			'group'				=>	__('Meta Fields', 'layered'),
			'single'			=>	true,
			'sanitize_callback'	=>	$this->fields[$args['type']]['sanitize_callback'],
			'defaultValue'		=>	null,
			'initialValue'		=>	null,
			'value'				=>	'',
			'placeholder'		=>	'',
			'prefix'			=>	'',
			'suffix'			=>	'',
			'show_in_rest'		=>	true,
			'inputName'			=>	$metaKey,
			'class'				=>	'',
			'showInMetaBox'		=>	true,
			'showInColumns'		=>	false,
			'columnContent'		=>	null,
			'showInQuickEdit'	=>	false,
			'showInBulkEdit'	=>	false
		]);

		$args['advancedType'] = $args['type'];
		$args['type'] = $this->fields[$args['type']]['type'];

		if (!$args['single']) {
			$args['inputName'] .= '[]';
		}

		if (is_callable($this->fields[$args['advancedType']]['prepareMetaArgs'])) {
			$args = call_user_func_array($this->fields[$args['advancedType']]['prepareMetaArgs'], [$args, $metaKey]);
		}

		return apply_filters('meta_fields_args', $args, $metaKey);
	}

	public function addPostMeta(string $postType, string $metaKey, array $args = []) {
		$args = $this->prepareMetaArgs($metaKey, $args);
		register_post_meta($postType, $metaKey, $args);

		if (!isset($this->metaFields['post'][$postType])) {
			$this->metaFields['post'][$postType] = [];
			add_filter('manage_edit-' . $postType . '_columns', [$this, 'addColumns']);
			add_filter('manage_' . $postType . '_posts_custom_column', [$this, 'addColumnContent'], 10, 2);
			add_action('bulk_edit_custom_box', [$this, 'addBulkEditFields'], 10, 2);
		}

		$this->metaFields['post'][$postType][$metaKey] = $args;
	}

	public function addTermMeta(string $taxonomy, string $metaKey, array $args = []) {
		$args = $this->prepareMetaArgs($metaKey, $args);
		register_term_meta($taxonomy, $metaKey, $args);

		if (!isset($this->metaFields['term'][$taxonomy])) {
			$this->metaFields['term'][$taxonomy] = [];
			add_action($taxonomy . '_edit_form_fields', [$this, 'displayTermEditMeta'], 100, 2);
			add_action($taxonomy . '_add_form_fields', [$this, 'displayTermAddMeta'], 100, 1);
			add_filter('manage_edit-' . $taxonomy . '_columns', [$this, 'addColumns']);
			add_filter('manage_' . $taxonomy . '_custom_column', [$this, 'addColumnContent'], 10, 3);
		}

		$this->metaFields['term'][$taxonomy][$metaKey] = $args;
	}

	public function addUserMeta(string $metaKey, array $args = []) {
		$args = $this->prepareMetaArgs($metaKey, $args);
		register_meta('user', $metaKey, $args);

		if (empty($this->metaFields['user'])) {
			add_filter('manage_users_columns', [$this, 'addColumns']);
			add_filter('manage_users_custom_column', [$this, 'addColumnContent'], 10, 3);
		}

		$this->metaFields['user'][$metaKey] = $args;
	}



	/* 2. Display columns */

	public function addColumns(array $columns): array {
		$screen = get_current_screen();

		if ($screen->base === 'edit-tags') {
			$metaFields = $this->metaFields['term'][$screen->taxonomy] ?? [];
		} elseif ($screen->base === 'users') {
			$metaFields = $this->metaFields['user'] ?? [];
		} else {
			$metaFields = $this->metaFields['post'][$screen->post_type] ?? [];
		}

		foreach ($metaFields as $metaKey => $metaField) {
			if ($metaField['showInColumns'] !== false) {
				$position = is_int($metaField['showInColumns']) ? $metaField['showInColumns'] : -1;
				$newCol = [];
				$newCol[$metaKey] = $metaField['name'];

				$columns = array_merge(array_slice($columns, 0, $position), $newCol, array_slice($columns, $position));
			}
		}

		return $columns;
	}

	public function addColumnContent($content, $columnName, $objId = null): string {
		$screen = get_current_screen();

		if ($screen->base === 'edit-tags') {
			$metaFields = $this->metaFields['term'][$screen->taxonomy] ?? [];
			$metaType = 'term';
		} elseif ($screen->base === 'users') {
			$metaFields = $this->metaFields['user'] ?? [];
			$metaType = 'user';
		} elseif ($screen->base === 'edit') {
			$metaFields = $this->metaFields['post'][$screen->post_type] ?? [];
			$metaType = 'post';
			$objId = $columnName;
			$columnName = $content;
			$content = '';
		} else {
			return '';
		}

		foreach ($metaFields as $metaKey => $metaField) {
			if ($metaField['showInColumns'] && $metaKey === $columnName) {
				$metaValue = $this->getMeta($metaType, $metaField, $objId, $metaKey);

				if (is_callable($metaField['columnContent'])) {
					$content = call_user_func_array($metaField['columnContent'], [$metaValue, $metaKey, $metaField]);
				} else {
					$content = call_user_func_array($this->fields[$metaField['advancedType']]['renderReadable'], [$metaValue, $metaKey, $metaField]);
					$content = $metaField['prefix'] . $content . $metaField['suffix'];
				}
			}
		}

		if ($metaType == 'post') {
			echo $content;
		}

		return (string) $content;
	}



	/* 3. Add meta boxes */

	public function addPostMetaBoxes(string $postType, $post) {
		if ($postType === 'comment') {
			$metaFields = $this->metaFields['comment'] ?? [];
		} else {
			$metaFields = $this->metaFields['post'][$postType] ?? [];
		}
		$metaFieldsByGroup = [];

		foreach ($metaFields as $metaKey => $metaField) {
			if ($metaField['showInMetaBox']) {
				if (!isset($metaFieldsByGroup[$metaField['group']])) {
					$metaFieldsByGroup[$metaField['group']] = [];
				}

				$metaFieldsByGroup[$metaField['group']][$metaKey] = $metaField;
			}
		}

		foreach ($metaFieldsByGroup as $groupName => $metaFields) {
			add_meta_box(sanitize_title($groupName), $groupName, [$this, 'displayPostMetaBox'], $postType, $context = 'advanced', $priority = 'default', $metaFields);
		}
	}

	public function displayPostMetaBox(\WP_Post $post, array $args) {
		$metaFields = $args['args'];
		wp_nonce_field('layeredPostMetaBoxes', 'layeredPostMetaBoxesNonce');
		?>

		<table class="form-table layered-meta-table">
			<tbody>

				<?php
				foreach ($metaFields as $metaKey => $field) {
					$field['value'] = get_post_meta($post->ID, $metaKey, $field['single']);
					?>

					<tr class="field-<?php echo esc_attr($metaKey) ?> field-type-<?php echo esc_attr($field['advancedType']) ?> field-type-<?php echo esc_attr($field['single'] ? 'single' : 'multiple') ?>">
						<th scope="row">
							<label for="<?php echo esc_attr($metaKey) ?>"><?php echo $field['name'] ?></label>
						</th>
						<td>

							<?php
							if (!$field['single'] && $field['value'] && is_array($field['value'])) {
								foreach ($field['value'] as $val) {
									$this->renderField($metaKey, array_merge($field, ['value' => $val]));
								}
							}
							?>

							<?php $this->renderField($metaKey, $field) ?>

							<?php if (!$field['single']) : ?>

								<div class="clear">
									<button class="button button-primary button-small js-layered-clone-field"><?php printf(__('Add %s'), $field['name']) ?></button>
								</div>

							<?php endif ?>

						</td>
					</tr>

					<?php
				}
				?>
			</tbody>
		</table>
		<?php
	}

	public function displayTermEditMeta(\WP_Term $term, string $taxonomy) {
		$metaFields = $this->metaFields['term'][$taxonomy] ?? [];
		$metaFields = array_filter($metaFields, function($metaField) {
			return $metaField['showInMetaBox'];
		});

		if (!count($metaFields)) {
			return;
		}

		wp_nonce_field('layeredTermMetaBoxes', 'layeredTermMetaBoxesNonce');

		foreach ($metaFields as $metaKey => $metaField) :
			$metaField['value'] = get_term_meta($term->term_id, $metaKey, $metaField['single']);
			?>
			<tr class="form-field term-slug-wrap">
				<th scope="row">
					<label for="<?php echo esc_attr($metaKey) ?>"><?php echo $metaField['name'] ?></label>
				</th>
				<td>
					<?php
					if (!$metaField['single'] && $metaField['value'] && is_array($metaField['value'])) {
						foreach ($metaField['value'] as $val) {
							$this->renderField($metaKey, array_merge($metaField, ['value' => $val]));
						}
					}
					?>

					<?php $this->renderField($metaKey, $metaField) ?>

					<?php if (!$metaField['single']) : ?>

						<div class="clear">
							<button class="button button-primary button-small js-layered-clone-field"><?php printf(__('Add %s'), $metaField['name']) ?></button>
						</div>

					<?php endif ?>
				</td>
			</tr>
			<?php
		endforeach;
	}

	public function displayTermAddMeta(string $taxonomy) {
		$metaFields = $this->metaFields['term'][$taxonomy] ?? [];
		$metaFields = array_filter($metaFields, function($metaField) {
			return $metaField['showInMetaBox'];
		});

		if (!count($metaFields)) {
			return;
		}

		wp_nonce_field('layeredTermMetaBoxes', 'layeredTermMetaBoxesNonce');

		foreach ($metaFields as $metaKey => $metaField) :
			?>
			<div class="form-field term-<?php echo esc_attr($metaKey) ?>-wrap">
				<label for="tag-<?php echo esc_attr($metaKey) ?>"><?php echo $metaField['name'] ?></label>
				
				<?php $this->renderField($metaKey, $metaField); ?>

				<?php if (!$metaField['single']) : ?>
					<div class="clear">
						<button class="button button-primary button-small js-layered-clone-field"><?php printf(__('Add %s'), $metaField['name']) ?></button>
					</div>
				<?php endif ?>
			</div>
			<?php
		endforeach;
		?>
		<?php
	}

	public function addBulkEditFields(string $columnName, string $postType) {
		$metaFields = $this->metaFields['post'][$postType] ?? [];

		foreach ($metaFields as $metaKey => $metaField) {
			if ($metaField['showInBulkEdit'] && $metaKey === $columnName && $this->fields[$metaField['advancedType']]['renderEditableFieldBulk']) {
				?>
				<fieldset class="inline-edit-col-right">
					<div class="inline-edit-col">
						<div class="inline-edit-group wp-clearfix">
							<label class="inline-edit-que alignleft">
								<span class="title"><?php echo $metaField['name'] ?></span>
								<?php
								call_user_func_array($this->fields[$metaField['advancedType']]['renderEditableFieldBulk'], [$metaField, $metaKey]);
								?>
							</label>
						</div>
					</div>
				</fieldset>
				<?php
			}
		}
	}

	public function addUserMetaBoxes(WP_User $user) {
		$metaFields = $this->metaFields['user'];
		$metaFieldsByGroup = [];

		wp_nonce_field('layeredUserMetaBoxes', 'layeredUserMetaBoxesNonce');

		foreach ($metaFields as $metaKey => $metaField) {
			if ($metaField['showInMetaBox']) {
				if (!isset($metaFieldsByGroup[$metaField['group']])) {
					$metaFieldsByGroup[$metaField['group']] = [];
				}

				$metaFieldsByGroup[$metaField['group']][$metaKey] = $metaField;
			}
		}

		foreach ($metaFieldsByGroup as $groupName => $metaFields) {
			?>
			<h2><?php echo $groupName ?></h2>

			<table class="form-table layered-meta-table">
				<?php foreach ($metaFields as $metaKey => $metaField) :
					$metaField['value'] = get_user_meta($user->ID, $metaKey, $metaField['single']);
					?>

					<tr class="field-<?php echo esc_attr($metaKey) ?> field-type-<?php echo esc_attr($metaField['advancedType']) ?> field-type-<?php echo esc_attr($metaField['single'] ? 'single' : 'multiple') ?>">
						<th scope="row">
							<label for="<?php echo esc_attr($metaKey) ?>"><?php echo $metaField['name'] ?></label>
						</th>
						<td>
							<?php
							if (!$metaField['single'] && $metaField['value'] && is_array($metaField['value'])) {
								foreach ($metaField['value'] as $val) {
									$this->renderField($metaKey, array_merge($metaField, ['value' => $val]));
								}
							}

							$this->renderField($metaKey, $metaField)
							?>

							<?php if (!$metaField['single']) : ?>
								<div class="clear">
									<button class="button button-primary button-small js-layered-clone-field"><?php printf(__('Add %s'), $metaField['name']) ?></button>
								</div>
							<?php endif ?>
						</td>
					</tr>
				<?php endforeach ?>
			</table>
			<?php
		}

	}



	/* 4. Render fields */

	protected function renderField(string $metaKey, array $metaField) {
		if (is_array($metaField['value'])) {
			$metaField['value'] = '';
		}
		?>

		<fieldset>
			<legend class="screen-reader-text"><span><?php echo $metaField['name'] ?></span></legend>

			<?php
			call_user_func_array($this->fields[$metaField['advancedType']]['renderEditableField'], [$metaField, $metaKey]);
			?>

			<?php if (!$metaField['single']) : ?>
				<button class="button button-small btn-multiple-remove js-multiple-remove">-</button>
			<?php endif ?>


			<?php if ($metaField['description']) : ?>
				<p class="description"><?php echo $metaField['description'] ?></p>
			<?php endif ?>

		</fieldset>

		<?php
	}

	public static function notEditable(array $metaField, string $metaKey) {
		?>
		<input id="<?php echo $metaKey ?>" type="text" placeholder="<?php echo esc_attr_e('Field not editable', 'layered') ?>" disabled />
		<?php
	}

	public static function editableTextField(array $metaField, string $metaKey) {
		echo $metaField['prefix'];
		?>
		<input id="<?php echo $metaKey ?>" type="<?php echo $metaField['advancedType'] ?>" name="<?php echo $metaField['inputName'] ?>" placeholder="<?php echo $metaField['placeholder'] ?>" value="<?php echo $metaField['value'] ?>" class="<?php echo $metaField['class'] ?>" />
		<?php
		echo $metaField['suffix'];
	}

	public static function editableCheckboxField(array $metaField, string $metaKey) {
		foreach ($metaField['options'] as $key => $label) : ?>
			<label>
				<input type="<?php echo $metaField['advancedType'] ?>" id="<?php echo $metaKey ?>" name="<?php echo $metaField['inputName'] ?>" value="<?php echo $key ?>" <?php checked($key, $metaField['value']) ?> />
				<?php echo $label ?>
			</label>
			<br>
		<?php endforeach;
	}

	public static function editableSelectField(array $metaField, string $metaKey) {
		?>
		<select id="<?php echo $metaKey ?>" name="<?php echo $metaField['inputName'] ?>" class="<?php echo $metaField['class'] ?>">
			<?php foreach($metaField['options'] as $optionKey => $optionValue) : ?>
				<option <?php selected($optionKey, $metaField['value']) ?> value="<?php echo $optionKey ?>"><?php echo $optionValue ?></option>
			<?php endforeach ?>
		</select>
		<?php
	}

	public static function bulkEditableTextField(array $metaField, string $metaKey) {
		echo $metaField['prefix'];
		?>
		<input id="<?php echo $metaKey ?>" type="<?php echo $metaField['advancedType'] ?>" name="_<?php echo $metaField['inputName'] ?>" placeholder="<?php echo __('No change') ?>" />
		<?php
		echo $metaField['suffix'];
	}

	public static function bulkEditableSelectField(array $metaField, string $metaKey) {
		$metaField['options'] = [
			-1	=>	__('— No Change —', 'layered')
		] + $metaField['options'];
		?>
		<select id="<?php echo $metaKey ?>" name="_<?php echo $metaField['inputName'] ?>" class="<?php echo $metaField['class'] ?>">
			<?php foreach($metaField['options'] as $optionKey => $optionValue) : ?>
				<option value="<?php echo $optionKey ?>"><?php echo $optionValue ?></option>
			<?php endforeach ?>
		</select>
		<?php
	}



	/* 5. Save meta data */

	public function savePostMetaFields(int $postId, \WP_Post $post) {
		if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !isset($_REQUEST['layeredPostMetaBoxesNonce']) || !wp_verify_nonce($_REQUEST['layeredPostMetaBoxesNonce'], 'layeredPostMetaBoxes')) return;

		$metaFields = $this->metaFields['post'][$post->post_type] ?? [];

		foreach ($metaFields as $metaKey => $metaField) {
			if (isset($_POST[$metaKey]) && $metaField['showInMetaBox']) {
				if (!$metaField['single']) {
					delete_post_meta($post->ID, $metaKey);

					foreach ($_POST[$metaKey] as $i => $value) {
						if ($metaField['sanitize_callback']) {
							$value = call_user_func($metaField['sanitize_callback'], $value);
						}
						if (strlen($value)) {
							add_post_meta($post->ID, $metaKey, $value);
						}
					}
				} else {
					$value = $_POST[$metaKey];
					if ($metaField['sanitize_callback']) {
						$value = call_user_func($metaField['sanitize_callback'], $value);
					}
					update_post_meta($post->ID, $metaKey, $value);
				}
			} else {
				delete_post_meta($post->ID, $metaKey);
			}
		}
	}

	public function savePostBulkEdit() {

		if (isset($_REQUEST['bulk_edit']) && $_REQUEST['post_type'] && $_REQUEST['action'] == 'edit') {
			$metaFields = $this->metaFields['post'][$_REQUEST['post_type']] ?? [];

			foreach ($metaFields as $metaKey => $metaField) {
				if ($metaField['showInBulkEdit'] && isset($_REQUEST['_' . $metaKey]) && strlen($_REQUEST['_' . $metaKey]) && $_REQUEST['_' . $metaKey] != -1) {
					foreach ($_REQUEST['post'] as $postId) {
						$value = $_REQUEST['_' . $metaKey];
						if ($metaField['sanitize_callback']) {
							$value = call_user_func($metaField['sanitize_callback'], $value);
						}
						update_post_meta($postId, $metaKey, $value);
					}
				}
			}
		}

	}

	public function saveTaxonomyMetaFields(int $termId, int $termTaxonomyId, string $taxonomy) {
		if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !isset($_REQUEST['layeredTermMetaBoxesNonce']) || !wp_verify_nonce($_REQUEST['layeredTermMetaBoxesNonce'], 'layeredTermMetaBoxes')) return;

		$metaFields = $this->metaFields['term'][$taxonomy] ?? [];

		foreach ($metaFields as $metaKey => $metaField) {
			if (isset($_POST[$metaKey]) && $metaField['showInMetaBox']) {
				if (!$metaField['single']) {
					delete_term_meta($termId, $metaKey);

					foreach ($_POST[$metaKey] as $i => $value) {
						if ($metaField['sanitize_callback']) {
							$value = call_user_func($metaField['sanitize_callback'], $value);
						}
						if (strlen($value)) {
							add_term_meta($termId, $metaKey, $value);
						}
					}
				} else {
					$value = $_POST[$metaKey];
					if ($metaField['sanitize_callback']) {
						$value = call_user_func($metaField['sanitize_callback'], $value);
					}
					update_term_meta($termId, $metaKey, $value);
				}
			} else {
				delete_term_meta($termId, $metaKey);
			}
		}
	}

	public function saveUserMetaFields(int $userId) {
		if (!current_user_can('edit_user', $userId) || !isset($_REQUEST['layeredUserMetaBoxesNonce']) || !wp_verify_nonce($_REQUEST['layeredUserMetaBoxesNonce'], 'layeredUserMetaBoxes')) return;

		$metaFields = $this->metaFields['user'] ?? [];

		foreach ($metaFields as $metaKey => $metaField) {
			if ($metaField['showInMetaBox'] && isset($_POST[$metaKey])) {
				if ($metaField['single']) {
					$value = $_POST[$metaKey];
					if ($metaField['sanitize_callback']) {
						$value = call_user_func($metaField['sanitize_callback'], $value);
					}
					update_user_meta($userId, $metaKey, $value);
				} else {
					delete_user_meta($userId, $metaKey);

					foreach ($_POST[$metaKey] as $i => $value) {
						if ($metaField['sanitize_callback']) {
							$value = call_user_func($metaField['sanitize_callback'], $value);
						}
						if (strlen($value)) {
							add_user_meta($userId, $metaKey, $value);
						}
					}
				}
			} else {
				delete_user_meta($userId, $metaKey);
			}
		}
	}



	/* 6. Get meta data */

	public function getMeta(string $metaType, array $metaField, int $id, string $metaKey) {
		$metaValue = get_metadata($metaType, $id, $metaKey, $metaField['single']);

		if ($metaField['single']) {
			$metaValue = call_user_func_array($this->fields[$metaField['advancedType']]['renderValue'], [$metaValue, $metaField]);
		} else {
			$metaValue = array_map(function($value) use($metaField) {
				return call_user_func_array($this->fields[$metaField['advancedType']]['renderValue'], [$value, $metaField]);
			}, $metaValue);
		}

		return $metaValue;
	}

	public function getPostMeta($post, string $metaKey) {
		$post = get_post($post);
		$metaField = $this->metaFields['post'][$post->post_type][$metaKey];

		return $this->getMeta('post', $metaField, $post->ID, $metaKey);
	}

	public function getTermMeta($term, string $metaKey) {
		$term = get_term($term);
		$metaField = $this->metaFields['term'][$term->taxonomy][$metaKey];

		return $this->getMeta('term', $metaField, $term->term_id, $metaKey);
	}

	public function getUserMeta($user, string $metaKey) {
		$userId = $user instanceof WP_User ? $user->ID : $user;
		$metaField = $this->metaFields['user'][$metaKey];

		return $this->getMeta('user', $metaField, (int) $userId, $metaKey);
	}


	public function adminHeadAssets() {
		?>
		<style type="text/css">
		.layered-meta-table tr:not(:last-child) {
			border-bottom: 1px solid #f0f0f0;
		}
		.field-type-text.field-type-multiple fieldset {
			position: relative;
			margin: 0.5rem 0;
			display: inline-block;
			padding: 0.3rem 0.5rem;
			border-radius: 3px;
		}
		.field-type-attachment fieldset {
			display: inline-block;
			position: relative;
			margin: 0.5rem 0.5rem 0.5rem 0;
			text-align: center;
			border: 1px solid #ccc;
			padding: 3px;
			border-radius: 3px;
		}
		.field-type-attachment fieldset p {
			margin: 0;
		}
		.btn-multiple-remove {
			position: absolute;
			top: -10px;
			right: -10px;
			border-radius: 50% !important;
			border-color: #ffbebe !important;
		}
		</style>
		<?php
	}

	public function adminFooterAssets() {
		?>
		<script type="text/javascript">
		jQuery(function($) {
			var media,
				lastField;

			$('.js-layered-open-media').click(function(e) {
				e.preventDefault();
				var $el = $(this);
				lastField = $el.closest('fieldset');

				if (!media) {
					media = wp.media({
						title: 'Select or Upload Image',
						type: 'image',
						button: {
							text: 'Use this image'
						},
						multiple: false
					});

					media.on('select', function() {
						var attachment = media.state().get('selection').first().toJSON();
						lastField.find('input').val(attachment.id);
						lastField.find('.caption').html(attachment.caption || '<i>No caption..</i>');
						lastField.find('img').data('attachment-id', attachment.id).attr('src', attachment.type == 'image' ? attachment.url : 'http://placehold.jp/ededed/9e9e9e/100x100.jpg?text=' + attachment.mime);

					});
				}

				media.on('open', function() {
					var selection = media.state().get('selection');
					selection.reset($el.data('attachment-id') ? [wp.media.attachment($el.data('attachment-id'))] : []);
				});

				media.open();
			});


			$('.js-layered-clone-field').click(function(e) {
				e.preventDefault();

				var field = $(this).closest('td').find('fieldset:last');
				var clonedField = field.clone(true).insertAfter(field);

				clonedField.find('input').val('');
				clonedField.find('img').data('attachment-id', null).attr('src', 'http://placehold.jp/ededed/9e9e9e/100x100.jpg?text=%2B');
			});


			$('.js-multiple-remove').click(function(e) {
				e.preventDefault();
				var fieldset = $(this).closest('fieldset');

				if (!fieldset.find('input').val().length || confirm('Are you sure?')) {
					fieldset.remove();
				}
			});

		});
		</script>
		<?php
	}

}



add_filter('meta_field_types', function(array $fields): array {

	// WP types
	// 'string', 'boolean', 'integer', 'number'

	// Basic fields

	$fields['text'] = [
		'name'						=>	__('Text', 'layered'),
		'type'						=>	'string',
		'sanitize_callback'			=>	'sanitize_text_field',
		'renderEditableField'		=>	[MetaFields::class, 'editableTextField'],
		'renderEditableFieldBulk'	=>	[MetaFields::class, 'bulkEditableTextField']
	];

	$fields['number'] = [
		'name'						=>	__('Number', 'layered'),
		'type'						=>	'number',
		'renderEditableField'		=>	[MetaFields::class, 'editableTextField'],
		'renderEditableFieldBulk'	=>	[MetaFields::class, 'bulkEditableTextField']
	];

	$fields['url'] = [
		'name'						=>	__('URL', 'layered'),
		'type'						=>	'string',
		'sanitize_callback'			=>	'esc_url_raw',
		'renderEditableField'		=>	[MetaFields::class, 'editableTextField'],
		'renderEditableFieldBulk'	=>	[MetaFields::class, 'bulkEditableTextField']
	];

	$fields['checkbox'] = [
		'name'					=>	__('Checkbox', 'layered'),
		'type'					=>	'boolean',
		'renderValue'			=>	'wp_validate_boolean',
		'renderReadable'		=>	function($metaValue) {
			return $metaValue ? __('Yes', 'layered') : __('No', 'layered');
		},
		'renderEditableField'	=>	[MetaFields::class, 'editableCheckboxField'],
		'prepareMetaArgs'		=>	function(array $args, string $metaKey): array {
			$args['options'] = [
				1	=>	$args['placeholder'] ?: $args['name']
			];

			return $args;
		}
	];

	$fields['radio'] = [
		'name'					=>	__('Radio', 'layered'),
		'type'					=>	'string',
		'renderValue'			=>	function($metaValue): bool {
			return $metaField['options'][$metaValue] ?? null;
		},
		'renderEditableField'	=>	[MetaFields::class, 'editableCheckboxField']
	];

	$fields['select'] = [
		'name'						=>	__('Select', 'layered'),
		'type'						=>	'string',
		'renderValue'				=>	function($metaValue, $metaField) {
			return $metaField['options'][$metaValue] ?? null;
		},
		'renderEditableField'		=>	[MetaFields::class, 'editableSelectField'],
		'renderEditableFieldBulk'	=>	[MetaFields::class, 'bulkEditableSelectField']
	];


	// Complex fields

	$fields['post'] = [
		'name'						=>	__('Post', 'layered'),
		'type'						=>	'integer',
		'renderValue'				=>	function($metaValue) {
			return $metaValue ? get_post($metaValue) : null;
		},
		'renderReadable'			=>	function($metaValue) {
			return $metaValue ? $metaValue->post_title : '';
		},
		'renderEditableField'		=>	[MetaFields::class, 'editableSelectField'],
		'renderEditableFieldBulk'	=>	[MetaFields::class, 'bulkEditableSelectField'],
		'prepareMetaArgs'			=>	function(array $args, string $metaKey): array {
			$posts = get_posts($args['postArgs'] ?? []);
			$args['options'] = [
				''	=>	$args['placeholder'] ?: __('— Select —', 'layered')
			];

			foreach ($posts as $post) {
				$args['options'][$post->ID] = $post->post_title;
			}

			return $args;
		}
	];

	$fields['term'] = [
		'name'						=>	__('Term', 'layered'),
		'type'						=>	'integer',
		'renderValue'				=>	function($metaValue) {
			return $metaValue ? get_post($metaValue) : null;
		},
		'renderReadable'			=>	function($metaValue) {
			return $metaValue ? $metaValue->post_title : '';
		},
		'renderEditableField'		=>	[MetaFields::class, 'editableSelectField'],
		'renderEditableFieldBulk'	=>	[MetaFields::class, 'bulkEditableSelectField'],
		'prepareMetaArgs'			=>	function(array $args, string $metaKey): array {
			$terms = get_terms($args['termArgs'] ?? []);
			$args['options'] = [
				''	=>	__('— Select —', 'layered')
			];

			foreach ($terms as $term) {
				$args['options'][$term->term_id] = $term->name;
			}

			return $args;
		}
	];

	$fields['user'] = [
		'name'						=>	__('Useer', 'layered'),
		'type'						=>	'integer',
		'renderValue'				=>	function($metaValue) {
			return $metaValue ? get_user_by('id', $metaValue) : null;
		},
		'renderReadable'			=>	function($metaValue) {
			return $metaValue ? $metaValue->display_name : '';
		},
		'renderEditableField'		=>	[MetaFields::class, 'editableSelectField'],
		'renderEditableFieldBulk'	=>	[MetaFields::class, 'bulkEditableSelectField'],
		'prepareMetaArgs'			=>	function(array $args, string $metaKey): array {
			$users = get_users($args['userArgs'] ?? []);
			$args['options'] = [
				''	=>	$args['placeholder'] ?: __('— Select —', 'layered')
			];

			foreach ($users as $user) {
				$args['options'][$user->ID] = $user->display_name;
			}

			return $args;
		}
	];

	$fields['attachment'] = [
		'name'				=>	__('Attachment', 'layered'),
		'type'				=>	'integer',
		'renderValue'		=>	function($metaValue) {
			return $metaValue ? get_post($metaValue) : null;
		},
		'renderReadable'	=>	function($metaValue) {
			return $metaValue ? wp_get_attachment_image($metaValue->ID, [50, 50], strpos($metaValue->post_mime_type, 'image') === false, ['class' => 'attachment-preview']) : '';
		},
		'renderEditableField'	=>	function(array $metaField, string $metaKey) {
			$caption = '<small><i>No caption</i></small>';

			if ($metaField['value']) {
				$attachment = get_post($metaField['value']);

				if ($attachment) {
					$caption = $attachment->post_excerpt ?: $caption;
					$thumb = wp_get_attachment_image_src($attachment->ID, [100, 100], strpos($attachment->post_mime_type, 'image') === false)[0];
				} else {
					$thumb = 'http://placehold.jp/ededed/9e9e9e/100x100.jpg?text=Missing Media';
				}
			} else {
				$thumb = 'http://placehold.jp/ededed/9e9e9e/100x100.jpg?text=%2B';
				$caption = '&nbsp;';
			}
			?>

			<img id="<?php echo $metaKey ?>" <?php if ($metaField['value']) echo 'data-attachment-id="' . $metaField['value'] . '"' ?> class="js-layered-open-media attachment-preview" src="<?php echo $thumb ?>" height="100" alt="Select" />
			<p class="caption"><?php echo $caption ?></p>

			<!--<button class="js-layered-open-media button button-small">Choose media</button>-->
			<input type="hidden" name="<?php echo $metaField['inputName'] ?>" value="<?php echo $metaField['value'] ?>" />
			<?php
		}
	];

	$fields['editor'] = [
		'name'					=>	__('Editor', 'layered'),
		'type'					=>	'string',
		'renderEditableField'	=>	function(array $metaField, string $metaKey) {
			wp_editor($metaField['value'], $metaKey, ['textarea_name' => $metaField['inputName'], 'media_buttons' => false, 'textarea_rows' => 10, 'teeny' => true]);
		}
	];

	$fields['json'] = [
		'name'				=>	__('JSON', 'layered'),
		'type'				=>	'string',
		'renderValue'		=>	function($metaValue) {
			return $metaValue ? json_decode($metaValue, true) : null;
		},
		'renderReadable'	=>	function($metaValue) {
			return $metaValue ? json_encode($metaValue, JSON_PRETTY_PRINT) : '';
		},
		'renderEditableField'	=>	function(array $metaField, string $metaKey) {
			?>
			<textarea id="<?php echo $metaKey ?>" name="<?php echo $metaField['inputName'] ?>" rows="7" cols="40" placeholder="<?php echo $metaField['placeholder'] ?>" class="large-text code"><?php echo $metaField['value'] ?></textarea>
			<?php
		}
	];

	return $fields;
});
