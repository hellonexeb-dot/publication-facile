<?php
/**
 * Formulaire simplifié « Nouvel article » et liste « Mes articles ».
 *
 * @package PublicationFacile
 */

defined( 'ABSPATH' ) || exit;

/**
 * Écrans de la rédactrice.
 */
final class PF_Editor_Page {

	const PAGE_NEW  = 'pf-nouvel-article';
	const PAGE_LIST = 'pf-mes-articles';
	const NONCE     = 'pf_save';
	const CAP       = 'edit_posts';

	/**
	 * Constructeur.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_pf_save', array( $this, 'handle_post' ) );
		add_action( 'wp_ajax_pf_preview', array( $this, 'handle_ajax_preview' ) );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
	}

	/**
	 * Menus.
	 */
	public function menu() {
		add_menu_page(
			__( 'Nouvel article', 'publication-facile' ),
			__( 'Nouvel article', 'publication-facile' ),
			self::CAP,
			self::PAGE_NEW,
			array( $this, 'render_form' ),
			'dashicons-welcome-write-blog',
			4
		);
		add_submenu_page(
			self::PAGE_NEW,
			__( 'Nouvel article', 'publication-facile' ),
			__( 'Écrire un article', 'publication-facile' ),
			self::CAP,
			self::PAGE_NEW,
			array( $this, 'render_form' )
		);
		add_submenu_page(
			self::PAGE_NEW,
			__( 'Mes articles', 'publication-facile' ),
			__( 'Mes articles', 'publication-facile' ),
			self::CAP,
			self::PAGE_LIST,
			array( $this, 'render_list' )
		);
	}

