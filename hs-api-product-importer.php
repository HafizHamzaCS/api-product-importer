<?php
/*
Plugin Name: Custom Product Importer
Plugin URI: https://github.com/HafizHamzaCS/api-product-importer
Description: A plugin to import the product from the third party API.
Version: 1.0
Author: Hafiz Hamza 
Author URI: https://github.com/HafizHamzaCS/api-product-importer
Text Domain: hs-api-product-import
*/

// Ensure the plugin is not accessed directly
if (!defined('ABSPATH')) {
    exit;
}
set_time_limit(1200); // Increase the execution time to 1200 seconds


define("HS_PLUGIN_DIR", plugin_dir_path(__FILE__) );
include "includes/class-hs-cron.php";
include "includes/class-hs-api-logger.php";
include "includes/class-hs-product-importer.php";
/**
 * Registers a function to schedule a cron job when the plugin is activated.
 *
 * @return void
 */
register_activation_hook(__FILE__, function() {
    $hs_cron = new HS_Cron();
    $hs_cron->schedule_cron(); // Call non-statically
});
/**
 * Registers a function to deactivate the cron job when the plugin is deactivated.
 *
 * @return void
 */
register_deactivation_hook(__FILE__, function() {
    $hs_cron = new HS_Cron();
    $hs_cron->deactivate(); // Call non-statically
});
// Hook to add settings page to the admin menu
add_action('admin_menu', 'pis_add_settings_page');
/**
 * Adds a settings page for the plugin to the WordPress admin menu.
 *
 * @hooked to admin_menu action hook
 *
 * @return void
 */
function pis_add_settings_page() {
    add_menu_page(  // Page title
        'Product Import Settings', // Page title
        'Product Import',          // Menu title
        'manage_options',          // Capability
        'product-import-settings', // Menu slug
        'pis_settings_page_html',  // Callback function to render the settings page
        'dashicons-admin-generic', // Icon (optional)
        20                         // Position in the menu
    );
}
/**
 * Renders the settings page for the "Product Import" plugin.
 * Handles form submissions and saves the settings.
 *
 * @return void
 */
function pis_settings_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Check if settings are saved
    if (isset($_POST['submit'])) {
        $environment = sanitize_text_field($_POST['environment']);
        update_option('pis_environment', $environment);
        update_option('pis_' . $environment . '_username', sanitize_text_field($_POST[$environment . '_username']));
        update_option('pis_' . $environment . '_password', sanitize_text_field($_POST[$environment . '_password']));
        
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    // Get saved settings
    $environment = get_option('pis_environment', 'staging');
    $staging_username = get_option('pis_staging_username', '');
    $staging_password = get_option('pis_staging_password', '');
    $live_username = get_option('pis_live_username', '');
    $live_password = get_option('pis_live_password', '');

    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <!-- Custom CSS for the form -->
        <style>
            .form-table td {
                padding: 20px 10px;
            }

            label {
                font-weight: bold;
                margin-bottom: 10px;
                display: inline-block;
            }

            .regular-text {
                width: 100%;
                max-width: 400px;
                padding: 8px;
                border: 1px solid #ccc;
                border-radius: 4px;
                box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
            }

            input[type="radio"] {
                margin-right: 5px;
            }

            .fieldset-section {
                margin-bottom: 20px;
            }

            .fieldset-section p {
                margin-bottom: 15px;
            }
        </style>

        <form method="post" action="">
            <?php wp_nonce_field('pis_save_settings', 'pis_nonce_field'); ?>
            <table class="form-table">
                <tr valign="top">
                    <td>
                        <fieldset class="fieldset-section">
                            <label>
                                <input type="radio" name="environment" value="staging" <?php checked($environment, 'staging'); ?> onclick="toggleEnvironmentFields();">
                                Staging
                            </label>
                            <div id="staging_fields" style="display: <?php echo ($environment == 'staging') ? 'block' : 'none'; ?>; margin-left: 30px;">
                                <p>
                                    <label for="staging_username" style="width: 70px;">Username</label>
                                    <input type="text" name="staging_username" value="<?php echo esc_attr($staging_username); ?>" class="regular-text">
                                </p>
                                <p>
                                    <label for="staging_password" style="width: 70px;">Password</label>
                                    <input type="password" name="staging_password" value="<?php echo esc_attr($staging_password); ?>" class="regular-text">
                                </p>
                            </div>
                        </fieldset>
                        <fieldset class="fieldset-section">
                            <label>
                                <input type="radio" name="environment" value="live" <?php checked($environment, 'live'); ?> onclick="toggleEnvironmentFields();">
                                Live
                            </label>
                            <div id="live_fields" style="display: <?php echo ($environment == 'live') ? 'block' : 'none'; ?>; margin-left: 30px;">
                                <p>
                                    <label for="live_username" style="width: 70px;">Username</label>
                                    <input type="text" name="live_username" value="<?php echo esc_attr($live_username); ?>" class="regular-text">
                                </p>
                                <p>
                                    <label for="live_password" style="width: 70px;">Password</label>
                                    <input type="password" name="live_password" value="<?php echo esc_attr($live_password); ?>" class="regular-text">
                                </p>
                            </div>
                        </fieldset>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>

    <script type="text/javascript">
        function toggleEnvironmentFields() {
            var selectedEnvironment = document.querySelector('input[name="environment"]:checked').value;
            document.getElementById('staging_fields').style.display = (selectedEnvironment === 'staging') ? 'block' : 'none';
            document.getElementById('live_fields').style.display = (selectedEnvironment === 'live') ? 'block' : 'none';
        }

        document.addEventListener('DOMContentLoaded', function() {
            toggleEnvironmentFields();
        });
    </script>
    <?php
}



/**
 * Initializes the HS_Cron class to handle the cron job functionality.
 *
 * @return void
 */
new HS_Cron();
