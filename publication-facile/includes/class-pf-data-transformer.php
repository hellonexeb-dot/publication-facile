<?php
/**
 * Transformation des données Elementor (_elementor_data).
 *
 * Classe volontairement indépendante de WordPress : elle reçoit un tableau
 * d'éléments Elementor et renvoie un nouveau tableau, sans jamais modifier
 * l'original. Elle peut ainsi être testée sans installer WordPress.
 *
 * @package PublicationFacile
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PF_TESTING' ) ) {
	exit;
}

/**
 * Erreur levée lorsqu'une transformation est impossible.
 */
class PF_Transform_Exception extends RuntimeException {}

/**
 * Clone un modèle Elementor et remplace uniquement les contenus ciblés.
 */
final class PF_Data_Transformer {

	/**
	 * Type de widget attendu pour chaque rôle.
	 *
	 * @var array<string,string>
	 */
	const ROLE_WIDGETS = array(
		'title'   => 'heading',
		'summary' => 'text-editor',
		'body'    => 'text-editor',
		'image'   => 'image',
	);

	/**
	 * Libellés français des rôles (messages d'erreur).
	 *
	 * @var array<string,string>
	 */
	const ROLE_LABELS = array(
		'title'   => 'Titre',
		'summary' => 'Résumé',
		'body'    => 'Texte',
		'image'   => 'Image principale',
	);

	/**
	 * Générateur d'identifiants (remplaçable pour les tests).
	 *
	 * @var callable
	 */
	private $id_generator;

	/**
	 * Constructeur.
	 *
	 * @param callable|null $id_generator Fonction renvoyant un identifiant.
	 */
	public function __construct( $id_generator = null ) {
		$this->id_generator = $id_generator ? $id_generator : array( __CLASS__, 'random_id' );
	}

	/**
	 * Identifiant hexadécimal de 7 caractères, au format Elementor.
	 *
	 * @return string
	 */
	public static function random_id() {
		return substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	}

	/**
	 * Clone un modèle : nouveaux identifiants, références réécrites, contenus remplacés.
	 *
	 * @param array $elements    Éléments du modèle.
	 * @param array $mapping     Rôle => identifiant de l'élément dans le modèle.
	 * @param array $values      Rôle => valeur (voir apply_values()).
	 * @param int   $old_post_id ID du modèle (pour réécrire .elementor-123).
	 * @param int   $new_post_id ID du nouvel article.
	 * @return array{elements: array, id_map: array, mapping: array}
	 * @throws PF_Transform_Exception Si le mappage est invalide.
	 */
	public function clone_template( array $elements, array $mapping, array $values, $old_post_id = 0, $new_post_id = 0 ) {
		self::assert_mapping( $elements, $mapping );

		$used   = array_fill_keys( self::collect_ids( $elements ), true );
		$id_map = array();
		$cloned = $this->assign_new_ids( $elements, $id_map, $used );

		$new_mapping = array();
		foreach ( $mapping as $role => $old_id ) {
			if ( '' !== (string) $old_id && isset( $id_map[ $old_id ] ) ) {
				$new_mapping[ $role ] = $id_map[ $old_id ];
			}
		}

		$cloned = self::rewrite_references( $cloned, $id_map, (int) $old_post_id, (int) $new_post_id );
		$cloned = self::apply_values( $cloned, $new_mapping, $values );

		self::assert_unique_ids( $cloned );

		return array(
			'elements' => $cloned,
			'id_map'   => $id_map,
			'mapping'  => $new_mapping,
		);
	}

	/**
	 * Met à jour un article déjà créé (identifiants conservés).
	 *
	 * @param array $elements Éléments de l'article.
	 * @param array $mapping  Rôle => identifiant dans l'article.
	 * @param array $values   Rôle => valeur.
	 * @return array
	 * @throws PF_Transform_Exception Si le mappage est invalide.
	 */
	public static function update_contents( array $elements, array $mapping, array $values ) {
		self::assert_mapping( $elements, $mapping );
		return self::apply_values( $elements, $mapping, $values );
	}

	/**
	 * Vérifie que chaque élément mappé existe et a le bon type de widget.
	 *
	 * @param array $elements Éléments.
	 * @param array $mapping  Rôle => identifiant.
	 * @throws PF_Transform_Exception Si un élément manque ou n'a pas le bon type.
	 */
	public static function assert_mapping( array $elements, array $mapping ) {
		$errors = self::mapping_errors( $elements, $mapping );
		if ( $errors ) {
			throw new PF_Transform_Exception( implode( ' ', $errors ) );
		}
	}

