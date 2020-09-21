<?php

namespace Quant;

use Quant\Client;

class SettingsScreen
{
    /**
     * Register the requred hooks for the admin screen
     *
     * @return void
     */
    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'addMenu']);
        add_action('admin_notices', [__CLASS__, 'displayNotices']);
    }

    /**
     * Register an tools/management menu for the admin area
     *
     * @return void
     */
    public static function addMenu()
    {
        add_options_page(
            'Quant Settings',
            'QuantCDN',
            'manage_options',
            'quant',
            [__CLASS__, 'renderPage']
        );
    }

    /**
     * Display a configuration alert if the user hasn't configured the plugin yet.
     *
     * @return void
     */
    public static function displayNotices()
    {
        global $hook_suffix;

        if ($hook_suffix == 'settings_page_quant') {

            $client = new Client();
            $success = $client->ping();

            if ($success) {
                ?><div class="notice notice-success">
                    <p>Successfully made a connection to Quant.</p>
                </div><?php
            }
            else {
                ?><div class="notice notice-warning">
                    <p>Unable to connect to the Quant API, please check your configuration values and try again.</p>
                </div><?php
            }

        }
    }

    /**
     * Render the management/tools page
     *
     * @return void
     */
    public static function renderPage()
    {
        ?><div class="wrap">

            <h2><?= get_admin_page_title(); ?></h2>
            
            <form method="post" action="<?= esc_url(admin_url('options.php')); ?>">
                <?php

                settings_fields(QUANT_SETTINGS_KEY);
                do_settings_sections(QUANT_SETTINGS_KEY);

                submit_button('Save Settings', 'primary', 'submit', false);

                ?>
            </form>

        </div><?php
    }
}
