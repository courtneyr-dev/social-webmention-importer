<?php
/**
 * Tools → Social Webmention Importer screen.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Admin;

use CourtneyRDev\SocialWebmentionImporter\Import\Comment_Importer;
use CourtneyRDev\SocialWebmentionImporter\Import\Preview_Record;
use CourtneyRDev\SocialWebmentionImporter\Plugin;

/**
 * Renders the three-step workflow: target + URLs → editable preview → report.
 *
 * Server-rendered forms throughout: every step works without JavaScript;
 * the only script is progressive enhancement for the post search box.
 */
class Import_Page {

	/**
	 * Register the page and its assets.
	 */
	public static function init() {
		add_action( 'admin_menu', array( static::class, 'register_page' ) );
		add_action( 'admin_menu', array( static::class, 'nest_under_webmention_tools' ), 999 );
		add_action( 'admin_head', array( static::class, 'print_menu_indent_style' ) );
		add_action( 'admin_enqueue_scripts', array( static::class, 'enqueue_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( static::class, 'enqueue_editor_assets' ) );
	}

	/**
	 * Visually indent the Tools entry beneath Webmention's.
	 *
	 * The admin menu is hard-limited to two levels, so a true child
	 * flyout isn't possible; an indent under the adjacent Webmention
	 * entry conveys the relationship without hover-only UI, keeping the
	 * item reachable by keyboard and touch.
	 */
	public static function print_menu_indent_style() {
		echo '<style>#adminmenu .wp-submenu a[href="tools.php?page=social-webmention-importer"]{padding-left:24px;}</style>';
	}

	/**
	 * Place the importer directly after Webmention's own Tools entry.
	 *
	 * The Webmention plugin registers Tools → Webmention (slug
	 * `webmention-tools`). Admin submenus are flat, so "nesting" means
	 * ordering: move our entry immediately below Webmention's so the two
	 * read as a group. Runs late so both entries already exist; does
	 * nothing when Webmention's entry is absent.
	 */
	public static function nest_under_webmention_tools() {
		global $submenu;

		if ( empty( $submenu['tools.php'] ) || ! is_array( $submenu['tools.php'] ) ) {
			return;
		}

		$ours = null;
		foreach ( $submenu['tools.php'] as $index => $item ) {
			if ( 'social-webmention-importer' === ( $item[2] ?? '' ) ) {
				$ours = $item;
				unset( $submenu['tools.php'][ $index ] );
				break;
			}
		}
		if ( null === $ours ) {
			return;
		}

		$entries = array_values( $submenu['tools.php'] );
		$anchor  = null;
		foreach ( $entries as $index => $item ) {
			if ( 'webmention-tools' === ( $item[2] ?? '' ) ) {
				$anchor = $index;
				break;
			}
		}

		if ( null === $anchor ) {
			// No Webmention entry to nest under; restore original placement.
			$entries[] = $ours;
		} else {
			array_splice( $entries, $anchor + 1, 0, array( $ours ) );
		}

		$submenu['tools.php'] = $entries;
	}

	/**
	 * Enqueue the block-editor "Social responses" panel for users who may
	 * import to the post being edited.
	 */
	public static function enqueue_editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		if ( ! Plugin::user_can_import() ) {
			return;
		}

		wp_enqueue_script(
			'swi-editor',
			SWI_PLUGIN_URL . 'assets/js/editor.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ),
			SWI_VERSION,
			true
		);
		wp_localize_script(
			'swi-editor',
			'swiEditor',
			array(
				'adminPost' => admin_url( 'admin-post.php' ),
				'nonce'     => wp_create_nonce( 'swi_preview' ),
			)
		);
	}

	/**
	 * Add the Tools submenu page, plus a companion entry beside Webmention.
	 *
	 * The Webmention plugin has no top-level menu of its own: it registers
	 * under Settings, or under the IndieWeb plugin's top-level menu when
	 * that plugin is active. When the IndieWeb menu exists, add a link
	 * entry beside Webmention's pointing at the canonical Tools screen.
	 */
	public static function register_page() {
		add_management_page(
			__( 'Social Webmention Importer', 'social-webmention-importer' ),
			__( 'Social Webmention Importer', 'social-webmention-importer' ),
			'moderate_comments',
			'social-webmention-importer',
			array( static::class, 'render' )
		);

		if ( class_exists( 'IndieWeb_Plugin' ) ) {
			add_submenu_page(
				'indieweb',
				__( 'Social Webmention Importer', 'social-webmention-importer' ),
				__( 'Social Importer', 'social-webmention-importer' ),
				'moderate_comments',
				'tools.php?page=social-webmention-importer'
			);
		}
	}

	/**
	 * Enqueue admin assets on our screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_social-webmention-importer' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'swi-admin', SWI_PLUGIN_URL . 'assets/css/admin.css', array(), SWI_VERSION );
		wp_enqueue_script( 'swi-admin', SWI_PLUGIN_URL . 'assets/js/admin.js', array(), SWI_VERSION, true );
		wp_localize_script(
			'swi-admin',
			'swiAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'searchNonce' => wp_create_nonce( 'swi_post_search' ),
			)
		);

		// Media Library picker for manual avatar selection.
		wp_enqueue_media();
	}

	/**
	 * Route to the step the query args describe.
	 */
	public static function render() {
		if ( ! Plugin::user_can_import() ) {
			wp_die( esc_html__( 'You are not allowed to use the importer.', 'social-webmention-importer' ), 403 );
		}

		echo '<div class="wrap swi-wrap">';
		echo '<h1>' . esc_html__( 'Social Webmention Importer', 'social-webmention-importer' ) . '</h1>';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing; every state change re-checks its own nonce.
		if ( isset( $_GET['swi_results'] ) ) {
			self::render_results( sanitize_key( $_GET['swi_results'] ) );
		} elseif ( isset( $_GET['swi_batch'] ) ) {
			self::render_review( sanitize_key( $_GET['swi_batch'] ) );
		} else {
			$preselected = isset( $_GET['swi_post'] ) ? absint( $_GET['swi_post'] ) : 0;
			$prefill     = isset( $_GET['swi_prefill'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['swi_prefill'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw sanitizes.
			self::render_start( $preselected, $prefill );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '</div>';
	}

	/**
	 * Step 1: target post + URL list.
	 *
	 * @param int    $preselected Post ID arriving from a row action.
	 * @param string $prefill     Source URL arriving from "Edit import details".
	 */
	protected static function render_start( $preselected = 0, $prefill = '' ) {
		if ( isset( $_GET['swi_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'social-webmention-importer' ) . '</p></div>';
		}

		$post_types = function_exists( 'get_post_types_by_support' ) ? get_post_types_by_support( 'webmentions' ) : array( 'post', 'page' );
		$recent     = get_posts(
			array(
				'post_type'      => $post_types ? $post_types : array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		// Make sure a preselected post appears even when it is not recent.
		if ( $preselected && ! in_array( $preselected, wp_list_pluck( $recent, 'ID' ), true ) ) {
			$pre_post = get_post( $preselected );
			if ( $pre_post ) {
				array_unshift( $recent, $pre_post );
			}
		}
		?>
		<p><?php esc_html_e( 'Paste public X/Twitter or LinkedIn post URLs (one per line), pick the post they respond to, and review each response before anything is imported.', 'social-webmention-importer' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swi-start-form">
			<?php wp_nonce_field( 'swi_preview' ); ?>
			<input type="hidden" name="action" value="swi_preview" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="swi-post-search"><?php esc_html_e( 'Search target posts', 'social-webmention-importer' ); ?></label>
					</th>
					<td>
						<input type="search" id="swi-post-search" class="regular-text" autocomplete="off"
							aria-describedby="swi-post-search-help" />
						<p id="swi-post-search-help" class="description"><?php esc_html_e( 'Type to filter the post list below. Works without JavaScript too — the list shows the newest posts.', 'social-webmention-importer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="swi-post-id"><?php esc_html_e( 'Target post', 'social-webmention-importer' ); ?> <span class="swi-required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( '(required)', 'social-webmention-importer' ); ?></span></label>
					</th>
					<td>
						<select name="swi_post_id" id="swi-post-id" required aria-describedby="swi-target-url">
							<option value=""><?php esc_html_e( '— Choose a post —', 'social-webmention-importer' ); ?></option>
							<?php foreach ( $recent as $post ) : ?>
								<option value="<?php echo esc_attr( $post->ID ); ?>" data-url="<?php echo esc_url( get_permalink( $post ) ); ?>" <?php selected( $preselected, $post->ID ); ?>>
									<?php echo esc_html( get_the_title( $post ) . ' (#' . $post->ID . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p id="swi-target-url" class="description swi-target-url" data-swi-target-url>
							<?php
							if ( $preselected ) {
								/* translators: %s: permalink. */
								printf( esc_html__( 'Canonical target URL: %s', 'social-webmention-importer' ), esc_url( get_permalink( $preselected ) ) );
							} else {
								esc_html_e( 'Canonical target URL appears here after you choose a post.', 'social-webmention-importer' );
							}
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="swi-urls"><?php esc_html_e( 'Response URLs', 'social-webmention-importer' ); ?> <span class="swi-required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( '(required)', 'social-webmention-importer' ); ?></span></label>
					</th>
					<td>
						<textarea name="swi_urls" id="swi-urls" rows="8" class="large-text code" required
							aria-describedby="swi-urls-help"><?php echo esc_textarea( $prefill ); ?></textarea>
						<p id="swi-urls-help" class="description">
							<?php
							printf(
								/* translators: %d: batch limit. */
								esc_html__( 'One URL per line; blank lines are fine. Up to %d URLs per batch.', 'social-webmention-importer' ),
								(int) Plugin::batch_limit()
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Preview responses', 'social-webmention-importer' ) ); ?>
		</form>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<hr />
			<h2><?php esc_html_e( 'Importer settings', 'social-webmention-importer' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'swi_settings' ); ?>
				<input type="hidden" name="action" value="swi_settings" />
				<label for="swi-allow-approve">
					<input type="checkbox" name="swi_allow_approve" id="swi-allow-approve" value="1" <?php checked( get_option( Plugin::OPTION_ALLOW_APPROVE ) ); ?> />
					<?php esc_html_e( 'Let imports follow the site’s normal moderation rules instead of always starting as pending. Leave off to hold every import for review.', 'social-webmention-importer' ); ?>
				</label>
				<?php submit_button( __( 'Save settings', 'social-webmention-importer' ), 'secondary' ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Step 2: editable preview cards.
	 *
	 * @param string $token Batch token.
	 */
	protected static function render_review( $token ) {
		$batch = get_transient( 'swi_batch_' . get_current_user_id() . '_' . $token );

		if ( ! is_array( $batch ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This preview expired or belongs to another user. Start over.', 'social-webmention-importer' ) . '</p></div>';
			self::render_start();
			return;
		}

		$post_id = (int) $batch['post_id'];
		?>
		<h2>
			<?php
			printf(
				/* translators: %s: post title. */
				esc_html__( 'Step 2 of 3 — review responses for “%s”', 'social-webmention-importer' ),
				esc_html( get_the_title( $post_id ) )
			);
			?>
		</h2>
		<?php if ( ! empty( $batch['truncated'] ) ) : ?>
			<div class="notice notice-warning"><p>
				<?php
				printf(
					/* translators: %d: number of dropped URLs. */
					esc_html__( '%d URLs beyond the batch limit were not previewed. Run them in a second batch.', 'social-webmention-importer' ),
					(int) $batch['truncated']
				);
				?>
			</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'swi_import' ); ?>
			<input type="hidden" name="action" value="swi_import" />
			<input type="hidden" name="swi_batch" value="<?php echo esc_attr( $token ); ?>" />

			<?php foreach ( $batch['records'] as $index => $stored ) : ?>
				<?php self::render_review_card( $index, Preview_Record::from_array( $stored ) ); ?>
			<?php endforeach; ?>

			<p>
				<label for="swi-approve-now">
					<input type="checkbox" name="swi_approve_now" id="swi-approve-now" value="1" />
					<?php esc_html_e( 'Approve these responses immediately instead of holding them as pending. You reviewed each one above; this publishes them on import.', 'social-webmention-importer' ); ?>
				</label>
			</p>

			<?php submit_button( __( 'Import selected responses', 'social-webmention-importer' ) ); ?>
		</form>
		<?php
	}

	/**
	 * One editable preview card.
	 *
	 * @param int            $index  Row index.
	 * @param Preview_Record $record Record.
	 */
	protected static function render_review_card( $index, Preview_Record $record ) {
		$field = fn( $name ) => "rows[{$index}][{$name}]";
		$id    = fn( $name ) => "swi-{$index}-{$name}";

		$mode_is_curated = Plugin::MODE_SOCIAL_LINKBACK === $record->mode;
		?>
		<fieldset class="swi-card <?php echo $record->error ? 'swi-card--error' : ''; ?>">
			<legend>
				<?php echo esc_html( sprintf( '#%d — %s', $index + 1, $record->source_url ) ); ?>
			</legend>

			<?php if ( $record->error ) : ?>
				<p class="swi-error" role="alert"><?php echo esc_html( $record->error ); ?></p>
				<p><a href="<?php echo esc_url( $record->source_url ); ?>" rel="noopener"><?php esc_html_e( 'Open the source', 'social-webmention-importer' ); ?></a></p>
			<?php else : ?>

				<p class="swi-card__status">
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $field( 'import' ) ); ?>" value="1" <?php checked( 'duplicate' !== $record->duplicate_status ); ?> />
						<strong><?php esc_html_e( 'Import this response', 'social-webmention-importer' ); ?></strong>
					</label>

					<span class="swi-badge swi-badge--provider"><?php echo esc_html( strtoupper( $record->provider ) ); ?></span>
					<span class="swi-badge swi-badge--<?php echo esc_attr( $record->verification ); ?>">
						<?php
						if ( 'verified' === $record->verification ) {
							esc_html_e( 'Links to this post — verified Webmention', 'social-webmention-importer' );
						} elseif ( 'unverified' === $record->verification ) {
							esc_html_e( 'No link to this post — curated response only', 'social-webmention-importer' );
						} else {
							esc_html_e( 'Could not verify — curated response only', 'social-webmention-importer' );
						}
						?>
					</span>
					<?php if ( 'update' === $record->duplicate_status ) : ?>
						<span class="swi-badge swi-badge--update"><?php esc_html_e( 'Will update an existing response', 'social-webmention-importer' ); ?></span>
					<?php elseif ( 'duplicate' === $record->duplicate_status ) : ?>
						<span class="swi-badge swi-badge--duplicate"><?php esc_html_e( 'Duplicate in this batch', 'social-webmention-importer' ); ?></span>
					<?php endif; ?>
					<?php if ( $record->remote_id ) : ?>
						<span class="swi-badge"><?php echo esc_html( sprintf( /* translators: %s: remote ID. */ __( 'Remote ID: %s', 'social-webmention-importer' ), $record->remote_id ) ); ?></span>
					<?php endif; ?>
				</p>

				<?php if ( $record->warnings ) : ?>
					<ul class="swi-warnings" id="<?php echo esc_attr( $id( 'warnings' ) ); ?>">
						<?php foreach ( $record->warnings as $warning ) : ?>
							<li><?php echo esc_html( $warning ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<div class="swi-grid">
					<p>
						<label for="<?php echo esc_attr( $id( 'author_name' ) ); ?>"><?php esc_html_e( 'Author name', 'social-webmention-importer' ); ?> <span class="swi-required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( '(required)', 'social-webmention-importer' ); ?></span></label>
						<input type="text" id="<?php echo esc_attr( $id( 'author_name' ) ); ?>" name="<?php echo esc_attr( $field( 'author_name' ) ); ?>"
							value="<?php echo esc_attr( $record->author_name ); ?>" <?php echo $record->warnings ? 'aria-describedby="' . esc_attr( $id( 'warnings' ) ) . '"' : ''; ?> />
					</p>
					<p>
						<label for="<?php echo esc_attr( $id( 'author_handle' ) ); ?>"><?php esc_html_e( 'Handle', 'social-webmention-importer' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $id( 'author_handle' ) ); ?>" name="<?php echo esc_attr( $field( 'author_handle' ) ); ?>"
							value="<?php echo esc_attr( $record->author_handle ); ?>" />
					</p>
					<p>
						<label for="<?php echo esc_attr( $id( 'author_url' ) ); ?>"><?php esc_html_e( 'Author profile URL', 'social-webmention-importer' ); ?></label>
						<input type="url" id="<?php echo esc_attr( $id( 'author_url' ) ); ?>" name="<?php echo esc_attr( $field( 'author_url' ) ); ?>"
							value="<?php echo esc_attr( $record->author_url ); ?>" />
					</p>
					<p class="swi-avatar-field">
						<label for="<?php echo esc_attr( $id( 'avatar' ) ); ?>"><?php esc_html_e( 'Avatar URL or Media Library ID', 'social-webmention-importer' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $id( 'avatar' ) ); ?>" name="<?php echo esc_attr( $field( 'avatar' ) ); ?>"
							value="<?php echo esc_attr( $record->avatar ); ?>" data-swi-avatar-input />
						<button type="button" class="button" data-swi-media-picker="<?php echo esc_attr( $id( 'avatar' ) ); ?>"><?php esc_html_e( 'Choose from Media Library', 'social-webmention-importer' ); ?></button>
						<?php if ( $record->avatar && ! is_numeric( $record->avatar ) ) : ?>
							<img src="<?php echo esc_url( $record->avatar ); ?>" alt="" width="48" height="48" class="swi-avatar-preview" />
						<?php endif; ?>
					</p>
					<p>
						<label for="<?php echo esc_attr( $id( 'published_gmt' ) ); ?>"><?php esc_html_e( 'Published (GMT, YYYY-MM-DD HH:MM:SS)', 'social-webmention-importer' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $id( 'published_gmt' ) ); ?>" name="<?php echo esc_attr( $field( 'published_gmt' ) ); ?>"
							value="<?php echo esc_attr( $record->published_gmt ); ?>" placeholder="<?php echo esc_attr( current_time( 'mysql', true ) ); ?>" />
					</p>
					<p>
						<label for="<?php echo esc_attr( $id( 'response_type' ) ); ?>"><?php esc_html_e( 'Response type', 'social-webmention-importer' ); ?></label>
						<select id="<?php echo esc_attr( $id( 'response_type' ) ); ?>" name="<?php echo esc_attr( $field( 'response_type' ) ); ?>">
							<?php foreach ( Comment_Importer::allowed_response_types( $record->mode ) as $type ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $record->response_type, $type ); ?>><?php echo esc_html( $type ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				</div>

				<p>
					<label for="<?php echo esc_attr( $id( 'content' ) ); ?>"><?php esc_html_e( 'Response text', 'social-webmention-importer' ); ?> <span class="swi-required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( '(required for replies)', 'social-webmention-importer' ); ?></span></label>
					<textarea id="<?php echo esc_attr( $id( 'content' ) ); ?>" name="<?php echo esc_attr( $field( 'content' ) ); ?>" rows="4" class="large-text"><?php echo esc_textarea( $record->content ); ?></textarea>
				</p>

				<input type="hidden" name="<?php echo esc_attr( $field( 'mode' ) ); ?>" value="<?php echo esc_attr( $record->mode ); ?>" />

				<?php if ( $mode_is_curated ) : ?>
					<p>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $field( 'confirm_curated' ) ); ?>" value="1" />
							<?php esc_html_e( 'I confirm importing this as a curated social response. It is not a Webmention: the source does not link to the article, and it will be labeled as imported from the network with a link to the original.', 'social-webmention-importer' ); ?>
						</label>
					</p>
				<?php endif; ?>

				<p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $field( 'remember_identity' ) ); ?>" value="1" />
						<?php esc_html_e( 'Use this identity for future imports — saves the name, profile URL, and avatar for this handle so later previews are pre-filled with your corrections.', 'social-webmention-importer' ); ?>
					</label>
				</p>

			<?php endif; ?>
		</fieldset>
		<?php
	}

	/**
	 * Step 3: per-row result report.
	 *
	 * @param string $token Results token.
	 */
	protected static function render_results( $token ) {
		$data = get_transient( 'swi_batch_' . get_current_user_id() . '_results_' . $token );

		if ( ! is_array( $data ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This report expired. The imports themselves are unaffected.', 'social-webmention-importer' ) . '</p></div>';
			self::render_start();
			return;
		}
		?>
		<h2><?php esc_html_e( 'Step 3 of 3 — import report', 'social-webmention-importer' ); ?></h2>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php esc_html_e( 'One result per submitted URL', 'social-webmention-importer' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Source', 'social-webmention-importer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'social-webmention-importer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Comment', 'social-webmention-importer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['results'] as $result ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $result['source_url'] ); ?>" rel="noopener"><?php echo esc_html( $result['source_url'] ); ?></a></td>
						<td>
							<span class="swi-badge swi-badge--<?php echo esc_attr( str_replace( '_', '-', $result['status'] ) ); ?>"><?php echo esc_html( $result['status'] ); ?></span>
							<?php echo esc_html( $result['message'] ); ?>
						</td>
						<td>
							<?php if ( $result['comment_id'] ) : ?>
								<a href="<?php echo esc_url( admin_url( 'comment.php?action=editcomment&c=' . (int) $result['comment_id'] ) ); ?>"><?php esc_html_e( 'Edit comment', 'social-webmention-importer' ); ?></a>
								|
								<a href="<?php echo esc_url( get_comment_link( (int) $result['comment_id'] ) ); ?>"><?php esc_html_e( 'View on site', 'social-webmention-importer' ); ?></a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><a class="button" href="<?php echo esc_url( add_query_arg( 'page', 'social-webmention-importer', admin_url( 'tools.php' ) ) ); ?>"><?php esc_html_e( 'Import more responses', 'social-webmention-importer' ); ?></a></p>
		<?php
	}
}
