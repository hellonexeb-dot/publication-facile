<?php
/**
 * Lecture et analyse du modèle Elementor (côté WordPress).
 *
 * @package PublicationFacile
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inspecteur du modèle.
 */
final class PF_Template_Inspector {

	/**
	 * Libellés des rôles.
	 *
	 * @return array<string,string>
	 */
	public static function role_labels() {
		return array(
			'title'   => __( 'Titre', 'publication-facile' ),
			'summary' => __( 'Résumé (chapô)', 'publication-facile' ),
			'body'    => __( 'Texte de l’article', 'publication-facile' ),
			'image'   => __( 'Image principale', 'publication-facile' ),
		);
	}

	/**
	 * Rôles obligatoires.
	 *
	 * @return string[]
	 */
	public static function required_roles() {
		return array( 'title', 'body', 'image' );
	}

	/**
	 * Indique si un contenu est construit avec Elementor.
	 *
	 * @param int $post_id ID.
	 * @return bool
	 */
	public static function is_elementor_post( $post_id ) {
		return 'builder' === get_post_meta( (int) $post_id, '_elementor_edit_mode', true )
			&& '' !== (string) get_post_meta( (int) $post_id, '_elementor_data', true );
	}

	/**
	 * Lit les éléments Elementor d'un contenu.
	 *
	 * @param int $post_id ID.
	 * @return array|WP_Error
	 */
	public static function get_elements( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'pf_no_post', __( 'Le contenu demandé est introuvable.', 'publication-facile' ) );
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $raw ) ) {
			$data = json_decode( $raw, true );
		} else {
			$data = $raw;
		}

		if ( ! is_array( $data ) || ! $data ) {
			return new WP_Error( 'pf_bad_data', __( 'Les données Elementor de ce contenu sont vides ou illisibles.', 'publication-facile' ) );
		}
		return $data;
	}

	/**
	 * Liste des articles Elementor pouvant servir de modèle.
	 *
	 * @return WP_Post[]
	 */
	public static function candidate_templates() {
		return get_posts(
			array(
				'post_type'        => 'post', // Seuls les articles servent de modèle.
				'post_status'      => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page'   => 200,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				// Les articles écrits avec l'extension sont aussi proposés.
				'meta_query'       => array(
					array(
						'key'   => '_elementor_edit_mode',
						'value' => 'builder',
					),
				),
			)
		);
	}

	/**
	 * Widgets d'un type donné, avec libellé lisible pour un menu déroulant.
	 *
	 * @param array  $elements    Éléments.
	 * @param string $widget_type Type de widget.
	 * @return array<string,string> identifiant => libellé
	 */
	public static function widget_options( array $elements, $widget_type ) {
		$options = array();
		foreach ( PF_Data_Transformer::flatten( $elements ) as $node ) {
			if ( $node['widgetType'] !== $widget_type ) {
				continue;
			}
			$options[ $node['id'] ] = sprintf(
				'%s — « %s » [%s]',
				implode( ' › ', $node['path'] ),
				self::excerpt( $node ),
				$node['id']
			);
		}
		return $options;
	}

	/**
	 * Extrait lisible du contenu d'un widget.
	 *
	 * @param array $node Nœud aplati.
	 * @return string
	 */
	public static function excerpt( array $node ) {
		$s = $node['settings'];
		switch ( $node['widgetType'] ) {
			case 'heading':
				$text = isset( $s['title'] ) ? $s['title'] : '';
				break;
			case 'text-editor':
				$text = isset( $s['editor'] ) ? $s['editor'] : '';
				break;
			case 'image':
				$text = isset( $s['image']['url'] ) ? wp_basename( $s['image']['url'] ) : '';
				break;
			default:
				$text = '';
		}
		$text = PF_Data_Transformer::plain_text( $text );
		if ( '' === $text ) {
			return __( 'vide', 'publication-facile' );
		}
		return wp_html_excerpt( $text, 55, '…' );
	}

	/**
	 * Vérifie qu'un modèle permet de créer un article.
	 *
	 * @param array $config Configuration du modèle (template_id, mapping…).
	 * @return string[] Messages d'erreur (vide si tout va bien).
	 */
	public static function validate_template( array $config ) {
		$errors = array();

		if ( ! PF_Plugin::elementor_active() ) {
			$errors[] = __( 'Elementor n’est pas activé.', 'publication-facile' );
			return $errors;
		}

		if ( empty( $config['template_id'] ) ) {
			$errors[] = __( 'Aucun article modèle n’est choisi.', 'publication-facile' );
			return $errors;
		}

		if ( ! get_post( $config['template_id'] ) || 'trash' === get_post_status( $config['template_id'] ) ) {
			$errors[] = __( 'Le contenu modèle a été supprimé ou mis à la corbeille.', 'publication-facile' );
			return $errors;
		}

		if ( 'post' !== get_post_type( $config['template_id'] ) ) {
			$errors[] = __( 'Seuls les articles peuvent servir de modèle : retirez ce contenu des modèles.', 'publication-facile' );
			return $errors;
		}

		$elements = self::get_elements( $config['template_id'] );
		if ( is_wp_error( $elements ) ) {
			$errors[] = $elements->get_error_message();
			return $errors;
		}

		$labels = self::role_labels();
		foreach ( self::required_roles() as $role ) {
			if ( empty( $config['mapping'][ $role ] ) ) {
				/* translators: %s: rôle */
				$errors[] = sprintf( __( 'Le widget « %s » n’est pas choisi.', 'publication-facile' ), $labels[ $role ] );
			}
		}

		$mapping = isset( $config['mapping'] ) ? (array) $config['mapping'] : array();
		foreach ( PF_Data_Transformer::mapping_errors( $elements, $mapping ) as $message ) {
			$errors[] = $message;
		}

		return $errors;
	}
}
