<?php
/**
 * Page de réglages : liste des modèles et choix des widgets.
 *
 * @package PublicationFacile
 */

defined( 'ABSPATH' ) || exit;

/**
 * Réglages (administrateur).
 */
final class PF_Settings {

	const PAGE  = 'publication-facile';
	const GROUP = 'pf_settings_group';

	/**
	 * Constructeur.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Entrée de menu sous Réglages.
	 */
	public function menu() {
		add_options_page(
			__( 'Publication Facile', 'publication-facile' ),
			__( 'Publication Facile', 'publication-facile' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Enregistrement de l'option.
	 */
	public function register() {
		register_setting(
			self::GROUP,
			PF_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => PF_Plugin::defaults(),
			)
		);
	}

	/**
	 * Feuilles de style et scripts.
	 *
	 * @param string $hook Écran courant.
	 */
	public function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'pf-admin', PF_URL . 'assets/css/admin.css', array(), PF_VERSION );
		wp_enqueue_script( 'pf-settings', PF_URL . 'assets/js/settings.js', array(), PF_VERSION, true );
	}

	/**
	 * Nettoyage des réglages envoyés.
	 *
	 * Format reçu :
	 * - templates[n][template_id|label|category_id|mapping[rôle]|remove]
	 * - add_template_id : modèle à ajouter (widgets choisis automatiquement)
	 *
	 * @param mixed $input Données envoyées.
	 * @return array
	 */
	public function sanitize( $input ) {
		// Appel interne de WordPress avec une valeur déjà propre (ex. add_option).
		if ( is_array( $input ) && isset( $input['templates'] ) && ! isset( $input['_pf_form'] ) && ! isset( $input['add_template_id'] ) ) {
			$clean = array();
			foreach ( (array) $input['templates'] as $config ) {
				$config = PF_Plugin::normalize_template( $config );
				if ( $config['template_id'] ) {
					$clean[] = $config;
				}
			}
			return array( 'templates' => $clean );
		}

		$input     = is_array( $input ) ? $input : array();
		$templates = array();

		if ( isset( $input['templates'] ) && is_array( $input['templates'] ) ) {
			foreach ( $input['templates'] as $posted ) {
				if ( ! is_array( $posted ) || ! empty( $posted['remove'] ) ) {
					continue;
				}
				$config = $this->sanitize_template( $posted );
				if ( $config && ! isset( $templates[ $config['template_id'] ] ) ) {
					$templates[ $config['template_id'] ] = $config;
				}
			}
		}

		$add = isset( $input['add_template_id'] ) ? absint( $input['add_template_id'] ) : 0;
		if ( $add ) {
			if ( isset( $templates[ $add ] ) ) {
				add_settings_error( PF_Plugin::OPTION, 'pf_dup', __( 'Ce modèle est déjà dans la liste.', 'publication-facile' ) );
			} elseif ( 'post' !== get_post_type( $add ) ) {
				add_settings_error( PF_Plugin::OPTION, 'pf_not_post', __( 'Seuls les articles peuvent servir de modèle.', 'publication-facile' ) );
			} else {
				$config = $this->sanitize_template( array( 'template_id' => $add ) );
				if ( $config ) {
					$templates[ $add ] = $config;
					add_settings_error(
						PF_Plugin::OPTION,
						'pf_added',
						sprintf(
							/* translators: %s: nom du modèle */
							__( 'Modèle « %s » ajouté : ses widgets ont été choisis automatiquement. Vérifiez-les ci-dessous.', 'publication-facile' ),
							$config['label']
						),
						'info'
					);
				}
			}
		}

		return array( 'templates' => array_values( $templates ) );
	}

