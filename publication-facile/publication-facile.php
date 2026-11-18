<?php
/**
 * Plugin Name:       Publication Facile
 * Description:       Publiez des articles construits avec Elementor grâce à un formulaire simple (titre, résumé, texte, image), à partir d’un article modèle. Compatible Elementor gratuit.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  elementor
 * Author:            Publication Facile
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       publication-facile
 * Update URI:        https://github.com/hellonexeb-dot/publication-facile
 *
 * @package PublicationFacile
 */

defined( 'ABSPATH' ) || exit;

define( 'PF_VERSION', '1.2.0' );
define( 'PF_FILE', __FILE__ );
define( 'PF_DIR', plugin_dir_path( __FILE__ ) );
define( 'PF_URL', plugin_dir_url( __FILE__ ) );

require_once PF_DIR . 'includes/class-pf-data-transformer.php';
require_once PF_DIR . 'includes/class-pf-template-inspector.php';
require_once PF_DIR . 'includes/class-pf-post-builder.php';
require_once PF_DIR . 'includes/class-pf-settings.php';
require_once PF_DIR . 'includes/class-pf-editor-page.php';
require_once PF_DIR . 'includes/class-pf-updater.php';
require_once PF_DIR . 'includes/class-pf-plugin.php';

register_activation_hook( __FILE__, array( 'PF_Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'PF_Plugin', 'instance' ) );
