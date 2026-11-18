<?php
/**
 * Désinstallation : supprime les réglages. Les articles créés ne sont jamais supprimés.
 *
 * @package PublicationFacile
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'pf_settings' );
delete_site_transient( 'pf_github_release' );
delete_post_meta_by_key( '_pf_source' );