	/**
	 * Nettoie un modèle. Widgets absents ou invalides → proposition automatique.
	 *
	 * @param array $posted Données envoyées pour ce modèle.
	 * @return array|null
	 */
	private function sanitize_template( array $posted ) {
		$template_id = isset( $posted['template_id'] ) ? absint( $posted['template_id'] ) : 0;
		if ( ! $template_id ) {
			return null;
		}
		if ( ! PF_Template_Inspector::is_elementor_post( $template_id ) ) {
			add_settings_error(
				PF_Plugin::OPTION,
				'pf_template_' . $template_id,
				/* translators: %d: ID */
				sprintf( __( 'Le contenu #%d n’est pas construit avec Elementor : il ne peut pas servir de modèle.', 'publication-facile' ), $template_id )
			);
			return null;
		}

		$config                = PF_Plugin::template_defaults();
		$config['template_id'] = $template_id;

		$label           = isset( $posted['label'] ) ? sanitize_text_field( $posted['label'] ) : '';
		$config['label'] = '' !== $label ? $label : PF_Plugin::default_label( $template_id );

		if ( array_key_exists( 'category_id', $posted ) ) {
			$category_id           = absint( $posted['category_id'] );
			$config['category_id'] = ( $category_id && term_exists( $category_id, 'category' ) ) ? $category_id : 0;
		} else {
			$config['category_id'] = PF_Plugin::default_category();
		}

		$elements = PF_Template_Inspector::get_elements( $template_id );
		if ( is_wp_error( $elements ) ) {
			add_settings_error( PF_Plugin::OPTION, 'pf_data_' . $template_id, $elements->get_error_message() );
			$previous = PF_Plugin::get_template( $template_id );
			if ( $previous ) {
				$config['mapping'] = $previous['mapping'];
			}
			return $config;
		}

		if ( empty( $posted['mapping'] ) || ! is_array( $posted['mapping'] ) ) {
			$config['mapping'] = PF_Data_Transformer::suggest_mapping( $elements );
			return $config;
		}

		foreach ( PF_Data_Transformer::ROLE_WIDGETS as $role => $widget_type ) {
			$id = isset( $posted['mapping'][ $role ] ) ? sanitize_key( $posted['mapping'][ $role ] ) : '';
			if ( '' !== $id ) {
				$element = PF_Data_Transformer::find_element( $elements, $id );
				if ( ! $element || ! isset( $element['widgetType'] ) || $element['widgetType'] !== $widget_type ) {
					$id = '';
				}
			}
			$config['mapping'][ $role ] = $id;
		}

		if ( '' !== $config['mapping']['summary'] && $config['mapping']['summary'] === $config['mapping']['body'] ) {
			$config['mapping']['summary'] = '';
			add_settings_error( PF_Plugin::OPTION, 'pf_same_' . $template_id, __( 'Le résumé et le texte ne peuvent pas utiliser le même widget : le résumé a été désactivé.', 'publication-facile' ) );
		}

		return $config;
	}

