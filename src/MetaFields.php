<?php
namespace Layered\Wp;

final class MetaFields {

	protected static $_instance = null;

	protected $metaFields = [
		'post'	=>	[],
		'term'	=>	[],
		'user'	=>	[]
	];

	/**
	 * Main MetaFields Instance. Ensures only one instance of MetaFields is loaded or can be loaded.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
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

	public function __construct() {

		// load assets
		add_action('admin_head', [$this, 'adminHeadAssets']);
		add_action('admin_footer', [$this, 'adminFooterAssets']);
		wp_enqueue_media();

		// handle post types
		add_action('add_meta_boxes', [$this, 'addMetaBoxes'], 100, 2);
		add_action('save_post', [$this, 'savePostMetaFields'], 100, 2);

		// handle taxonomies
		add_action('edited_term', [$this, 'saveTaxonomyMetaFields'], 100, 3);
		add_action('created_term', [$this, 'saveTaxonomyMetaFields'], 100, 3);

	}

	protected function prepareMetaArgs(string $metaKey, array $args = []): array {

		if (!isset($args['name']) || !strlen($args['name'])) {
			_doing_it_wrong(__FUNCTION__, sprintf(__('Field "%s" is required when registering a custom meta field', 'layered'), 'name'), null);
		}

		// In 4.7 one of 'string', 'boolean', 'integer', 'number' must be used as 'type'.
		$basicTypes = [
			'text'			=>	'string',
			'select'		=>	'string',
			'date'			=>	'string',
			'url'			=>	'string',
			'time'			=>	'string',
			'radio'			=>	'string',
			'custom'		=>	'string',
			'attachment'	=>	'integer',
			'posts'			=>	'integer',
			'checkbox'		=>	'boolean'
		];

		$args = wp_parse_args($args, [
			'type'				=>	'text',
			'description'		=>	'',
			'group'				=>	__('Meta Fields', 'layered'),
			'single'			=>	true,
			'defaultValue'		=>	null,
			'initialValue'		=>	null,
			'value'				=>	'',
			'placeholder'		=>	'',
			'prefix'			=>	'',
			'suffix'			=>	'',
			'show_in_rest'		=>	true,
			'input_name'		=>	$metaKey,
			'show_as_column'	=>	false
		]);

		$args['advancedType'] = $args['type'];
		if (isset($basicTypes[$args['type']])) {
			$args['type'] = $basicTypes[$args['type']];
		}

		if (!$args['single']) {
			$args['input_name'] .= '[]';
		}

		if ($args['advancedType'] === 'checkbox') {
			$args['options'] = [
				1	=>	$args['placeholder'] ?: $args['name']
			];
		}

		$args = apply_filters('meta_fields_args', $args, $metaKey);

		return $args;
	}

	public function postMeta(string $postType, string $metaKey, array $args = []) {
		$args = $this->prepareMetaArgs($metaKey, $args);
		register_post_meta($postType, $metaKey, $args);
		$this->metaFields['post'][$postType][$metaKey] = $args;
	}

	public function termMeta(string $taxonomy, string $metaKey, array $args = []) {
		$args = $this->prepareMetaArgs($metaKey, $args);
		register_term_meta($taxonomy, $metaKey, $args);

		if (!isset($this->metaFields['term'][$taxonomy])) {
			$this->metaFields['term'][$taxonomy] = [];
			add_action($taxonomy . '_edit_form_fields', [$this, 'displayTermEditMeta'], 100, 2);
			add_action($taxonomy . '_add_form_fields', [$this, 'displayTermAddMeta'], 100, 1);
			add_filter('manage_edit-' . $taxonomy . '_columns', [$this, 'addTermColumns']);
			add_filter('manage_' . $taxonomy . '_custom_column', [$this, 'addTermColumnContent'], 10, 3);
		}

		$this->metaFields['term'][$taxonomy][$metaKey] = $args;
	}

	public function userMeta(string $metaKey, array $args = []) {
		$args = $this->prepareMetaArgs($metaKey, $args);
		register_meta('user', $metaKey, $args);
		$this->metaFields['user'][$metaKey] = $args;
	}

	public function addTermColumns(array $columns): array {
		$taxonomy = get_current_screen()->taxonomy;
		$metaFields = $this->metaFields['term'][$taxonomy] ?? [];

		foreach ($metaFields as $metaKey => $metaField) {
			if ($metaField['show_as_column'] !== false) {
				$position = is_int($metaField['show_as_column']) ? $metaField['show_as_column'] : -1;
				$newCol = [];
				$newCol[$metaKey] = $metaField['name'];

				$columns = array_merge(array_slice($columns, 0, $position), $newCol, array_slice($columns, $position));
			}
		}

		return $columns;
	}

	public function addTermColumnContent($content, $columnName, $termId): string {
		$taxonomy = get_current_screen()->taxonomy;
		$metaFields = $this->metaFields['term'][$taxonomy] ?? [];

		foreach ($metaFields as $metaKey => $metaField) {
			if ($metaField['show_as_column'] && $metaKey === $columnName) {
				$content = $this->getTermMetaValue($termId, $columnName);
			}
		}

		return $content;
	}

	public function addMetaBoxes(string $postType, \WP_Post $post) {
		$metaFields = $this->metaFields['post'][$postType] ?? [];
		$metaFieldsByGroup = [];

		foreach ($metaFields as $metaKey => $metaField) {
			if (!isset($metaFieldsByGroup[$metaField['group']])) {
				$metaFieldsByGroup[$metaField['group']] = [];
			}

			$metaFieldsByGroup[$metaField['group']][$metaKey] = $metaField;
		}

		foreach ($metaFieldsByGroup as $groupName => $metaFields) {
			add_meta_box(sanitize_title($groupName), $groupName, [$this, 'displayPostMetaBox'], $postType, $context = 'advanced', $priority = 'default', $metaFields);
		}
	}

	public function displayPostMetaBox(\WP_Post $post, array $args) {
		$metaFields = $args['args'];
		$cf = get_post_meta($post->ID);

		wp_nonce_field('layered_meta_boxes', 'layered_meta_boxes_nonce');
		?>

		<table class="form-table layered-meta-table">
			<tbody>

				<?php
				foreach ($metaFields as $metaKey => $field) {
					$field['value'] = $cf[$metaKey] ?? null;
					if ($field['single'] && is_array($field['value'])) {
						$field['value'] = array_shift($field['value']);
					}
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
		$cf = get_term_meta($term->term_id);

		foreach ($metaFields as $metaKey => $metaField) :
			$metaField['value'] = $cf[$metaKey] ?? null;
			if ($metaField['single'] && is_array($metaField['value'])) {
				$metaField['value'] = array_shift($metaField['value']);
			}
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

	protected function renderField(string $metaKey, array $metaField) {
		if (is_array($metaField['value'])) {
			$metaField['value'] = '';
		}
		?>

		<fieldset>
			<legend class="screen-reader-text"><span><?php echo $metaField['name'] ?></span></legend>

			<?php if (in_array($metaField['advancedType'], ['text', 'number', 'date', 'url', 'time'])) : ?>

				<?php if (isset($metaField['prefix'])) echo $metaField['prefix'] ?>
				<input id="<?php echo $metaKey ?>" type="<?php echo $metaField['advancedType'] ?>" name="<?php echo $metaField['input_name'] ?>" placeholder="<?php echo $metaField['placeholder'] ?: '' ?>" value="<?php echo $metaField['value'] ?>" class="<?php echo isset($metaField['class']) ? $metaField['class'] : 'regular-text' ?>" />
				<?php if (isset($metaField['suffix'])) echo $metaField['suffix'] ?>

			<?php elseif($metaField['advancedType'] == 'select') : ?>

				<select id="<?php echo $metaKey ?>" name="<?php echo $metaField['input_name'] ?>" class="<?php echo isset($metaField['class']) ? $metaField['class'] : 'regular-text' ?>">
					<?php foreach( $metaField['options'] as $option_key => $option_value ) : ?>
						<option <?php selected( $option_key, $metaField['value'] ) ?> value="<?php echo $option_key ?>"><?php echo $option_value ?></option>
					<?php endforeach ?>
				</select>

			<?php elseif( 'posts' == $metaField['advancedType'] ) : ?>

				<select id="<?php echo $metaKey ?>" name="<?php echo $metaField['input_name'] ?>">
					<?php
					$posts = get_posts($metaField['args']);
					?>

					<option value="0"><?php _e(' - Select -') ?></option>

					<?php foreach($posts as $post ) : ?>
						<option <?php selected($post->ID, $metaField['value']) ?> value="<?php echo $post->ID ?>"><?php echo $post->post_title ?></option>
					<?php endforeach ?>
				</select>

			<?php elseif (in_array($metaField['advancedType'], ['radio', 'checkbox'])) : ?>

				<?php foreach ($metaField['options'] as $key => $label) : ?>
					<label>
						<input type="<?php echo $metaField['advancedType'] ?>" id="<?php echo $metaKey ?>" name="<?php echo $metaField['input_name'] ?>" value="<?php echo $key ?>" <?php echo $metaField['value'] == $key ? 'checked' : '' ?> />
						<?php echo $label ?>
					</label><br>
				<?php endforeach ?>

			<?php elseif( 'attachment' == $metaField['advancedType'] ) : ?>

				<?php
				$caption = '<small><i>No caption</i></small>';

				if ($metaField['value']) {
					$attachment = get_post($metaField['value']);

					if ($attachment && strpos($attachment->post_mime_type, 'image') !== false) {
						$thumb = $attachment->guid;
						$caption = $attachment->post_excerpt ?: $caption;
					} elseif ($attachment) {
						$thumb = 'http://placehold.jp/ededed/9e9e9e/100x100.jpg?text=' . $attachment->post_mime_type;
						$caption = $attachment->post_excerpt ?: $caption;
					} else {
						$thumb = 'http://placehold.jp/ededed/9e9e9e/100x100.jpg?text=Missing Media';
					}
				} else {
					$thumb = 'http://placehold.jp/ededed/9e9e9e/100x100.jpg?text=%2B';
					$caption = '&nbsp;';
				}
				?>

				<img id="<?php echo $metaKey ?>" <?php if ($metaField['value']) echo 'data-attachment-id="' . $metaField['value'] . '"' ?> class="js-layered-open-media" src="<?php echo $thumb ?>" height="100" alt="Select" />
				<p class="caption"><?php echo $caption ?></p>

				<!--<button class="js-layered-open-media button button-small">Choose media</button>-->
				<input type="hidden" name="<?php echo $metaField['input_name'] ?>" value="<?php echo $metaField['value'] ?>" />

			<?php elseif ($metaField['advancedType'] == 'custom') : ?>

				<?php call_user_func($metaField['render']) ?>

			<?php endif ?>


			<?php if (!$metaField['single']) : ?>
				<button class="button button-small btn-multiple-remove js-multiple-remove">-</button>
			<?php endif ?>


			<?php if (isset($metaField['description'])) : ?>
				<p class="description"><?php echo $metaField['description'] ?></p>
			<?php endif ?>

		</fieldset>

		<?php
	}

	public function savePostMetaFields(int $postId, \WP_Post $post) {
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
		$metaFields = $this->metaFields['post'][$post->post_type] ?? [];

		foreach ($metaFields as $metaKey => $metaField) {
			if (isset($_POST[$metaKey])) {
				if (!$metaField['single']) {
					delete_post_meta($post->ID, $metaKey);

					foreach ($_POST[$metaKey] as $i => $value) {
						if (strlen($value)) {
							add_post_meta($post->ID, $metaKey, $value);
						}
					}
				} else {
					update_post_meta($post->ID, $metaKey, $_POST[$metaKey]);
				}
			} else {
				delete_post_meta($post->ID, $metaKey);
			}
		}
	}

	public function saveTaxonomyMetaFields(int $termId, int $termTaxonomyId, string $taxonomy) {
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
		$metaFields = $this->metaFields['term'][$taxonomy] ?? [];

		foreach ($metaFields as $metaKey => $metaField) {
			if (isset($_POST[$metaKey])) {
				if (!$metaField['single']) {
					delete_term_meta($termId, $metaKey);

					foreach ($_POST[$metaKey] as $i => $value) {
						if (strlen($value)) {
							add_term_meta($termId, $metaKey, $value);
						}
					}
				} else {
					update_term_meta($termId, $metaKey, $_POST[$metaKey]);
				}
			} else {
				delete_term_meta($termId, $metaKey);
			}
		}
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

	public function getPostMetaValue(int $postId, string $metaKey) {
		$post = get_post($postId);
		$metaField = $this->metaFields['post'][$post->post_type][$metaKey] ?? [];
		return $this->renderValue($metaField, get_post_meta($postId, $metaKey, $metaField['single']));
	}

	public function getTermMetaValue(int $termId, string $metaKey) {
		$term = get_term($termId);
		$metaField = $this->metaFields['term'][$term->taxonomy][$metaKey] ?? [];
		return $this->renderValue($metaField, get_term_meta($termId, $metaKey, $metaField['single']));
	}

	public function getUserMetaValue(int $userId, string $metaKey) {
		$metaField = $this->metaFields['user'][$metaKey] ?? [];
		return $this->renderValue($metaField, get_user_meta($userId, $metaKey, $metaField['single']));
	}

	public function renderValue(array $metaField, $value) {

		if ($metaField['advancedType'] === 'attachment') {
			$value = wp_get_attachment_image($value, [75, 75]);
		}

		return $value;
	}

}