	/**
	 * Feuilles de style et scripts.
	 *
	 * @param string $hook Écran courant.
	 */
	public function assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE_NEW ) && false === strpos( $hook, self::PAGE_LIST ) ) {
			return;
		}
		wp_enqueue_style( 'pf-admin', PF_URL . 'assets/css/admin.css', array(), PF_VERSION );

		if ( false !== strpos( $hook, self::PAGE_LIST ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'pf-editor', PF_URL . 'assets/js/editor.js', array( 'jquery' ), PF_VERSION, true );
		wp_localize_script(
			'pf-editor',
			'pfEditor',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'chooseImage'  => __( 'Choisir l’image principale', 'publication-facile' ),
					'useImage'     => __( 'Utiliser cette image', 'publication-facile' ),
					'changeImage'  => __( 'Changer l’image', 'publication-facile' ),
					'previewWait'  => __( 'Préparation de l’aperçu…', 'publication-facile' ),
					'previewError' => __( 'L’aperçu n’a pas pu être préparé.', 'publication-facile' ),
					'missingTitle' => __( 'Merci d’indiquer un titre.', 'publication-facile' ),
					'missingBody'  => __( 'Merci d’écrire le texte de l’article.', 'publication-facile' ),
					'missingImage' => __( 'Merci de choisir une image.', 'publication-facile' ),
					'confirmPub'   => __( 'Publier cet article sur le site maintenant ?', 'publication-facile' ),
					'unsaved'      => __( 'Des modifications ne sont pas enregistrées.', 'publication-facile' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Traitement                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Envoi classique du formulaire (admin-post.php).
	 */
	public function handle_post() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Vous n’avez pas les droits nécessaires.', 'publication-facile' ), 403 );
		}
		check_admin_referer( self::NONCE, 'pf_nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nettoyé dans process().
		$data   = wp_unslash( $_POST );
		$result = $this->process( $data );
		$action = isset( $data['pf_action'] ) ? sanitize_key( $data['pf_action'] ) : '';

		$args = array( 'page' => self::PAGE_NEW );
		if ( is_wp_error( $result ) ) {
			set_transient( $this->transient_key( 'form' ), $this->raw_fields( $data ), 10 * MINUTE_IN_SECONDS );
			set_transient( $this->transient_key( 'error' ), $result->get_error_messages(), 10 * MINUTE_IN_SECONDS );
			$post_id = isset( $data['pf_post_id'] ) ? absint( $data['pf_post_id'] ) : 0;
			if ( $post_id ) {
				$args['post_id'] = $post_id;
			} elseif ( ! empty( $data['pf_template_id'] ) ) {
				$args['template'] = absint( $data['pf_template_id'] );
			}
			$args['pf_msg'] = 'error';
		} else {
			$args['post_id'] = $result['post_id'];
			$args['pf_msg']  = $result['message'];
			if ( 'preview' === $action ) {
				$args['pf_preview'] = 1;
			}
			if ( $result['warnings'] ) {
				set_transient( $this->transient_key( 'warning' ), $result['warnings'], 10 * MINUTE_IN_SECONDS );
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Aperçu en AJAX : enregistre le brouillon et renvoie le lien d'aperçu.
	 */
	public function handle_ajax_preview() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Vous n’avez pas les droits nécessaires.', 'publication-facile' ) ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'pf_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'La page a expiré. Rechargez-la puis réessayez (pensez à copier votre texte).', 'publication-facile' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nettoyé dans process().
		$data              = wp_unslash( $_POST );
		$data['pf_action'] = 'preview';
		$result            = $this->process( $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => implode( ' ', $result->get_error_messages() ) ) );
		}

		wp_send_json_success(
			array(
				'post_id'     => $result['post_id'],
				'preview_url' => get_preview_post_link( $result['post_id'] ),
				'edit_url'    => add_query_arg(
					array(
						'page'    => self::PAGE_NEW,
						'post_id' => $result['post_id'],
					),
					admin_url( 'admin.php' )
				),
			)
		);
	}

	/**
	 * Validation, nettoyage puis création ou mise à jour.
	 *
	 * @param array $data Données envoyées (non échappées).
	 * @return array|WP_Error { post_id, message, warnings }
	 */
	private function process( array $data ) {
		$action = isset( $data['pf_action'] ) ? sanitize_key( $data['pf_action'] ) : '';
		if ( ! in_array( $action, array( 'preview', 'draft', 'publish', 'update' ), true ) ) {
			return new WP_Error( 'pf_action', __( 'Action inconnue.', 'publication-facile' ) );
		}

		$post_id = isset( $data['pf_post_id'] ) ? absint( $data['pf_post_id'] ) : 0;
		if ( $post_id ) {
			if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'pf_cap', __( 'Vous ne pouvez pas modifier cet article.', 'publication-facile' ) );
			}
			if ( ! PF_Post_Builder::get_source( $post_id ) ) {
				return new WP_Error( 'pf_not_ours', __( 'Cet article n’a pas été créé avec Publication Facile.', 'publication-facile' ) );
			}
		}

		// Modèle : celui d'origine pour un article existant, sinon celui choisi.
		if ( $post_id && PF_Plugin::is_template( $post_id ) ) {
			// Article devenu modèle : le formulaire ne doit pas le modifier.
			echo '<h1>' . esc_html__( 'Modifier l’article', 'publication-facile' ) . '</h1>';
			echo '<div class="pf-card pf-card--error"><p><strong>' . esc_html__( 'Cet article sert de modèle : il ne se modifie pas avec le formulaire, pour protéger la mise en page des futurs articles.', 'publication-facile' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Pour le changer, utilisez Elementor, ou retirez-le d’abord des modèles dans les réglages.', 'publication-facile' ) . '</p></div></div>';
			return;
		}

		if ( $post_id ) {
			$source = PF_Post_Builder::get_source( $post_id );
			$config = PF_Plugin::get_template( $source['template_id'] );
			if ( ! $config ) {
				$config                = PF_Plugin::template_defaults();
				$config['template_id'] = (int) $source['template_id'];
			}
		} else {
			$template_id = isset( $data['pf_template_id'] ) ? absint( $data['pf_template_id'] ) : 0;
			$config      = PF_Plugin::get_template( $template_id );
			if ( ! $config ) {
				return new WP_Error( 'pf_template', __( 'Merci de choisir un modèle d’article.', 'publication-facile' ) );
			}
			$errors = PF_Template_Inspector::validate_template( $config );
			if ( $errors ) {
				return new WP_Error( 'pf_settings', __( 'Ce modèle n’est pas configuré correctement :', 'publication-facile' ) . ' ' . implode( ' ', $errors ) );
			}
		}

		if ( 'publish' === $action && ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error( 'pf_cap', __( 'Vous n’avez pas le droit de publier. Enregistrez un brouillon.', 'publication-facile' ) );
		}

		$fields = $this->sanitize_fields( $data );
		$errors = new WP_Error();
		if ( '' === $fields['title'] ) {
			$errors->add( 'pf_title', __( 'Le titre est obligatoire.', 'publication-facile' ) );
		}
		if ( '' === trim( wp_strip_all_tags( $fields['body'] ) ) ) {
			$errors->add( 'pf_body', __( 'Le texte de l’article est obligatoire.', 'publication-facile' ) );
		}
		if ( ! $fields['image_id'] || ! wp_attachment_is_image( $fields['image_id'] ) ) {
			$errors->add( 'pf_image', __( 'Merci de choisir une image valide dans la médiathèque.', 'publication-facile' ) );
		}
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$current_status = $post_id ? get_post_status( $post_id ) : '';
		if ( 'preview' === $action && 'publish' === $current_status ) {
			return new WP_Error( 'pf_preview', __( 'L’aperçu n’est disponible que pour les brouillons : cet article est déjà en ligne.', 'publication-facile' ) );
		}

		$status  = in_array( $action, array( 'publish', 'draft' ), true ) ? $action : ( 'preview' === $action ? 'draft' : '' );
		$builder = new PF_Post_Builder( $config );
		$result  = $post_id ? $builder->update( $post_id, $fields, $status ) : $builder->create( $fields, $status );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$messages = array(
			'preview' => 'preview',
			'draft'   => 'draft',
			'publish' => 'publish',
			'update'  => 'publish' === get_post_status( $result ) ? 'updated' : 'draft',
		);

		return array(
			'post_id'  => (int) $result,
			'message'  => $messages[ $action ],
			'warnings' => $builder->get_warnings(),
		);
	}

	/**
	 * Nettoie les champs du formulaire.
	 *
	 * @param array $data Données brutes.
	 * @return array
	 */
	private function sanitize_fields( array $data ) {
		$title   = isset( $data['pf_title'] ) ? sanitize_text_field( $data['pf_title'] ) : '';
		$summary = isset( $data['pf_summary'] ) ? sanitize_textarea_field( $data['pf_summary'] ) : '';
		$body    = isset( $data['pf_body'] ) ? (string) $data['pf_body'] : '';

		$body = wp_kses( wpautop( $body ), self::allowed_body_tags() );
		$body = self::add_noopener( $body );
		$body = trim( preg_replace( '#<p>(\s|&nbsp;|\x{00A0})*</p>#u', '', $body ) );

		return array(
			'title'        => $title,
			'summary_text' => $summary,
			'summary'      => '' !== $summary ? trim( wpautop( esc_html( $summary ) ) ) : '',
			'body'         => $body,
			'image_id'     => isset( $data['pf_image_id'] ) ? absint( $data['pf_image_id'] ) : 0,
			'image_alt'    => isset( $data['pf_image_alt'] ) ? sanitize_text_field( $data['pf_image_alt'] ) : '',
		);
	}

	/**
	 * Liens ouverts dans un nouvel onglet : ajoute rel="noopener noreferrer"
	 * (protection contre le « tabnabbing », y compris sur les anciens navigateurs).
	 *
	 * @param string $html HTML déjà filtré par wp_kses.
	 * @return string
	 */
	public static function add_noopener( $html ) {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || false === stripos( $html, 'target=' ) ) {
			return $html;
		}
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( 'a' ) ) {
			if ( '_blank' !== $tags->get_attribute( 'target' ) ) {
				continue;
			}
			$rel   = (string) $tags->get_attribute( 'rel' );
			$parts = array_unique( array_filter( array_merge( preg_split( '/\s+/', strtolower( $rel ) ), array( 'noopener', 'noreferrer' ) ) ) );
			$tags->set_attribute( 'rel', implode( ' ', $parts ) );
		}
		return $tags->get_updated_html();
	}

	/**
	 * Balises autorisées dans le texte : la mise en forme visuelle vient du modèle.
	 *
	 * @return array
	 */
	public static function allowed_body_tags() {
		return array(
			'p'          => array(),
			'br'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'u'          => array(),
			's'          => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'blockquote' => array(),
			'a'          => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			),
		);
	}

	/**
	 * Champs bruts à réafficher après une erreur (la saisie n'est jamais perdue).
	 *
	 * @param array $data Données.
	 * @return array
	 */
	private function raw_fields( array $data ) {
		return array(
			'title'     => isset( $data['pf_title'] ) ? sanitize_text_field( $data['pf_title'] ) : '',
			'summary'   => isset( $data['pf_summary'] ) ? sanitize_textarea_field( $data['pf_summary'] ) : '',
			'body'      => isset( $data['pf_body'] ) ? wp_kses( (string) $data['pf_body'], self::allowed_body_tags() ) : '',
			'image_id'  => isset( $data['pf_image_id'] ) ? absint( $data['pf_image_id'] ) : 0,
			'image_alt' => isset( $data['pf_image_alt'] ) ? sanitize_text_field( $data['pf_image_alt'] ) : '',
		);
	}

	/**
	 * Clé de transient propre à l'utilisateur.
	 *
	 * @param string $name Nom.
	 * @return string
	 */
	private function transient_key( $name ) {
		return 'pf_' . $name . '_' . get_current_user_id();
	}

	/**
	 * Lit puis supprime un transient.
	 *
	 * @param string $name Nom.
	 * @return mixed
	 */
	private function pull_transient( $name ) {
		$key   = $this->transient_key( $name );
		$value = get_transient( $key );
		delete_transient( $key );
		return $value;
	}

	/* ------------------------------------------------------------------ */
	/* Affichage                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Formulaire.
	 */
	public function render_form() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- lecture seule.
		$post_id     = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$template_id = isset( $_GET['template'] ) ? absint( $_GET['template'] ) : 0;
		$msg         = isset( $_GET['pf_msg'] ) ? sanitize_key( $_GET['pf_msg'] ) : '';
		$preview     = ! empty( $_GET['pf_preview'] );
		// phpcs:enable

		echo '<div class="wrap pf-wrap pf-editor">';

		$usable = PF_Plugin::usable_templates();
		$config = null;

		if ( $post_id && PF_Plugin::is_template( $post_id ) ) {
			// Article devenu modèle : le formulaire ne doit pas le modifier.
			echo '<h1>' . esc_html__( 'Modifier l’article', 'publication-facile' ) . '</h1>';
			echo '<div class="pf-card pf-card--error"><p><strong>' . esc_html__( 'Cet article sert de modèle : il ne se modifie pas avec le formulaire, pour protéger la mise en page des futurs articles.', 'publication-facile' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Pour le changer, utilisez Elementor, ou retirez-le d’abord des modèles dans les réglages.', 'publication-facile' ) . '</p></div></div>';
			return;
		}

		if ( $post_id ) {
			$source = PF_Post_Builder::get_source( $post_id );
			if ( $source ) {
				$config = PF_Plugin::get_template( $source['template_id'] );
				if ( ! $config ) {
					// Modèle retiré des réglages : l'article reste modifiable.
					$config                = PF_Plugin::template_defaults();
					$config['template_id'] = (int) $source['template_id'];
					$config['label']       = PF_Plugin::default_label( $source['template_id'] );
					$config['mapping']     = $source['mapping'];
				}
			}
		} elseif ( ! $usable ) {
			echo '<h1>' . esc_html__( 'Nouvel article', 'publication-facile' ) . '</h1>';
			echo '<div class="pf-card pf-card--error"><p><strong>' . esc_html__( 'Le formulaire n’est pas encore disponible : aucun modèle d’article n’est prêt.', 'publication-facile' ) . '</strong></p>';
			if ( current_user_can( 'manage_options' ) ) {
				printf( '<p><a class="button button-primary" href="%s">%s</a></p>', esc_url( admin_url( 'options-general.php?page=' . PF_Settings::PAGE ) ), esc_html__( 'Ouvrir les réglages', 'publication-facile' ) );
			}
			echo '</div></div>';
			return;
		} elseif ( isset( $usable[ $template_id ] ) ) {
			$config = $usable[ $template_id ];
		} elseif ( 1 === count( $usable ) ) {
			$config = reset( $usable );
		} else {
			$this->render_chooser( $usable, $msg );
			echo '</div>';
			return;
		}

		$fields = array(
			'title'     => '',
			'summary'   => '',
			'body'      => '',
			'image_id'  => 0,
			'image_alt' => '',
		);
		$status = '';

		if ( $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				echo '<div class="pf-card pf-card--error"><p>' . esc_html__( 'Vous ne pouvez pas modifier cet article.', 'publication-facile' ) . '</p></div></div>';
				return;
			}
			$read = PF_Post_Builder::read_fields( $post_id );
			if ( is_wp_error( $read ) ) {
				echo '<div class="pf-card pf-card--error"><p>' . esc_html( $read->get_error_message() ) . '</p></div></div>';
				return;
			}
			$fields = $read;
			$status = get_post_status( $post_id );
		}

		$saved_form = $this->pull_transient( 'form' );
		if ( 'error' === $msg && is_array( $saved_form ) ) {
			$fields = array_merge( $fields, $saved_form );
		}

		$is_live = 'publish' === $status;
		$heading = $post_id ? __( 'Modifier l’article', 'publication-facile' ) : __( 'Nouvel article', 'publication-facile' );
		$has_sum = $config && ! empty( $config['mapping']['summary'] );

		echo '<h1>' . esc_html( $heading ) . '</h1>';
		$this->render_messages( $msg, $post_id, $preview );

		if ( ! $post_id && count( $usable ) > 1 ) {
			printf(
				'<p class="pf-status">%s <strong>%s</strong> · <a href="%s">%s</a></p>',
				esc_html__( 'Modèle :', 'publication-facile' ),
				esc_html( $config['label'] ),
				esc_url( admin_url( 'admin.php?page=' . self::PAGE_NEW ) ),
				esc_html__( 'changer de modèle', 'publication-facile' )
			);
		}

		if ( $post_id ) {
			echo '<p class="pf-status">';
			echo $is_live
				? '<span class="pf-badge pf-badge--live">' . esc_html__( 'En ligne', 'publication-facile' ) . '</span> '
				: '<span class="pf-badge">' . esc_html__( 'Brouillon', 'publication-facile' ) . '</span> ';
			if ( $is_live ) {
				printf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( get_permalink( $post_id ) ), esc_html__( 'Voir l’article sur le site', 'publication-facile' ) );
			}
			echo '</p>';
		}

		$image_url = $fields['image_id'] ? wp_get_attachment_image_url( (int) $fields['image_id'], 'medium_large' ) : '';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pf-form" class="pf-form" novalidate>
			<input type="hidden" name="action" value="pf_save">
			<input type="hidden" name="pf_post_id" id="pf-post-id" value="<?php echo esc_attr( $post_id ); ?>">
			<input type="hidden" name="pf_template_id" value="<?php echo esc_attr( $config ? $config['template_id'] : 0 ); ?>">
			<?php wp_nonce_field( self::NONCE, 'pf_nonce' ); ?>

			<div class="pf-layout">
				<div class="pf-main">
					<div class="pf-card">
						<label for="pf-title" class="pf-label"><?php esc_html_e( 'Titre', 'publication-facile' ); ?> <span class="pf-required" aria-hidden="true">*</span></label>
						<input type="text" id="pf-title" name="pf_title" class="pf-input pf-input--title" value="<?php echo esc_attr( $fields['title'] ); ?>" required maxlength="200" placeholder="<?php esc_attr_e( 'Ex. : 5 conseils pour prendre soin de soi', 'publication-facile' ); ?>">

						<?php if ( $has_sum ) : ?>
							<label for="pf-summary" class="pf-label"><?php esc_html_e( 'Résumé', 'publication-facile' ); ?> <span class="pf-muted"><?php esc_html_e( '(une ou deux phrases sous le titre)', 'publication-facile' ); ?></span></label>
							<textarea id="pf-summary" name="pf_summary" class="pf-input" rows="3" maxlength="500"><?php echo esc_textarea( $fields['summary'] ); ?></textarea>
						<?php endif; ?>
					</div>

					<div class="pf-card">
						<span class="pf-label" id="pf-body-label"><?php esc_html_e( 'Texte de l’article', 'publication-facile' ); ?> <span class="pf-required" aria-hidden="true">*</span></span>
						<p class="description"><?php esc_html_e( 'Écrivez autant de paragraphes que nécessaire. Utilisez « Intertitre » pour les sous-titres. La mise en forme du site est appliquée automatiquement.', 'publication-facile' ); ?></p>
						<?php
						wp_editor(
							$fields['body'],
							'pf_body',
							array(
								'textarea_name' => 'pf_body',
								'media_buttons' => false,
								'quicktags'     => false,
								'teeny'         => false,
								'editor_height' => 380,
								'tinymce'       => array(
									'toolbar1'            => 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo',
									'toolbar2'            => '',
									'block_formats'       => 'Paragraphe=p;Intertitre=h2;Sous-intertitre=h3',
									'paste_remove_styles' => true,
									'paste_webkit_styles' => 'none',
									'paste_data_images'   => false,
								),
							)
						);
						?>
					</div>
				</div>

				<aside class="pf-side">
					<div class="pf-card">
						<span class="pf-label"><?php esc_html_e( 'Image principale', 'publication-facile' ); ?> <span class="pf-required" aria-hidden="true">*</span></span>
						<div class="pf-image <?php echo $image_url ? 'has-image' : ''; ?>" id="pf-image-box">
							<img id="pf-image-preview" src="<?php echo esc_url( $image_url ); ?>" alt="" <?php echo $image_url ? '' : 'hidden'; ?>>
							<button type="button" class="button button-secondary pf-btn-block" id="pf-image-button">
								<?php echo $image_url ? esc_html__( 'Changer l’image', 'publication-facile' ) : esc_html__( 'Choisir l’image principale', 'publication-facile' ); ?>
							</button>
						</div>
						<input type="hidden" name="pf_image_id" id="pf-image-id" value="<?php echo esc_attr( $fields['image_id'] ); ?>">
						<label for="pf-image-alt" class="pf-label pf-label--small"><?php esc_html_e( 'Description de l’image', 'publication-facile' ); ?></label>
						<input type="text" id="pf-image-alt" name="pf_image_alt" class="pf-input" value="<?php echo esc_attr( $fields['image_alt'] ); ?>" placeholder="<?php esc_attr_e( 'Ex. : Pot de crème au milieu de pétales de rose', 'publication-facile' ); ?>">
						<p class="description"><?php esc_html_e( 'Utile pour les personnes malvoyantes et pour Google.', 'publication-facile' ); ?></p>
					</div>

					<div class="pf-card pf-actions">
						<?php if ( $is_live ) : ?>
							<button type="submit" name="pf_action" value="update" class="button button-primary button-hero pf-btn-block"><?php esc_html_e( 'Mettre à jour l’article', 'publication-facile' ); ?></button>
							<button type="submit" name="pf_action" value="draft" class="button pf-btn-block pf-btn-link"><?php esc_html_e( 'Retirer du site (repasser en brouillon)', 'publication-facile' ); ?></button>
						<?php else : ?>
							<button type="button" class="button pf-btn-block" id="pf-preview-button" data-action="preview"><?php esc_html_e( 'Aperçu', 'publication-facile' ); ?></button>
							<button type="submit" name="pf_action" value="draft" class="button pf-btn-block"><?php esc_html_e( 'Enregistrer le brouillon', 'publication-facile' ); ?></button>
							<?php if ( current_user_can( 'publish_posts' ) ) : ?>
								<button type="submit" name="pf_action" value="publish" class="button button-primary button-hero pf-btn-block" data-confirm="1"><?php esc_html_e( 'Publier maintenant', 'publication-facile' ); ?></button>
							<?php endif; ?>
						<?php endif; ?>
						<p class="pf-feedback" id="pf-feedback" role="status" aria-live="polite"></p>
					</div>
				</aside>
			</div>
		</form>
		<?php
		echo '</div>';
	}

	/**
	 * Écran de choix du modèle (affiché s'il y en a plusieurs).
	 *
	 * @param array  $usable Modèles utilisables.
	 * @param string $msg    Code de message.
	 */
	private function render_chooser( array $usable, $msg ) {
		echo '<h1>' . esc_html__( 'Nouvel article', 'publication-facile' ) . '</h1>';
		if ( 'error' === $msg ) {
			$this->render_messages( $msg, 0, false );
		}
		echo '<p class="pf-intro">' . esc_html__( 'Quel type d’article voulez-vous écrire ?', 'publication-facile' ) . '</p>';
		echo '<div class="pf-chooser">';
		foreach ( $usable as $config ) {
			$url   = add_query_arg(
				array(
					'page'     => self::PAGE_NEW,
					'template' => $config['template_id'],
				),
				admin_url( 'admin.php' )
			);
			$thumb = get_the_post_thumbnail_url( $config['template_id'], 'medium_large' );
			?>
			<a class="pf-choice" href="<?php echo esc_url( $url ); ?>">
				<span class="pf-choice__img"<?php echo $thumb ? ' style="' . esc_attr( 'background-image:url("' . esc_url( $thumb ) . '")' ) . '"' : ''; ?>></span>
				<span class="pf-choice__label"><?php echo esc_html( $config['label'] ); ?></span>
				<span class="pf-choice__cta"><?php esc_html_e( 'Choisir ce modèle →', 'publication-facile' ); ?></span>
			</a>
			<?php
		}
		echo '</div>';
		printf(
			'<p class="pf-muted">%s</p>',
			esc_html__( 'Le modèle définit la mise en page : il ne reste qu’à écrire le titre et le texte, et à choisir l’image.', 'publication-facile' )
		);
	}

	/**
	 * Messages de retour.
	 *
	 * @param string $msg     Code.
	 * @param int    $post_id ID.
	 * @param bool   $preview Ouvrir l'aperçu ?
	 */
	private function render_messages( $msg, $post_id, $preview ) {
		$texts = array(
			'draft'   => __( 'Brouillon enregistré. Il n’est pas encore visible sur le site.', 'publication-facile' ),
			'publish' => __( 'Bravo, votre article est en ligne !', 'publication-facile' ),
			'updated' => __( 'Modifications enregistrées et en ligne.', 'publication-facile' ),
			'preview' => __( 'Brouillon enregistré.', 'publication-facile' ),
		);

		if ( 'error' === $msg ) {
			$errors = $this->pull_transient( 'error' );
			$errors = is_array( $errors ) && $errors ? $errors : array( __( 'Une erreur est survenue.', 'publication-facile' ) );
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'L’article n’a pas été enregistré :', 'publication-facile' ) . '</strong></p><ul class="pf-list">';
			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul><p>' . esc_html__( 'Votre saisie a été conservée ci-dessous.', 'publication-facile' ) . '</p></div>';
			return;
		}

		if ( isset( $texts[ $msg ] ) && $post_id ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $texts[ $msg ] );
			if ( in_array( $msg, array( 'publish', 'updated' ), true ) ) {
				printf( ' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( get_permalink( $post_id ) ), esc_html__( 'Voir l’article', 'publication-facile' ) );
			} else {
				printf( ' <a href="%s" target="_blank" rel="noopener"%s>%s</a>', esc_url( get_preview_post_link( $post_id ) ), $preview ? ' id="pf-open-preview"' : '', esc_html__( 'Ouvrir l’aperçu', 'publication-facile' ) );
			}
			echo '</p></div>';
		}

		$warnings = $this->pull_transient( 'warning' );
		if ( is_array( $warnings ) ) {
			foreach ( $warnings as $warning ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( $warning ) . '</p></div>';
			}
		}
	}

	/**
	 * Liste « Mes articles ».
	 */
	public function render_list() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 20,
				'paged'          => $paged,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'       => PF_Post_Builder::SOURCE_META,
			)
		);
		?>
		<?php $multi = count( PF_Plugin::get_settings()['templates'] ) > 1; ?>
		<div class="wrap pf-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Mes articles', 'publication-facile' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_NEW ) ); ?>" class="page-title-action"><?php esc_html_e( 'Écrire un article', 'publication-facile' ); ?></a>

			<?php if ( ! $query->have_posts() ) : ?>
				<div class="pf-card"><p><?php esc_html_e( 'Aucun article pour le moment.', 'publication-facile' ); ?></p></div>
			<?php else : ?>
				<div class="pf-cards-list">
					<?php
					foreach ( $query->posts as $item ) :
						$edit = add_query_arg(
							array(
								'page'    => self::PAGE_NEW,
								'post_id' => $item->ID,
							),
							admin_url( 'admin.php' )
						);
						$live     = 'publish' === $item->post_status;
						$is_model = PF_Plugin::is_template( $item->ID );
						?>
						<div class="pf-card pf-item">
							<div class="pf-item__thumb"><?php echo get_the_post_thumbnail( $item, 'thumbnail' ); ?></div>
							<div class="pf-item__body">
								<strong class="pf-item__title"><?php echo esc_html( get_the_title( $item ) ); ?></strong>
								<span class="pf-badge <?php echo $live ? 'pf-badge--live' : ''; ?>"><?php echo $live ? esc_html__( 'En ligne', 'publication-facile' ) : esc_html__( 'Brouillon', 'publication-facile' ); ?></span>
								<?php
								$src = PF_Post_Builder::get_source( $item->ID );
								$tpl = $src ? PF_Plugin::get_template( $src['template_id'] ) : null;
								if ( $tpl && $multi ) :
									?>
									<span class="pf-muted">
										<?php
										/* translators: %s: nom du modèle */
										echo esc_html( sprintf( __( 'Modèle : %s', 'publication-facile' ), $tpl['label'] ) );
										?>
									</span>
								<?php endif; ?>
								<span class="pf-muted">
									<?php
									/* translators: %s: date */
									echo esc_html( sprintf( __( 'Modifié le %s', 'publication-facile' ), get_the_modified_date( '', $item ) ) );
									?>
								</span>
							</div>
							<div class="pf-item__actions">
								<?php if ( $is_model ) : ?>
									<span class="pf-badge"><?php esc_html_e( 'Sert de modèle', 'publication-facile' ); ?></span>
								<?php elseif ( current_user_can( 'edit_post', $item->ID ) ) : ?>
									<a class="button button-primary" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Modifier', 'publication-facile' ); ?></a>
								<?php endif; ?>
								<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $live ? get_permalink( $item ) : get_preview_post_link( $item ) ); ?>"><?php echo $live ? esc_html__( 'Voir', 'publication-facile' ) : esc_html__( 'Aperçu', 'publication-facile' ); ?></a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $query->max_num_pages,
						)
					)
				);
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Lien « Modifier (formulaire simple) » dans la liste des articles WordPress.
	 *
	 * @param string[] $actions Actions.
	 * @param WP_Post  $post    Article.
	 * @return string[]
	 */
	public function row_action( $actions, $post ) {
		if ( 'post' === $post->post_type && PF_Post_Builder::get_source( $post->ID ) && ! PF_Plugin::is_template( $post->ID ) && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['pf_edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page'    => self::PAGE_NEW,
							'post_id' => $post->ID,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Modifier (formulaire simple)', 'publication-facile' )
			);
		}
		return $actions;
	}
}