	/**
	 * Affichage de la page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = PF_Plugin::get_settings();
		$candidates = PF_Template_Inspector::candidate_templates();
		$name       = PF_Plugin::OPTION;
		?>
		<div class="wrap pf-wrap">
			<h1><?php esc_html_e( 'Publication Facile — Réglages', 'publication-facile' ); ?></h1>
			<?php settings_errors( PF_Plugin::OPTION ); ?>

			<?php if ( ! PF_Plugin::elementor_active() ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Elementor doit être activé.', 'publication-facile' ); ?></p></div>
			<?php endif; ?>

			<p class="description pf-intro"><?php esc_html_e( 'Chaque modèle est un article Elementor dont la mise en page sera reprise à l’identique. Seuls les widgets choisis reçoivent le contenu du formulaire. Les modèles ne sont jamais modifiés. S’il y a plusieurs modèles, la rédactrice choisit le sien au moment d’écrire.', 'publication-facile' ); ?></p>

			<form method="post" action="options.php" id="pf-settings-form">
				<?php settings_fields( self::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[_pf_form]" value="1">

				<?php if ( ! $settings['templates'] ) : ?>
					<div class="pf-card"><p><?php esc_html_e( 'Aucun modèle pour l’instant : ajoutez-en un ci-dessous.', 'publication-facile' ); ?></p></div>
				<?php endif; ?>

				<?php
				$index = 0;
				foreach ( $settings['templates'] as $config ) {
					$this->render_template_card( $config, $index++ );
				}
				?>

				<div class="pf-card pf-card--add">
					<h2><?php esc_html_e( 'Ajouter un modèle', 'publication-facile' ); ?></h2>
					<label for="pf-add-template" class="pf-label"><?php esc_html_e( 'Article Elementor', 'publication-facile' ); ?></label>
					<select id="pf-add-template" name="<?php echo esc_attr( $name ); ?>[add_template_id]" class="pf-select" data-pf-autosubmit="1">
						<option value="0"><?php esc_html_e( '— Choisir un article à utiliser comme modèle —', 'publication-facile' ); ?></option>
						<?php foreach ( $candidates as $candidate ) : ?>
							<?php
							if ( isset( $settings['templates'][ $candidate->ID ] ) ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $candidate->ID ); ?>">
								<?php
								$status = get_post_status_object( $candidate->post_status );
								echo esc_html(
									sprintf(
										'%s (#%d, %s)',
										get_the_title( $candidate ) ? get_the_title( $candidate ) : __( '(sans titre)', 'publication-facile' ),
										$candidate->ID,
										$status ? $status->label : $candidate->post_status
									)
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Les widgets sont détectés automatiquement à l’ajout.', 'publication-facile' ); ?></p>
				</div>

				<?php submit_button( __( 'Enregistrer les réglages', 'publication-facile' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Carte de configuration d'un modèle.
	 *
	 * @param array $config Configuration.
	 * @param int   $index  Position dans le formulaire.
	 */
	private function render_template_card( array $config, $index ) {
		$name     = PF_Plugin::OPTION . '[templates][' . $index . ']';
		$prefix   = 'pf-t' . $config['template_id'] . '-';
		$labels   = PF_Template_Inspector::role_labels();
		$elements = PF_Template_Inspector::get_elements( $config['template_id'] );
		$errors   = PF_Template_Inspector::validate_template( $config );
		?>
		<div class="pf-card <?php echo $errors ? 'pf-card--error' : 'pf-card--ok'; ?>">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[template_id]" value="<?php echo esc_attr( $config['template_id'] ); ?>">

			<div class="pf-card__head">
				<h2>
					<?php echo esc_html( $config['label'] ); ?>
					<span class="pf-muted">— <?php echo esc_html( sprintf( '#%d', $config['template_id'] ) ); ?></span>
				</h2>
				<span>
					<a href="<?php echo esc_url( get_permalink( $config['template_id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Voir', 'publication-facile' ); ?></a>
					<?php if ( PF_Plugin::elementor_active() && get_post( $config['template_id'] ) ) : ?>
						· <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $config['template_id'] . '&action=elementor' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ouvrir dans Elementor', 'publication-facile' ); ?></a>
					<?php endif; ?>
				</span>
			</div>

			<div class="pf-grid">
				<div class="pf-field">
					<label for="<?php echo esc_attr( $prefix . 'label' ); ?>" class="pf-label"><?php esc_html_e( 'Nom affiché à la rédactrice', 'publication-facile' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $prefix . 'label' ); ?>" name="<?php echo esc_attr( $name ); ?>[label]" class="pf-input" value="<?php echo esc_attr( $config['label'] ); ?>" maxlength="80">
				</div>
				<div class="pf-field">
					<label for="<?php echo esc_attr( $prefix . 'category' ); ?>" class="pf-label"><?php esc_html_e( 'Catégorie des articles', 'publication-facile' ); ?></label>
					<?php
					wp_dropdown_categories(
						array(
							'name'              => $name . '[category_id]',
							'id'                => $prefix . 'category',
							'class'             => 'pf-select',
							'selected'          => $config['category_id'],
							'hide_empty'        => false,
							'show_option_none'  => __( '— Catégorie par défaut de WordPress —', 'publication-facile' ),
							'option_none_value' => 0,
							'hierarchical'      => true,
						)
					);
					?>
				</div>
			</div>

			<?php if ( is_array( $elements ) ) : ?>
				<h3 class="pf-subtitle"><?php esc_html_e( 'Widgets à remplacer', 'publication-facile' ); ?></h3>
				<?php
				foreach ( PF_Data_Transformer::ROLE_WIDGETS as $role => $widget_type ) :
					$options  = PF_Template_Inspector::widget_options( $elements, $widget_type );
					$required = in_array( $role, PF_Template_Inspector::required_roles(), true );
					$field_id = $prefix . 'map-' . $role;
					?>
					<div class="pf-field">
						<label for="<?php echo esc_attr( $field_id ); ?>" class="pf-label">
							<?php echo esc_html( $labels[ $role ] ); ?>
							<?php if ( ! $required ) : ?>
								<span class="pf-muted"><?php esc_html_e( '(facultatif)', 'publication-facile' ); ?></span>
							<?php endif; ?>
						</label>
						<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>[mapping][<?php echo esc_attr( $role ); ?>]" class="pf-select">
							<option value=""><?php echo $required ? esc_html__( '— Choisir —', 'publication-facile' ) : esc_html__( '— Ne pas utiliser —', 'publication-facile' ); ?></option>
							<?php foreach ( $options as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $config['mapping'][ $role ], $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<div class="pf-state">
				<?php if ( $errors ) : ?>
					<strong><?php esc_html_e( 'À corriger :', 'publication-facile' ); ?></strong>
					<ul class="pf-list">
						<?php foreach ( $errors as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<span class="pf-ok">✓ <?php esc_html_e( 'Modèle prêt.', 'publication-facile' ); ?></span>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => PF_Editor_Page::PAGE_NEW, 'template' => $config['template_id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Écrire un article avec ce modèle', 'publication-facile' ); ?></a>
				<?php endif; ?>
			</div>

			<label class="pf-remove">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[remove]" value="1">
				<?php esc_html_e( 'Retirer ce modèle de la liste (l’article lui-même et les articles déjà créés ne sont pas touchés)', 'publication-facile' ); ?>
			</label>
		</div>
		<?php
	}
}
