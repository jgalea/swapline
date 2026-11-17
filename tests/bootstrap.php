<?php

define( 'ABSPATH', __DIR__ . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- stands in for WordPress.
define( 'OBJECT', 'OBJECT' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
define( 'ARRAY_A', 'ARRAY_A' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
define( 'ARRAY_N', 'ARRAY_N' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
define( 'DAY_IN_SECONDS', 86400 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
define( 'SWAPLINE_VERSION', 'test' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/Support/wp-stubs.php';
