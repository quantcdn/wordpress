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
                <a href="?page=quant&tab=cache" class="nav-tab <?php echo $active_tab == 'cache' ? 'nav-tab-active' : ''; ?>">Cache</a>
            </h2>

            <?php if( $active_tab == 'cache' ) { ?>
                <!-- Cache tab doesn't use a form, it has custom actions -->
                <?php self::renderCacheTab(); ?>
            <?php } else { ?>
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
            <?php } ?>

        </div><?php
    }

    /**
     * Render the Cache management tab
     *
     * @return void
     */
    public static function renderCacheTab()
    {
        ?>
        <div class="quant-cache-management">
            <style>
                .quant-cache-card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                    margin: 20px 0;
                    padding: 20px;
                    max-width: 800px;
                }
                .quant-cache-card h3 {
                    margin-top: 0;
                }
                .quant-cache-card p {
                    color: #646970;
                }
                .quant-purge-paths {
                    width: 100%;
                    min-height: 150px;
                    font-family: monospace;
                }
                .quant-cache-response {
                    margin-top: 15px;
                    padding: 10px;
                    display: none;
                }
                .quant-cache-response.success {
                    display: block;
                    background: #d7f0d7;
                    border-left: 4px solid #46b450;
                    color: #000;
                }
                .quant-cache-response.error {
                    display: block;
                    background: #f8d7da;
                    border-left: 4px solid #dc3545;
                    color: #721c24;
                }
                .quant-button-large {
                    height: 40px;
                    font-size: 14px;
                }
            </style>

            <div class="quant-cache-card">
                <h3>Purge All Cache</h3>
                <p>This will purge the entire CDN cache for your site by purging "/*".</p>
                <button type="button" id="quant-purge-all" class="button button-primary button-hero quant-button-large">
                    Purge All Cache
                </button>
                <div id="quant-purge-all-response" class="quant-cache-response"></div>
            </div>

            <div class="quant-cache-card">
                <h3>Purge Selective Paths</h3>
                <p>Enter specific paths to purge from the CDN cache (one per line):</p>
                <textarea id="quant-purge-paths" class="quant-purge-paths" placeholder="/path/to/page&#10;/another/path&#10;/blog/*"></textarea>
                <p><em>Examples: /about, /blog/*, /wp-content/themes/mytheme/style.css</em></p>
                <button type="button" id="quant-purge-selective" class="button button-primary button-large quant-button-large">
                    Purge Selected Paths
                </button>
                <div id="quant-purge-selective-response" class="quant-cache-response"></div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                // Purge all cache
                $('#quant-purge-all').on('click', function() {
                    var button = $(this);
                    var responseDiv = $('#quant-purge-all-response');
                    
                    button.prop('disabled', true).text('Purging...');
                    responseDiv.removeClass('success error').hide();
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'quant_purge_cache',
                            nonce: '<?php echo wp_create_nonce('quant_purge_cache'); ?>',
                            paths: ['/*']
                        },
                        success: function(response) {
                            if (response.success) {
                                responseDiv.addClass('success').text('✓ Successfully purged all cache').show();
                            } else {
                                responseDiv.addClass('error').text('✗ Error: ' + (response.data || 'Unknown error')).show();
                            }
                            button.prop('disabled', false).text('Purge All Cache');
                        },
                        error: function(xhr, status, error) {
                            responseDiv.addClass('error').text('✗ Request failed: ' + error).show();
                            button.prop('disabled', false).text('Purge All Cache');
                        }
                    });
                });

                // Purge selective paths
                $('#quant-purge-selective').on('click', function() {
                    var button = $(this);
                    var responseDiv = $('#quant-purge-selective-response');
                    var pathsTextarea = $('#quant-purge-paths');
                    var paths = pathsTextarea.val().split('\n').map(function(p) { 
                        return p.trim(); 
                    }).filter(function(p) { 
                        return p.length > 0; 
                    });
                    
                    if (paths.length === 0) {
                        responseDiv.addClass('error').text('✗ Please enter at least one path').show();
                        return;
                    }
                    
                    button.prop('disabled', true).text('Purging...');
                    responseDiv.removeClass('success error').hide();
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'quant_purge_cache',
                            nonce: '<?php echo wp_create_nonce('quant_purge_cache'); ?>',
                            paths: paths
                        },
                        success: function(response) {
                            if (response.success) {
                                responseDiv.addClass('success').text('✓ Successfully purged ' + paths.length + ' path(s)').show();
                            } else {
                                responseDiv.addClass('error').text('✗ Error: ' + (response.data || 'Unknown error')).show();
                            }
                            button.prop('disabled', false).text('Purge Selected Paths');
                        },
                        error: function(xhr, status, error) {
                            responseDiv.addClass('error').text('✗ Request failed: ' + error).show();
                            button.prop('disabled', false).text('Purge Selected Paths');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }
}
