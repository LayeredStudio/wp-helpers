<?php

namespace Layered\Wp;

class PostMetaBox {

  protected static $addedAssets = false;
  protected static $fields = [];

  public function __construct($postType, $title, $fields, $context = 'normal', $priority = 'default', $filter = '__return_true') {


    self::addAdminAssets();


    // process fields
    $fields = array_map(function($field) {

      $field = array_merge([
        'default'     =>  null,
        'type'        =>  'text',
        'value'       =>  null,
        'placeholder' =>  '',
        'prefix '     =>  '',
        'suffix'      =>  ''
      ], $field);

      self::$fields[$field['id']] = $field;

      return $field;
    }, $fields);


    add_action('add_meta_boxes', function() use($postType, $title, $context, $fields, $priority, $filter) {

      if (call_user_func($filter)) {
        add_meta_box(sanitize_title($title), $title, function() use($fields) {
          wp_nonce_field( 'rkm_meta_boxes', 'rkm_meta_boxes_nonce' );
          $cf = get_post_custom();
          ?>

          <table class="form-table">
            <tbody>

              <?php

              foreach ($fields as $field) {
                $field['value'] = isset( $cf[$field['id']] ) ? $cf[$field['id']][0] : null;
                $field['name'] = $field['id'];

                if (isset($field['multiple']) && $field['multiple'] == true) {
                  $field['name'] = $field['id'] . '[]';
                  $field['value'] = maybe_unserialize($field['value']);
                }

                ?>

                <?php if ($field['type'] === 'line') : ?>
                  <tr>
                    <td colspan="2"><hr></td>
                  </tr>
                <?php else : ?>

                  <tr class="field-<?php echo $field['id'] ?> field-type-<?php echo $field['type'] ?>">
                    <th scope="row">
                      <label for="<?php echo $field['id'] ?>"><?php echo $field['title'] ?></label>
                    </th>
                    <td>

                      <?php
                      if (isset($field['multiple']) && $field['multiple'] == true && $field['value'] && is_array($field['value'])) {
                        foreach ($field['value'] as $val) {
                          $this->renderField(array_merge($field, ['value' => $val]));
                        }
                      }
                      ?>


                      <?php $this->renderField($field) ?>


                      <?php if (isset($field['multiple']) && $field['multiple'] == true) : ?>

                        <button class="button button-primary button-small js-layered-clone-field"><?php printf(__('Add %s'), $field['title']) ?></button>

                      <?php endif ?>

                    </td>
                  </tr>

                <?php endif ?>

                <?php
              }

              ?>

            </tbody>
          </table>

          <?php

        }, $postType, $context, $priority );
      }

    } );

    add_action( 'save_post', function() use($postType, $fields, $filter) {
      global $post;

      if (!call_user_func($filter)) return;
      if( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
      if( ! isset( $_POST['rkm_meta_boxes_nonce'] ) || ! wp_verify_nonce( $_POST['rkm_meta_boxes_nonce'], 'rkm_meta_boxes' ) ) return;

      foreach ($fields as $field) {

        if (isset($_POST[$field['id']])) {
          if (isset($field['multiple']) && $field['multiple'] == true) {
            foreach( $_POST[$field['id']] as $i => $value ) {
              if( ! strlen( $value ) ) {
                unset( $_POST[$field['id']][$i] );
              }
            }
          }
          update_post_meta( $post->ID, $field['id'], $_POST[$field['id']] );
        }
      }

    } );
  }

  protected function renderField($field) {

    if (is_array($field['value'])) {
      $field['value'] = '';
    }

    ?>

    <fieldset>
      <legend class="screen-reader-text"><span><?php echo $field['title'] ?></span></legend>

      <?php if (in_array($field['type'], ['text', 'number', 'date', 'url', 'time'])) : ?>
        
        <?php if (isset($field['prefix'])) echo $field['prefix'] ?>
        <input id="<?php echo $field['id'] ?>" type="<?php echo $field['type'] ?>" name="<?php echo $field['name'] ?>" placeholder="<?php echo $field['placeholder'] ?: '' ?>" value="<?php echo $field['value'] ?>" class="<?php echo isset($field['class']) ? $field['class'] : 'regular-text' ?>" />
        <?php if (isset($field['suffix'])) echo $field['suffix'] ?>

      <?php elseif( 'select' == $field['type'] ) : ?>
        
        <select id="<?php echo $field['id'] ?>" name="<?php echo $field['name'] ?>">
          <?php foreach( $field['options'] as $option_key => $option_value ) : ?>
            <option <?php selected( $option_key, $field['value'] ) ?> value="<?php echo $option_key ?>"><?php echo $option_value ?></option>
          <?php endforeach ?>
        </select>
      
      <?php elseif( 'posts' == $field['type'] ) : ?>
        
		<select id="<?php echo $field['id'] ?>" name="<?php echo $field['name'] ?>">
			<?php
			$posts = get_posts($field['args']);
			?>

			<option value="0"><?php _e(' - Select -') ?></option>

			<?php foreach($posts as $post ) : ?>
				<option <?php selected($post->ID, $field['value']) ?> value="<?php echo $post->ID ?>"><?php echo $post->post_title ?></option>
			<?php endforeach ?>
		</select>
      
      <?php elseif( 'upload' == $field['type'] ) : ?>
        
        <input id="<?php echo $field['id'] ?>" type="text" size="50" name="<?php echo $field['id'] ?>" value="<?php echo $value ?>" />
        <button class="js-layered-upload-btn">Choose file</button>
      
      <?php elseif (in_array($field['type'], ['radio', 'checkbox'])) : ?>

        <?php foreach( $field['options'] as $key => $label ) : ?>
          <label>
            <input type="<?php echo $field['type'] ?>" id="<?php echo $field['id'] ?>" name="<?php echo $field['id'] ?>" value="<?php echo $key ?>" <?php echo $field['value'] == $key ? 'checked' : '' ?> />
            <?php echo $label ?>
          </label><br>
        <?php endforeach ?>

      <?php elseif ($field['type'] == 'custom') : ?>

        <?php call_user_func($field['render']) ?>

      <?php endif ?>


      <?php if (isset($field['multiple']) && $field['multiple'] == true) : ?>
        <button class="button button-small">-</button>
      <?php endif ?>


      <?php if (isset($field['description'])) : ?>
        <p class="description"><?php echo $field['description'] ?></p>
      <?php endif ?>

    </fieldset>

    <?php
  }

  protected function addAdminAssets() {

    add_action('admin_head', function() {
      ?>

      <style type="text/css">
      
      </style>

      <script type="text/javascript">
      jQuery(function($) {

        $('.js-layered-clone-field').click(function(e) {
          e.preventDefault();

          var lastField = $(this).parent().find('fieldset:last');
          var clonedField = lastField.clone(true).insertAfter(lastField);

          clonedField.find('input').val('');

        });

      });
      </script>

      <?php
    });

  }

  public static function get($key) {
    $post = get_post();

    $cf = get_post_custom();

    if (isset($cf[$key]) && isset($cf[$key][0])) {
      return maybe_unserialize($cf[$key][0]);
    }

    return null;
  }

  public static function getField($key) {
    $post = get_post();

    if (isset(self::$fields[$key])) {
      $cf = get_post_custom();
      $field = self::$fields[$key];

      if (isset($cf[$key]) && isset($cf[$key][0])) {
        $field['value'] = maybe_unserialize($cf[$key][0]);
      }

      return $field;
    }

    return null;
  }

}
