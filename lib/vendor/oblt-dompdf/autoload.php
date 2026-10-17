<?php
/**
 * OrderBay Lite: autoloader for the bundled PDF engine.
 *
 * Bundled (unmodified, trimmed to runtime files):
 *   dompdf/dompdf 3.1.6            LGPL-2.1          dompdf/dompdf/LICENSE.LGPL
 *   dompdf/php-font-lib 1.0.2      LGPL-2.1-or-later dompdf/php-font-lib/LICENSE
 *   dompdf/php-svg-lib 1.0.2       LGPL-3.0-or-later dompdf/php-svg-lib/LICENSE
 *   masterminds/html5 2.9.0        MIT               masterminds/html5/LICENSE.txt
 *   sabberworm/php-css-parser 8.7.0 MIT              sabberworm/php-css-parser/LICENSE
 *
 * Composer's generated autoloader is deliberately not shipped: its class names are
 * derived from a hash, so two plugins bundling the same Dompdf build would declare
 * the same ComposerAutoloaderInit class and fatal. This loader only maps the five
 * namespaces and never declares a class itself. The caller loads it only when
 * \Dompdf\Dompdf is not already available, so another copy of Dompdf (the paid
 * OrderBay plugin, or any other plugin) always wins.
 *
 * @package OrderBay_Lite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'OBLT_DOMPDF_AUTOLOAD' ) ) {
	define( 'OBLT_DOMPDF_AUTOLOAD', __DIR__ );

	spl_autoload_register(
		static function ( $class_name ) {
			static $map = null;
			if ( null === $map ) {
				$base = OBLT_DOMPDF_AUTOLOAD . '/';
				$map  = array(
					'Dompdf\\'         => $base . 'dompdf/dompdf/src/',
					'FontLib\\'        => $base . 'dompdf/php-font-lib/src/FontLib/',
					'Svg\\'            => $base . 'dompdf/php-svg-lib/src/Svg/',
					'Masterminds\\'    => $base . 'masterminds/html5/src/',
					'Sabberworm\\CSS\\' => $base . 'sabberworm/php-css-parser/src/',
				);
			}
			if ( 'Dompdf\\Cpdf' === $class_name ) {
				require OBLT_DOMPDF_AUTOLOAD . '/dompdf/dompdf/lib/Cpdf.php';
				return;
			}
			foreach ( $map as $prefix => $dir ) {
				$len = strlen( $prefix );
				if ( 0 !== strncmp( $class_name, $prefix, $len ) ) {
					continue;
				}
				$file = $dir . str_replace( '\\', '/', substr( $class_name, $len ) ) . '.php';
				if ( is_file( $file ) ) {
					require $file;
				}
				return;
			}
		}
	);
}
