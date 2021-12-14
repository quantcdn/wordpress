<?php

// No poor mans cron allowed.
define('DISABLE_WP_CRON', true);

/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'wordpress');

/** MySQL database username */
define( 'DB_USER', 'wordpress');

/** MySQL database password */
define( 'DB_PASSWORD', 'wordpress');

/** MySQL hostname */
define( 'DB_HOST', 'db:3306');

/** Database Charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8');

/** The Database Collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '');

/** Settings that make Quant work properly. */
$protocol = stripos($_SERVER['SERVER_PROTOCOL'],'https') === 0 ? 'https://' : 'http://';
define('WP_SITEURL', $protocol . $_SERVER['HTTP_HOST']);
define('WP_HOME', $protocol . $_SERVER['HTTP_HOST']);
define ('WPCF7_LOAD_JS', false);

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'c492f07d9ea9b20f94ccacc64e220610e44d42f8');
define( 'SECURE_AUTH_KEY',  '896a61debd7974f7b7cdaf9ff9e8193090889c84');
define( 'LOGGED_IN_KEY',    '5be128cfae7c274be13cce04246dd21d73f74438');
define( 'NONCE_KEY',        '34e73088ba864862949342e13a51fe65163a4a30');
define( 'AUTH_SALT',        'fcbe67cbd86fe9bf0b6c4cee012b1432c79a7eb8');
define( 'SECURE_AUTH_SALT', '4cd8ad8b8327390da36580b8e85e544860c48152');
define( 'LOGGED_IN_SALT',   'b11581170a86df719358266ec918ab6d7c0bfef3');
define( 'NONCE_SALT',       '18bf4ef3084a845b16102cdbd2fb165db7c1afe7');

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );

// If we're behind a proxy server and using HTTPS, we need to alert WordPress of that fact
// see also http://codex.wordpress.org/Administration_Over_SSL#Using_a_Reverse_Proxy
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
	$_SERVER['HTTPS'] = 'on';
}

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Enables revisions for Quant testing/development. */
define( 'WP_POST_REVISIONS', TRUE);

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