	/**
	 * Liste les problèmes de mappage (vide si tout va bien).
	 *
	 * @param array $elements Éléments.
	 * @param array $mapping  Rôle => identifiant.
	 * @return string[]
	 */
	public static function mapping_errors( array $elements, array $mapping ) {
		$errors = array();
		foreach ( $mapping as $role => $id ) {
			if ( '' === (string) $id || ! isset( self::ROLE_WIDGETS[ $role ] ) ) {
				continue;
			}
			$label   = self::ROLE_LABELS[ $role ];
			$element = self::find_element( $elements, $id );
			if ( null === $element ) {
				$errors[] = sprintf( 'Le widget « %s » (%s) est introuvable : a-t-il été supprimé dans Elementor ?', $label, $id );
				continue;
			}
			$type = isset( $element['widgetType'] ) ? $element['widgetType'] : '';
			if ( self::ROLE_WIDGETS[ $role ] !== $type ) {
				$errors[] = sprintf( 'Le widget « %s » (%s) est de type « %s » au lieu de « %s ».', $label, $id, $type, self::ROLE_WIDGETS[ $role ] );
			}
		}
		return $errors;
	}

	/**
	 * Attribue récursivement un nouvel identifiant unique à chaque élément.
	 *
	 * @param array $elements Éléments.
	 * @param array $id_map   Table ancien => nouveau (complétée).
	 * @param array $used     Identifiants déjà utilisés (complétée).
	 * @return array
	 * @throws PF_Transform_Exception Si aucun identifiant libre n'est trouvé.
	 */
	private function assign_new_ids( array $elements, array &$id_map, array &$used ) {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['id'] ) ) {
				$tries = 0;
				do {
					$new_id = (string) call_user_func( $this->id_generator );
					if ( ++$tries > 1000 ) {
						throw new PF_Transform_Exception( 'Impossible de générer un identifiant Elementor unique.' );
					}
				} while ( '' === $new_id || isset( $used[ $new_id ] ) );

				$used[ $new_id ]                   = true;
				$id_map[ (string) $element['id'] ] = $new_id;
				$element['id']                     = $new_id;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = $this->assign_new_ids( $element['elements'], $id_map, $used );
			}
			$elements[ $index ] = $element;
		}
		return $elements;
	}

	/**
	 * Réécrit, dans tous les réglages texte, les références aux anciens identifiants.
	 *
	 * Exemple : le widget HTML « CSS article » qui cible .elementor-element-7dc595e.
	 *
	 * @param array $elements    Éléments.
	 * @param array $id_map      Table ancien => nouveau.
	 * @param int   $old_post_id Ancien ID d'article.
	 * @param int   $new_post_id Nouvel ID d'article.
	 * @return array
	 */
	public static function rewrite_references( array $elements, array $id_map, $old_post_id = 0, $new_post_id = 0 ) {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = self::rewrite_value( $element['settings'], $id_map, $old_post_id, $new_post_id );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::rewrite_references( $element['elements'], $id_map, $old_post_id, $new_post_id );
			}
			$elements[ $index ] = $element;
		}
		return $elements;
	}

	/**
	 * Réécrit une valeur de réglage (récursif sur les tableaux).
	 *
	 * @param mixed $value       Valeur.
	 * @param array $id_map      Table ancien => nouveau.
	 * @param int   $old_post_id Ancien ID d'article.
	 * @param int   $new_post_id Nouvel ID d'article.
	 * @return mixed
	 */
	private static function rewrite_value( $value, array $id_map, $old_post_id, $new_post_id ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::rewrite_value( $item, $id_map, $old_post_id, $new_post_id );
			}
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( false !== strpos( $value, 'elementor-element-' ) ) {
			$value = preg_replace_callback(
				'/\belementor-element-([0-9a-zA-Z]+)\b/',
				function ( $m ) use ( $id_map ) {
					return isset( $id_map[ $m[1] ] ) ? 'elementor-element-' . $id_map[ $m[1] ] : $m[0];
				},
				$value
			);
		}

		if ( false !== strpos( $value, 'data-id=' ) ) {
			$value = preg_replace_callback(
				'/data-id=(["\'])([0-9a-zA-Z]+)\1/',
				function ( $m ) use ( $id_map ) {
					return isset( $id_map[ $m[2] ] ) ? 'data-id=' . $m[1] . $id_map[ $m[2] ] . $m[1] : $m[0];
				},
				$value
			);
		}

		if ( $old_post_id > 0 && $new_post_id > 0 && false !== strpos( $value, (string) $old_post_id ) ) {
			$value = preg_replace(
				'/\b(elementor-page-|elementor-|postid-)' . $old_post_id . '\b/',
				'${1}' . $new_post_id,
				$value
			);
		}

		return $value;
	}

	/**
	 * Applique les valeurs aux éléments mappés.
	 *
	 * Valeurs reconnues :
	 * - title (texte brut, rendu d'une seule couleur)
	 * - summary (HTML), body (HTML)
	 * - image (array : id, url, alt)
	 * Un rôle absent de $values laisse le widget inchangé.
	 *
	 * @param array $elements Éléments.
	 * @param array $mapping  Rôle => identifiant.
	 * @param array $values   Valeurs.
	 * @return array
	 */
	public static function apply_values( array $elements, array $mapping, array $values ) {
		$by_id = array();
		foreach ( $mapping as $role => $id ) {
			if ( '' !== (string) $id && array_key_exists( $role, $values ) ) {
				$by_id[ (string) $id ] = $role;
			}
		}
		if ( ! $by_id ) {
			return $elements;
		}
		return self::apply_values_recursive( $elements, $by_id, $values );
	}

	/**
	 * Parcours récursif pour apply_values().
	 *
	 * @param array $elements Éléments.
	 * @param array $by_id    Identifiant => rôle.
	 * @param array $values   Valeurs.
	 * @return array
	 */
	private static function apply_values_recursive( array $elements, array $by_id, array $values ) {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$id = isset( $element['id'] ) ? (string) $element['id'] : '';
			if ( '' !== $id && isset( $by_id[ $id ] ) ) {
				$element = self::apply_role( $element, $by_id[ $id ], $values );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::apply_values_recursive( $element['elements'], $by_id, $values );
			}
			$elements[ $index ] = $element;
		}
		return $elements;
	}

	/**
	 * Remplace le contenu d'un élément selon son rôle (et rien d'autre).
	 *
	 * @param array  $element Élément.
	 * @param string $role    Rôle.
	 * @param array  $values  Valeurs.
	 * @return array
	 */
	private static function apply_role( array $element, $role, array $values ) {
		if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
			$element['settings'] = array();
		}

		switch ( $role ) {
			case 'title':
				// Titre d'une seule couleur : texte simple, la couleur vient du style du widget.
				$element['settings']['title'] = self::esc_text( (string) $values['title'] );
				break;

			case 'summary':
			case 'body':
				$element['settings']['editor'] = (string) $values[ $role ];
				break;

			case 'image':
				$image = isset( $element['settings']['image'] ) && is_array( $element['settings']['image'] )
					? $element['settings']['image']
					: array();
				$new   = (array) $values['image'];

				$image['id']     = isset( $new['id'] ) ? (int) $new['id'] : 0;
				$image['url']    = isset( $new['url'] ) ? (string) $new['url'] : '';
				$image['alt']    = isset( $new['alt'] ) ? (string) $new['alt'] : '';
				$image['source'] = 'library';

				$element['settings']['image'] = $image;
				break;
		}

		return $element;
	}

	/**
	 * Texte brut d'un titre (les éventuels <br> et <span> colorés sont aplatis).
	 *
	 * @param string $html Contenu du widget titre.
	 * @return string
	 */
	public static function heading_text( $html ) {
		return self::plain_text( $html );
	}

	/**
	 * Échappe un texte destiné à un nœud HTML (les apostrophes restent lisibles).
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	public static function esc_text( $text ) {
		return htmlspecialchars( (string) $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Convertit un fragment HTML en texte brut.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function plain_text( $html ) {
		$text = preg_replace( '/<br\s*\/?>/i', ' ', (string) $html );
		$text = strip_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Recherche un élément par identifiant.
	 *
	 * @param array  $elements Éléments.
	 * @param string $id       Identifiant.
	 * @return array|null
	 */
	public static function find_element( array $elements, $id ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['id'] ) && (string) $element['id'] === (string) $id ) {
				return $element;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = self::find_element( $element['elements'], $id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Liste tous les identifiants (ordre du document).
	 *
	 * @param array $elements Éléments.
	 * @return string[]
	 */
	public static function collect_ids( array $elements ) {
		$ids = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['id'] ) ) {
				$ids[] = (string) $element['id'];
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$ids = array_merge( $ids, self::collect_ids( $element['elements'] ) );
			}
		}
		return $ids;
	}

	/**
	 * Aplatit l'arbre en une liste de nœuds avec leur chemin lisible.
	 *
	 * @param array    $elements Éléments.
	 * @param string[] $path     Chemin parent.
	 * @return array<int, array{id: string, elType: string, widgetType: string, settings: array, path: string[], top: string}>
	 */
	public static function flatten( array $elements, array $path = array(), $top = '' ) {
		$list = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['id'] ) ) {
				continue;
			}
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$el_type  = isset( $element['elType'] ) ? (string) $element['elType'] : '';
			$widget   = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';
			$label    = ! empty( $settings['_title'] ) ? (string) $settings['_title'] : ( 'widget' === $el_type ? $widget : 'Conteneur' );
			$node_top = '' === $top ? (string) $element['id'] : $top;

			$list[] = array(
				'id'         => (string) $element['id'],
				'elType'     => $el_type,
				'widgetType' => $widget,
				'settings'   => $settings,
				'path'       => array_merge( $path, array( $label ) ),
				'top'        => $node_top,
			);

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$list = array_merge( $list, self::flatten( $element['elements'], array_merge( $path, array( $label ) ), $node_top ) );
			}
		}
		return $list;
	}

	/**
	 * Propose un mappage automatique.
	 *
	 * Titre : premier titre H1 (sinon premier titre).
	 * Texte : éditeur de texte le plus long.
	 * Résumé : autre éditeur de texte du même bloc principal que le titre.
	 * Image : première image non SVG du bloc du titre (sinon première image non SVG).
	 *
	 * @param array $elements Éléments.
	 * @return array{title: string, summary: string, body: string, image: string}
	 */
	public static function suggest_mapping( array $elements ) {
		$nodes   = self::flatten( $elements );
		$mapping = array(
			'title'   => '',
			'summary' => '',
			'body'    => '',
			'image'   => '',
		);

		$title_top = '';
		foreach ( $nodes as $node ) {
			if ( 'heading' === $node['widgetType'] && isset( $node['settings']['header_size'] ) && 'h1' === $node['settings']['header_size'] ) {
				$mapping['title'] = $node['id'];
				$title_top        = $node['top'];
				break;
			}
		}
		if ( '' === $mapping['title'] ) {
			foreach ( $nodes as $node ) {
				if ( 'heading' === $node['widgetType'] ) {
					$mapping['title'] = $node['id'];
					$title_top        = $node['top'];
					break;
				}
			}
		}

		$longest = -1;
		foreach ( $nodes as $node ) {
			if ( 'text-editor' !== $node['widgetType'] ) {
				continue;
			}
			$length = strlen( self::plain_text( isset( $node['settings']['editor'] ) ? $node['settings']['editor'] : '' ) );
			if ( $length > $longest ) {
				$longest         = $length;
				$mapping['body'] = $node['id'];
			}
		}

		foreach ( $nodes as $node ) {
			if ( 'text-editor' === $node['widgetType'] && $node['id'] !== $mapping['body'] && '' !== $title_top && $node['top'] === $title_top ) {
				$mapping['summary'] = $node['id'];
				break;
			}
		}

		$first_image = '';
		foreach ( $nodes as $node ) {
			if ( 'image' !== $node['widgetType'] ) {
				continue;
			}
			$url = isset( $node['settings']['image']['url'] ) ? (string) $node['settings']['image']['url'] : '';
			if ( preg_match( '/\.svg(\?.*)?$/i', $url ) ) {
				continue;
			}
			if ( '' === $first_image ) {
				$first_image = $node['id'];
			}
			if ( '' !== $title_top && $node['top'] === $title_top ) {
				$mapping['image'] = $node['id'];
				break;
			}
		}
		if ( '' === $mapping['image'] ) {
			$mapping['image'] = $first_image;
		}

		return $mapping;
	}

	/**
	 * Vérifie l'absence de doublons d'identifiants.
	 *
	 * @param array $elements Éléments.
	 * @throws PF_Transform_Exception En cas de doublon.
	 */
	public static function assert_unique_ids( array $elements ) {
		$ids = self::collect_ids( $elements );
		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			throw new PF_Transform_Exception( 'Identifiants Elementor en double après duplication.' );
		}
	}
}
