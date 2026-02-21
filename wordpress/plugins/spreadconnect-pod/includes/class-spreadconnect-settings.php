<?php

namespace SpreadconnectPod;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SpreadconnectSettings {

    public function add_settings_page(): void {
        add_options_page(
            'Spreadconnect POD Einstellungen',
            'Spreadconnect POD',
            'manage_options',
            'spreadconnect-pod',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( 'spreadconnect_pod_settings', 'spreadconnect_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( 'spreadconnect_pod_settings', 'spreadconnect_use_staging', [
            'sanitize_callback' => 'rest_sanitize_boolean',
        ] );
    }

    public function render_settings_page(): void {
        ?>
        <div class="wrap">
            <h1>Spreadconnect POD Einstellungen</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'spreadconnect_pod_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="password" name="spreadconnect_api_key"
                                   value="<?php echo esc_attr( get_option( 'spreadconnect_api_key', '' ) ); ?>"
                                   class="regular-text" autocomplete="off" />
                            <p class="description">Spreadconnect API Key (aus dem Spreadconnect Dashboard).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Staging-Modus</th>
                        <td>
                            <label>
                                <input type="checkbox" name="spreadconnect_use_staging" value="1"
                                       <?php checked( get_option( 'spreadconnect_use_staging', true ) ); ?> />
                                Staging API verwenden (staging.spreadconnect.com)
                            </label>
                            <p class="description">Für lokale Entwicklung und Tests. Deaktivieren für Produktion.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
