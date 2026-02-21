<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function pinterest_capi_admin_init(): void {
    add_action( 'admin_menu', 'pinterest_capi_add_settings_page' );
    add_action( 'admin_init', 'pinterest_capi_register_settings' );
}

function pinterest_capi_add_settings_page(): void {
    add_options_page(
        'Pinterest CAPI',
        'Pinterest CAPI',
        'manage_options',
        'pinterest-capi',
        'pinterest_capi_render_settings_page'
    );
}

function pinterest_capi_register_settings(): void {
    register_setting( 'pinterest_capi_settings', 'pinterest_capi_access_token', [
        'sanitize_callback' => 'sanitize_text_field',
    ] );
    register_setting( 'pinterest_capi_settings', 'pinterest_capi_ad_account_id', [
        'sanitize_callback' => 'sanitize_text_field',
    ] );
    register_setting( 'pinterest_capi_settings', 'pinterest_capi_tag_id', [
        'sanitize_callback' => 'sanitize_text_field',
    ] );
}

function pinterest_capi_render_settings_page(): void {
    ?>
    <div class="wrap">
        <h1>Pinterest Conversions API Einstellungen</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'pinterest_capi_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th>Pinterest Tag ID</th>
                    <td><input type="text" name="pinterest_capi_tag_id"
                        value="<?php echo esc_attr( get_option( 'pinterest_capi_tag_id', '' ) ); ?>"
                        class="regular-text" /></td>
                </tr>
                <tr>
                    <th>Ad Account ID</th>
                    <td><input type="text" name="pinterest_capi_ad_account_id"
                        value="<?php echo esc_attr( get_option( 'pinterest_capi_ad_account_id', '' ) ); ?>"
                        class="regular-text" /></td>
                </tr>
                <tr>
                    <th>Access Token (Bearer)</th>
                    <td><input type="password" name="pinterest_capi_access_token"
                        value="<?php echo esc_attr( get_option( 'pinterest_capi_access_token', '' ) ); ?>"
                        class="regular-text" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
