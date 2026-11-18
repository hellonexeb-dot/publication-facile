<?php
/**
 * Mises à jour depuis GitHub, affichées dans le tableau de bord comme pour les autres extensions.
 *
 * Fonctionnement : l'en-tête « Update URI » de l'extension pointe vers GitHub. WordPress
 * (5.8 et plus) appelle alors le filtre update_plugins_github.com lors de sa vérification
 * habituelle (deux fois par jour). On y lit la dernière version publiée sur GitHub.
 * Aucune donnée du site n'est envoyée.
 *
 * @package PublicationFacile
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vérificateur de mises à jour GitHub.
 */
class PF_Updater {

	const REPO      = 'hellonexeb-dot/publication-facile';
	const SLUG      = 'publication-facile';
	const TRANSIENT = 'pf_github_release';
	const CACHE     = 6 * HOUR_IN_SECONDS;

	/**
	 * Branche les filtres.
	 */
	public function __construct() {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'flush' ), 10, 0 );
	}

	/**
	 * Dernière version publiée sur GitHub (mise en cache).
	 *
	 * @return array|null { version, package, url, notes, published }
	 */
	public static function latest() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Publication-Facile-Updater',
				),
			)
		);

		$release = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$release = self::parse( json_decode( wp_remote_retrieve_body( $response ), true ) );
		}

		// En cas d'échec, on réessaie seulement une heure plus tard.
		set_site_transient(
			self::TRANSIENT,
			$release ? $release : array( 'version' => '' ),
			$release ? self::CACHE : HOUR_IN_SECONDS
		);
		return $release;
	}

	/**
	 * Extrait les informations utiles d'une réponse GitHub.
	 *
	 * @param mixed $data Réponse décodée.
	 * @return array|null
	 */
	public static function parse( $data ) {
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( (string) $data['tag_name'], 'vV' );
		if ( ! preg_match( '/^\d+(\.\d+)*$/', $version ) ) {
			return null;
		}

		// Le ZIP joint à la version (il contient le dossier publication-facile/).
		$package = '';
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				$url = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
				if ( '.zip' === strtolower( substr( $url, -4 ) ) && 0 === strpos( $url, 'https://github.com/' . self::REPO . '/' ) ) {
					$package = $url;
					break;
				}
			}
		}
		if ( ! $package ) {
			return null;
		}

		return array(
			'version'   => $version,
			'package'   => $package,
			'url'       => 'https://github.com/' . self::REPO,
			'notes'     => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
		);
	}

	/**
	 * Réponse au filtre update_plugins_github.com.
	 *
	 * @param array|false $update      Mise à jour déjà trouvée.
	 * @param array       $plugin_data En-têtes de l'extension.
	 * @param string      $plugin_file Fichier principal (dossier/fichier.php).
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( PF_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::latest();
		if ( ! $release ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
		);
	}

	/**
	 * Fenêtre « Voir les détails de la version ».
	 *
	 * @param false|object|array $result Résultat.
	 * @param string             $action Action demandée.
	 * @param object             $args   Arguments.
	 * @return false|object|array
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::latest();
		if ( ! $release ) {
			return $result;
		}
		$notes = '' !== trim( $release['notes'] ) ? wpautop( esc_html( $release['notes'] ) ) : '<p>' . esc_html__( 'Nouvelle version disponible.', 'publication-facile' ) . '</p>';
		return (object) array(
			'name'          => 'Publication Facile',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'Publication Facile',
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'last_updated'  => $release['published'],
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'sections'      => array(
				'changelog' => $notes,
			),
		);
	}

	/**
	 * Vide le cache après une mise à jour.
	 */
	public function flush() {
		delete_site_transient( self::TRANSIENT );
	}
}
