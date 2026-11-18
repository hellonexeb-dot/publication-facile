<?php
/**
 * Création et mise à jour des articles.
 *
 * @package PublicationFacile
 */

defined( 'ABSPATH' ) || exit;

/**
 * Construit un article à partir du modèle Elementor.
 */
final class PF_Post_Builder {

	/**
	 * Métas copiées du modèle vers le nouvel article.
	 *
	 * Les métas de cache (_elementor_css, _elementor_element_cache,
	 * _elementor_page_assets) ne sont volontairement pas copiées :
	 * Elementor les régénère pour le nouvel article.
	 *
	 * @var string[]
	 */
	const COPIED_META = array(
		'_elementor_edit_mode',
		'_elementor_template_type',
		'_elementor_version',
		'_elementor_pro_version',
		'_elementor_page_settings',
		'_wp_page_template',
	);

	const SOURCE_META = '_pf_source';

	/**
	 * Configuration du modèle utilisé (template_id, mapping, category_id).
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Avertissements non bloquants (ex. CSS non régénéré).
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Constructeur.
	 *
	 * @param array $settings Configuration du modèle (voir PF_Plugin::template_defaults()).
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Avertissements de la dernière opération.
	 *
	 * @return string[]
	 */
	public function get_warnings() {
		return $this->warnings;
	}

