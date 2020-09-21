<?php

/**
 * Plugin Name: Quant
 * Description: QuantCDN static edge integration
 * Author: Stuart Rowlands
 * Plugin URI: https://www.quantcdn.io
 * Version: 1.0.0
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once(__DIR__.'/src/App.php');

function Quant()
{
    return Quant\App::instance();
}

register_activation_hook(__FILE__, [Quant(), 'activation']);
register_deactivation_hook(__FILE__, [Quant(), 'deactivation']);
