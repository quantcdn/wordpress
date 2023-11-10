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

            $structure = get_option( 'permalink_structure' );
            if (empty($structure)) {
                ?><div class="notice notice-warning">
                    <p>Quant currently requires permalinks set to something other than "Plain".</p>
                </div><?php
                return;
            }

            $validateMarkup = $client->markupFromRoute('/__quant-validate');
            if (empty($validateMarkup['content']) || $validateMarkup['content'] != 'qsuccess') {
                ?><div class="notice notice-warning">
                    <p>Unable to connect to local webserver. Please check the configuration values for webserver and host and try again.</p>
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

        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'seed';

        ?><div class="wrap">

            <h2><?= get_admin_page_title(); ?></h2>

            <h2 class="nav-tab-wrapper">
                <a href="?page=quant&tab=seed" class="nav-tab <?php echo $active_tab == 'seed' ? 'nav-tab-active' : ''; ?>">Seed Settings</a>
                <a href="?page=quant&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=quant&tab=cron" class="nav-tab <?php echo $active_tab == 'cron' ? 'nav-tab-active' : ''; ?>">Cron</a>
            </h2>

            <form method="post" action="<?php echo esc_url( add_query_arg('tab', $active_tab, admin_url( 'options.php' )) ); ?>">

                <?php

                if( $active_tab == 'settings' ) {
                    settings_fields(QUANT_SETTINGS_KEY);
                    do_settings_sections(QUANT_SETTINGS_KEY);
                    submit_button('Save Settings', 'primary', 'submit', false);
                }
                else if ( $active_tab == 'seed') {
                    settings_fields(QUANT_SEED_KEY);
                    do_settings_sections(QUANT_SEED_KEY);
                    submit_button('Save Settings', 'primary', 'submit', false);
                }
                else if ( $active_tab == 'cron') {
                    settings_fields(QUANT_CRON_SETTINGS_KEY);
                    do_settings_sections(QUANT_CRON_SETTINGS_KEY);
                    submit_button('Save Settings', 'primary', 'submit', false);
                }

                ?>
            </form>

        </div><?php
    }
}
