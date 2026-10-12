<?php defined( 'ABSPATH' ) || exit; ?>
<div class="scf-fields-builder">
    <table class="wp-list-table widefat fixed striped scf-fields-table" id="scf-fields-list">
        <thead>
            <tr>
                <th style="width:30px;"></th>
                <th><?php esc_html_e( 'Label', 'smart-contact-form' ); ?></th>
                <th><?php esc_html_e( 'Name (slug)', 'smart-contact-form' ); ?></th>
                <th><?php esc_html_e( 'Type', 'smart-contact-form' ); ?></th>
                <th><?php esc_html_e( 'Placeholder', 'smart-contact-form' ); ?></th>
                <th><?php esc_html_e( 'Required', 'smart-contact-form' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'smart-contact-form' ); ?></th>
            </tr>
        </thead>
        <tbody id="scf-fields-body">
            <?php foreach ( $fields as $i => $field ) : ?>
            <tr class="scf-field-row" draggable="true">
                <td class="scf-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'smart-contact-form' ); ?>">☰</td>
                <td><input type="text" name="scf_field_labels[]" value="<?php echo esc_attr( $field['label'] ); ?>" class="widefat"></td>
                <td><input type="text" name="scf_field_names[]" value="<?php echo esc_attr( $field['name'] ); ?>" class="widefat" pattern="[a-z0-9_]+" title="lowercase, numbers, underscores"></td>
                <td>
                    <select name="scf_field_types[]">
                        <?php foreach ( [ 'text', 'email', 'tel', 'textarea', 'select', 'number', 'url' ] as $t ) : ?>
                            <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $field['type'], $t ); ?>><?php echo esc_html( $t ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" name="scf_field_placeholders[]" value="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>" class="widefat"></td>
                <td style="text-align:center;">
                    <input type="checkbox" name="scf_field_required[]" value="<?php echo esc_attr( $i ); ?>" <?php checked( ! empty( $field['required'] ) ); ?>>
                </td>
                <td><button type="button" class="button scf-remove-field"><?php esc_html_e( 'Remove', 'smart-contact-form' ); ?></button></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p>
        <button type="button" class="button button-secondary" id="scf-add-field">
            + <?php esc_html_e( 'Add Field', 'smart-contact-form' ); ?>
        </button>
    </p>
</div>

<script type="text/html" id="scf-field-row-template">
<tr class="scf-field-row" draggable="true">
    <td class="scf-drag-handle">☰</td>
    <td><input type="text" name="scf_field_labels[]" value="" class="widefat" placeholder="<?php esc_attr_e( 'Label', 'smart-contact-form' ); ?>"></td>
    <td><input type="text" name="scf_field_names[]" value="" class="widefat" pattern="[a-z0-9_]+" placeholder="my_field"></td>
    <td>
        <select name="scf_field_types[]">
            <option value="text">text</option>
            <option value="email">email</option>
            <option value="tel">tel</option>
            <option value="textarea">textarea</option>
            <option value="select">select</option>
            <option value="number">number</option>
            <option value="url">url</option>
        </select>
    </td>
    <td><input type="text" name="scf_field_placeholders[]" value="" class="widefat"></td>
    <td style="text-align:center;"><input type="checkbox" name="scf_field_required[]" value="__INDEX__"></td>
    <td><button type="button" class="button scf-remove-field"><?php esc_html_e( 'Remove', 'smart-contact-form' ); ?></button></td>
</tr>
</script>
