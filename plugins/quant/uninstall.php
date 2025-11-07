<?php

// Triggered during un-installation of the plugin.
// @todo: Clean up database/config values.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clean up options data.
delete_option("quant_internal_token");
if (defined('QUANT_SETTINGS_KEY')) {
    delete_option(QUANT_SETTINGS_KEY);
}
if (defined('QUANT_CRON_SETTINGS_KEY')) {
    delete_option(QUANT_CRON_SETTINGS_KEY);
}
if (defined('QUANT_SEED_KEY')) {
    delete_option(QUANT_SEED_KEY);
}