	/**
	 * Crée un nouvel article à partir du modèle.
	 *
	 * @param array  $fields Champs validés (title, summary, body, image_id, image_alt).
	 * @param string $status 'draft' ou 'publish'.
	 * @return int|WP_Error ID du nouvel article.
	 */
	public function create( array $fields, $status ) {
		$template_id = (int) $this->settings['template_id'];
		$mapping     = $this->active_mapping();

		$elements = PF_Template_Inspector::get_elements( $template_id );
		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'     => 'post',
					'post_status'   => 'draft',
					'post_title'    => $fields['title'],
					'post_excerpt'  => $fields['summary_text'],
					'post_content'  => $this->plain_content( $fields ),
					'post_author'   => get_current_user_id(),
					'post_category' => $this->settings['category_id'] ? array( (int) $this->settings['category_id'] ) : array(),
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'pf_insert', __( 'WordPress n’a pas pu créer l’article.', 'publication-facile' ) . ' ' . $post_id->get_error_message() );
		}
		$post_id = (int) $post_id;

		// Garde-fou : on n'écrit jamais dans un modèle.
		if ( $post_id === $template_id || PF_Plugin::is_template( $post_id ) ) {
			return new WP_Error( 'pf_guard', __( 'Opération annulée pour protéger le modèle.', 'publication-facile' ) );
		}

		try {
			$transformer = new PF_Data_Transformer();
			$result      = $transformer->clone_template( $elements, $mapping, $this->values( $fields ), $template_id, $post_id );

			$this->copy_meta( $template_id, $post_id );
			$this->write_elementor_data( $post_id, $result['elements'] );

			update_post_meta(
				$post_id,
				self::SOURCE_META,
				array(
					'template_id' => $template_id,
					'mapping'     => $result['mapping'],
					'version'     => PF_VERSION,
					'created'     => time(),
				)
			);

			$this->set_image( $post_id, $fields );
			$this->finalize( $post_id, $status );
		} catch ( Throwable $e ) {
			// Retour en arrière : pas d'article à moitié créé.
			wp_delete_post( $post_id, true );
			$this->log( $e );
			return new WP_Error( 'pf_failed', __( 'La création de l’article a échoué, rien n’a été enregistré.', 'publication-facile' ) . ' ' . $e->getMessage() );
		}

		return $post_id;
	}

	/**
	 * Met à jour un article créé par l'extension (seuls les widgets ciblés changent).
	 *
	 * @param int    $post_id ID.
	 * @param array  $fields  Champs validés.
	 * @param string $status  'draft', 'publish' ou '' (statut inchangé).
	 * @return int|WP_Error
	 */
	public function update( $post_id, array $fields, $status ) {
		$post_id = (int) $post_id;
		$source  = self::get_source( $post_id );
		if ( ! $source ) {
			return new WP_Error( 'pf_not_ours', __( 'Cet article n’a pas été créé avec Publication Facile.', 'publication-facile' ) );
		}
		if ( $post_id === (int) $this->settings['template_id'] || PF_Plugin::is_template( $post_id ) ) {
			return new WP_Error( 'pf_guard', __( 'Opération annulée pour protéger le modèle.', 'publication-facile' ) );
		}

		$elements = PF_Template_Inspector::get_elements( $post_id );
		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		try {
			$updated = PF_Data_Transformer::update_contents( $elements, $source['mapping'], $this->values( $fields, $source['mapping'] ) );
		} catch ( Throwable $e ) {
			$this->log( $e );
			return new WP_Error( 'pf_failed', __( 'Impossible de mettre à jour cet article : sa structure a été modifiée dans Elementor.', 'publication-facile' ) . ' ' . $e->getMessage() );
		}

		$this->write_elementor_data( $post_id, $updated );

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_title'   => $fields['title'],
					'post_excerpt' => $fields['summary_text'],
					'post_content' => $this->plain_content( $fields ),
				)
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->set_image( $post_id, $fields );
		$this->finalize( $post_id, $status );

		return $post_id;
	}

	/**
	 * Métadonnées d'origine d'un article créé par l'extension.
	 *
	 * @param int $post_id ID.
	 * @return array|null
	 */
	public static function get_source( $post_id ) {
		$source = get_post_meta( (int) $post_id, self::SOURCE_META, true );
		if ( ! is_array( $source ) || empty( $source['mapping'] ) || ! is_array( $source['mapping'] ) ) {
			return null;
		}
		return $source;
	}

	/**
	 * Lit les contenus actuels d'un article (pour préremplir le formulaire).
	 *
	 * @param int $post_id ID.
	 * @return array|WP_Error
	 */
	public static function read_fields( $post_id ) {
		$source = self::get_source( $post_id );
		if ( ! $source ) {
			return new WP_Error( 'pf_not_ours', __( 'Cet article n’a pas été créé avec Publication Facile.', 'publication-facile' ) );
		}
		$elements = PF_Template_Inspector::get_elements( $post_id );
		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		$fields = array(
			'title'    => get_the_title( $post_id ),
			'summary'  => '',
			'body'     => '',
			'image_id' => (int) get_post_thumbnail_id( $post_id ),
		);
		$map    = $source['mapping'];

		if ( ! empty( $map['title'] ) ) {
			$el = PF_Data_Transformer::find_element( $elements, $map['title'] );
			if ( $el && isset( $el['settings']['title'] ) ) {
				$fields['title'] = PF_Data_Transformer::heading_text( $el['settings']['title'] );
			}
		}
		if ( ! empty( $map['summary'] ) ) {
			$el = PF_Data_Transformer::find_element( $elements, $map['summary'] );
			if ( $el && isset( $el['settings']['editor'] ) ) {
				$text              = preg_replace( '#<br\s*/?>|</p>\s*<p[^>]*>#i', "\n", $el['settings']['editor'] );
				$fields['summary'] = trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
		}
		if ( ! empty( $map['body'] ) ) {
			$el = PF_Data_Transformer::find_element( $elements, $map['body'] );
			if ( $el && isset( $el['settings']['editor'] ) ) {
				$fields['body'] = $el['settings']['editor'];
			}
		}
		if ( ! empty( $map['image'] ) && ! $fields['image_id'] ) {
			$el = PF_Data_Transformer::find_element( $elements, $map['image'] );
			if ( $el && ! empty( $el['settings']['image']['id'] ) ) {
				$fields['image_id'] = (int) $el['settings']['image']['id'];
			}
		}
		$fields['image_alt'] = $fields['image_id'] ? (string) get_post_meta( $fields['image_id'], '_wp_attachment_image_alt', true ) : '';

		return $fields;
	}

	/**
	 * Mappage du modèle, limité aux rôles renseignés.
	 *
	 * @return array
	 */
	private function active_mapping() {
		return array_filter( (array) $this->settings['mapping'], 'strlen' );
	}

	/**
	 * Valeurs à injecter dans les widgets.
	 *
	 * @param array      $fields  Champs.
	 * @param array|null $mapping Mappage (par défaut celui des réglages).
	 * @return array
	 */
	private function values( array $fields, $mapping = null ) {
		$mapping = null === $mapping ? $this->active_mapping() : $mapping;
		$values  = array(
			'title' => $fields['title'],
			'body'  => $fields['body'],
			'image' => array(
				'id'  => (int) $fields['image_id'],
				'url' => (string) wp_get_attachment_url( (int) $fields['image_id'] ),
				'alt' => $fields['image_alt'],
			),
		);
		if ( ! empty( $mapping['summary'] ) ) {
			$values['summary'] = $fields['summary'];
		}
		return $values;
	}

	/**
	 * Copie les métas Elementor utiles du modèle.
	 *
	 * @param int $from Modèle.
	 * @param int $to   Nouvel article.
	 */
	private function copy_meta( $from, $to ) {
		foreach ( self::COPIED_META as $key ) {
			if ( ! metadata_exists( 'post', $from, $key ) ) {
				continue;
			}
			$value = get_post_meta( $from, $key, true );
			update_post_meta( $to, $key, wp_slash( $value ) );
		}
		// Au cas où le modèle n'aurait pas ces métas (anciennes versions).
		if ( ! get_post_meta( $to, '_elementor_edit_mode', true ) ) {
			update_post_meta( $to, '_elementor_edit_mode', 'builder' );
		}
		if ( ! get_post_meta( $to, '_elementor_template_type', true ) ) {
			update_post_meta( $to, '_elementor_template_type', 'wp-post' );
		}
	}

	/**
	 * Écrit _elementor_data comme le fait Elementor lui-même.
	 *
	 * On n'utilise pas Document::save() : il reconstruit chaque élément via les
	 * widgets enregistrés et supprimerait silencieusement ceux d'une extension
	 * tierce momentanément désactivée.
	 *
	 * @param int   $post_id  ID.
	 * @param array $elements Éléments.
	 * @throws RuntimeException Si l'encodage ou l'écriture échoue.
	 */
	private function write_elementor_data( $post_id, array $elements ) {
		$json = wp_json_encode( $elements );
		if ( false === $json ) {
			throw new RuntimeException( __( 'Encodage des données Elementor impossible.', 'publication-facile' ) );
		}

		// wp_slash : update_metadata() retire les antislashs, ce qui casserait le JSON.
		update_metadata( 'post', $post_id, '_elementor_data', wp_slash( $json ) );

		$check = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $check ) || null === json_decode( $check, true ) ) {
			throw new RuntimeException( __( 'Les données Elementor n’ont pas été enregistrées correctement.', 'publication-facile' ) );
		}

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}

		// Caches Elementor à reconstruire.
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );
		delete_post_meta( $post_id, '_elementor_page_assets' );
	}

	/**
	 * Image mise en avant et texte alternatif.
	 *
	 * @param int   $post_id ID.
	 * @param array $fields  Champs.
	 */
	private function set_image( $post_id, array $fields ) {
		$image_id = (int) $fields['image_id'];
		if ( ! $image_id ) {
			return;
		}
		set_post_thumbnail( $post_id, $image_id );
		if ( '' !== $fields['image_alt'] && current_user_can( 'edit_post', $image_id ) ) {
			update_post_meta( $image_id, '_wp_attachment_image_alt', wp_slash( $fields['image_alt'] ) );
		}
	}

	/**
	 * Statut, CSS Elementor et caches.
	 *
	 * @param int    $post_id ID.
	 * @param string $status  'draft', 'publish' ou ''.
	 */
	private function finalize( $post_id, $status ) {
		if ( 'publish' === $status && 'publish' !== get_post_status( $post_id ) && current_user_can( 'publish_post', $post_id ) ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);
		} elseif ( 'draft' === $status && 'publish' === get_post_status( $post_id ) ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
		}

		$this->regenerate_css( $post_id );

		clean_post_cache( $post_id );
		// Caches de page courants (sans effet si l'extension n'est pas installée).
		do_action( 'litespeed_purge_post', $post_id );
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		do_action( 'pf_after_save', $post_id, $status );
	}

	/**
	 * Régénère le fichier CSS Elementor de l'article.
	 *
	 * @param int $post_id ID.
	 */
	private function regenerate_css( $post_id ) {
		if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			return;
		}
		try {
			$css = \Elementor\Core\Files\CSS\Post::create( $post_id );
			$css->update();
		} catch ( Throwable $e ) {
			$this->log( $e );
			$this->warnings[] = __( 'Le fichier de style Elementor n’a pas pu être généré tout de suite ; il le sera à la première visite de l’article.', 'publication-facile' );
		}
	}

	/**
	 * Version texte de l'article (recherche, flux RSS, référencement).
	 *
	 * @param array $fields Champs.
	 * @return string
	 */
	private function plain_content( array $fields ) {
		$parts = array();
		if ( '' !== $fields['summary'] ) {
			$parts[] = $fields['summary'];
		}
		$parts[] = $fields['body'];
		return wp_kses_post( implode( "\n", $parts ) );
	}

	/**
	 * Journalisation (si WP_DEBUG_LOG).
	 *
	 * @param Throwable $e Erreur.
	 */
	private function log( $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Publication Facile] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
		}
	}
}
