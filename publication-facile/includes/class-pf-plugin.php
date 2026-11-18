<?php
/**
 * Chargement de l'extension.
 *
 * @package PublicationFacile
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classe principale.
 */
final class PF_Plugin {

	const OPTION = 'pf_settings';

	/**
	 * Instance unique.
	 *
	 * @var PF_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Renvoie l'instance unique.
	 *
	 * @return PF_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructeur : branchement des hooks.
	 */
	private function __construct() {
		load_plugin_textdomain( 'publication-facile', false, dirname( plugin_basename( PF_FILE ) ) . '/languages' );

		// Mises à jour depuis GitHub (aussi pendant les tâches planifiées de WordPress).
		new PF_Updater();

		if ( is_admin() ) {
			new PF_Settings();
			new PF_Editor_Page();
			add_action( 'admin_notices', array( $this, 'elementor_notice' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( PF_FILE ), array( $this, 'action_links' ) );
		}
	}

	/**
	 * Elementor est-il chargé ?
	 *
	 * @return bool
	 */
	public static function elementor_active() {
		return did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Réglages par défaut.
	 *
	 * @return array{templates: array}
	 */
	public static function defaults() {
		return array( 'templates' => array() );
	}

	/**
	 * Configuration vide d'un modèle.
	 *
	 * @return array
	 */
	public static function template_defaults() {
		return array(
			'template_id' => 0,
			'label'       => '',
			'category_id' => 0,
			'mapping'     => array(
				'title'   => '',
				'summary' => '',
				'body'    => '',
				'image'   => '',
			),
		);
	}

	/**
	 * Normalise la configuration d'un modèle.
	 *
	 * @param array $config Configuration brute.
	 * @return array
	 */
	public static function normalize_template( $config ) {
		$defaults = self::template_defaults();
		$config   = is_array( $config ) ? $config : array();
		$out      = array_merge( $defaults, array_intersect_key( $config, $defaults ) );

		$out['template_id'] = (int) $out['template_id'];
		$out['category_id'] = (int) $out['category_id'];
		$out['label']       = (string) $out['label'];
		$out['mapping']     = array_merge( $defaults['mapping'], is_array( $out['mapping'] ) ? array_intersect_key( $out['mapping'], $defaults['mapping'] ) : array() );
		return $out;
	}

	/**
	 * Réglages enregistrés (avec reprise du format 1.0 : un seul modèle).
	 *
	 * @return array{templates: array<int, array>}
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		// Format 1.0 : template_id / category_id / mapping à la racine.
		if ( ! isset( $saved['templates'] ) && ! empty( $saved['template_id'] ) ) {
			$saved = array(
				'templates' => array(
					array(
						'template_id' => (int) $saved['template_id'],
						'label'       => '',
						'category_id' => isset( $saved['category_id'] ) ? (int) $saved['category_id'] : 0,
						'mapping'     => isset( $saved['mapping'] ) ? $saved['mapping'] : array(),
					),
				),
			);
		}

		$templates = array();
		if ( isset( $saved['templates'] ) && is_array( $saved['templates'] ) ) {
			foreach ( $saved['templates'] as $config ) {
				$config = self::normalize_template( $config );
				if ( $config['template_id'] && ! isset( $templates[ $config['template_id'] ] ) ) {
					if ( '' === $config['label'] ) {
						$config['label'] = self::default_label( $config['template_id'] );
					}
					$templates[ $config['template_id'] ] = $config;
				}
			}
		}

		return array( 'templates' => $templates );
	}

	/**
	 * Configuration d'un modèle, ou null s'il n'est pas déclaré.
	 *
	 * @param int $template_id ID du modèle.
	 * @return array|null
	 */
	public static function get_template( $template_id ) {
		$settings = self::get_settings();
		return isset( $settings['templates'][ (int) $template_id ] ) ? $settings['templates'][ (int) $template_id ] : null;
	}

	/**
	 * Modèles utilisables (sans erreur de configuration).
	 *
	 * @return array<int, array>
	 */
	public static function usable_templates() {
		$usable = array();
		foreach ( self::get_settings()['templates'] as $id => $config ) {
			if ( ! PF_Template_Inspector::validate_template( $config ) ) {
				$usable[ $id ] = $config;
			}
		}
		return $usable;
	}

	/**
	 * Le contenu est-il déclaré comme modèle ?
	 *
	 * @param int $post_id ID.
	 * @return bool
	 */
	public static function is_template( $post_id ) {
		return null !== self::get_template( $post_id );
	}

	/**
	 * Nom par défaut d'un modèle : le titre du contenu.
	 *
	 * @param int $template_id ID.
	 * @return string
	 */
	public static function default_label( $template_id ) {
		$title = get_the_title( (int) $template_id );
		return '' !== $title ? $title : sprintf( 'Modèle #%d', (int) $template_id );
	}

	/**
	 * Catégorie « Actualités » si elle existe.
	 *
	 * @return int
	 */
	public static function default_category() {
		$term = get_term_by( 'slug', 'actualites', 'category' );
		if ( ! $term ) {
			$term = get_term_by( 'name', 'Actualités', 'category' );
		}
		return $term ? (int) $term->term_id : 0;
	}

	/**
	 * Activation : rien à préparer, les réglages par défaut suffisent.
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	/**
	 * Avis si Elementor est absent.
	 */
	public function elementor_notice() {
		if ( self::elementor_active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'Publication Facile a besoin de l’extension Elementor (version gratuite) pour fonctionner. Merci de l’installer et de l’activer.', 'publication-facile' );
		echo '</p></div>';
	}

	/**
	 * Lien « Réglages » dans la liste des extensions.
	 *
	 * @param string[] $links Liens.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . PF_Settings::PAGE ) ),
				esc_html__( 'Réglages', 'publication-facile' )
			)
		);
		return $links;
	}
}
