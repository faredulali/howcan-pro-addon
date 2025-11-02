<?php
/**
 * WordPress Importer class for managing the import process of a WXR file
 *
 * @package WordPress
 * @subpackage Importer
 */

use WordPress\DataLiberation\URL\WPURL;
use function WordPress\DataLiberation\URL\wp_rewrite_urls;

/**
 * WordPress importer class.
 */
class WP_Import extends WP_Importer {
	public $max_wxr_version = 1.2; // max. supported WXR version

	public $id; // WXR attachment ID

	// information to import from WXR file
	public $version;
	public $authors         = array();
	public $posts           = array();
	public $terms           = array();
	public $categories      = array();
	public $tags            = array();
	public $base_url        = '';
	public $base_url_parsed = null;
	public $site_url_parsed = null;

	// mappings from old information to new
	public $processed_authors    = array();
	public $author_mapping       = array();
	public $processed_terms      = array();
	public $processed_posts      = array();
	public $post_orphans         = array();
	public $processed_menu_items = array();
	public $menu_item_orphans    = array();
	public $missing_menu_items   = array();

	public $fetch_attachments = false;
	public $url_remap         = array();
	public $featured_images   = array();

	/**
	 * Import options.
	 *
	 * @since 0.9.1
	 * @var array
	 */
	public $options = array();

	/**
	 * Registered callback function for the WordPress Importer
	 *
	 * Manages the three separate stages of the WXR import process
	 */
	public function dispatch() {
		$this->header();

		$step = empty( $_GET['step'] ) ? 0 : (int) $_GET['step'];
		switch ( $step ) {
			case 0:
				$this->greet();
				break;
			case 1:
				check_admin_referer( 'import-upload' );
				if ( $this->handle_upload() ) {
					$this->import_options();
				}
				break;
			case 2:
				check_admin_referer( 'import-wordpress' );
				$this->fetch_attachments = ( ! empty( $_POST['fetch_attachments'] ) && $this->allow_fetch_attachments() );
				$this->id                = (int) $_POST['import_id'];
				$file                    = get_attached_file( $this->id );
				set_time_limit( 0 );
				$this->import( $file, array( 'rewrite_urls' => '1' === $_POST['rewrite_urls'] ) );
				break;
		}

		$this->footer();
	}

	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file    Path to the WXR file for importing
	 * @param array  $options Options to control import behavior. Supported:
	 *                       - 'rewrite_urls' (bool) Enable rewriting URLs in post content/excerpt.
	 */
	public function import( $file, $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'rewrite_urls' => false,
			)
		);

		$this->options = apply_filters( 'wp_import_options', $options );

		add_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );

		$this->import_start( $file );

		/**
		 * If URL rewriting was requested but the WP version is too old, report
		 * an error and disable it.
		 *
		 * More context:
		 * WordPress 6.7 introduced WP_HTML_Tag_Processor::set_modifiable_text
		 * required for wp_rewrite_urls to work. We could also offer a graceful
		 * downgrade and support versions down to WordPress 6.5 where the required
		 * WP_HTML_Tag_Processor::get_token_type() method was introduced.
		 *
		 * Alternatively, it might be possible to just rely on the HTML Processor
		 * polyfill shipped with this plugin and make URL rewriting work in any
		 * WordPress version.
		 */
		if ( $this->options['rewrite_urls'] && version_compare( get_bloginfo( 'version' ), '6.7', '<' ) ) {
			echo '<div class="error"><p><strong>' . __( 'URL rewriting requires WordPress 6.7 or newer. The import will continue without rewriting URLs.', 'wordpress-importer' ) . '</strong></p></div>';
			$this->options['rewrite_urls'] = false;
		}
		// URL rewriting is only possible when we have the previous site base URL
		if ( $this->options['rewrite_urls'] && ! $this->base_url_parsed ) {
			$this->options['rewrite_urls'] = false;
		}

		$this->get_author_mapping();

		wp_suspend_cache_invalidation( true );
		$this->process_categories();
		$this->process_tags();
		$this->process_terms();
		$this->process_posts();
		wp_suspend_cache_invalidation( false );

		// update incorrect/missing information in the DB
		$this->backfill_parents();
		$this->backfill_attachment_urls();
		$this->remap_featured_images();

		$this->import_end();
	}

	/**
	 * Parses the WXR file and prepares us for the task of processing parsed data
	 *
	 * @param string $file Path to the WXR file for importing
	 */
	public function import_start( $file ) {
		if ( ! is_file( $file ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
			echo __( 'The file does not exist, please try again.', 'wordpress-importer' ) . '</p>';
			$this->footer();
			die();
		}

		$import_data = $this->parse( $file );

		if ( is_wp_error( $import_data ) ) {
			/** @var WP_Error $import_error */
			$import_error = $import_data;
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
			echo esc_html( $import_error->get_error_message() ) . '</p>';
			$this->footer();
			die();
		}

		$this->version = $import_data['version'];
		$this->get_authors_from_import( $import_data );
		$this->posts      = $import_data['posts'];
		$this->terms      = $import_data['terms'];
		$this->categories = $import_data['categories'];
		$this->tags       = $import_data['tags'];
		$this->base_url   = esc_url( $import_data['base_url'] );

		/**
		 * Add trailing slash to base URL and site URL. Without the trailing slashes,
		 * the WHATWG URL spec tells us compare the parent pathname. For example:
		 *
		 * > is_child_url_of("https://example.com/path", "https://example.com/path-2")
		 * true
		 *
		 * The example above actually ignores the `/path` and `/path-2` parts and only
		 * compares the `example.com` parts.
		 *
		 * With the trailing slashes, the result is false:
		 *
		 * > is_child_url_of("https://example.com/path/", "https://example.com/path-2/")
		 * false
		 *
		 * In this scenario, `/path/` and `/path-2/` are considered in the comparison.
		 */
		$base_url_with_trailing_slash = rtrim( $import_data['base_url'], '/' ) . '/';
		$this->base_url_parsed        = WPURL::parse( $base_url_with_trailing_slash );

		$site_url_with_trailing_slash = rtrim( get_site_url(), '/' ) . '/';
		$this->site_url_parsed        = WPURL::parse( $site_url_with_trailing_slash );

		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		do_action( 'import_start' );
	}

	/**
	 * Performs post-import cleanup of files and the cache
	 */
	public function import_end() {
		wp_import_cleanup( $this->id );

		wp_cache_flush();
		foreach ( get_taxonomies() as $tax ) {
			delete_option( "{$tax}_children" );
			_get_term_hierarchy( $tax );
		}

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		echo '<p>' . __( 'All done.', 'wordpress-importer' ) . ' <a href="' . admin_url() . '">' . __( 'Have fun!', 'wordpress-importer' ) . '</a>' . '</p>';
		echo '<p>' . __( 'Remember to update the passwords and roles of imported users.', 'wordpress-importer' ) . '</p>';

		do_action( 'import_end' );
	}

	/**
	 * Handles the WXR upload and initial parsing of the file to prepare for
	 * displaying author import options
	 *
	 * @return bool False if error uploading or invalid file, true otherwise
	 */
	public function handle_upload() {
		$file = wp_import_handle_upload();

		if ( isset( $file['error'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
			echo esc_html( $file['error'] ) . '</p>';
			return false;
		} elseif ( ! file_exists( $file['file'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
			printf( __( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'wordpress-importer' ), esc_html( $file['file'] ) );
			echo '</p>';
			return false;
		}

		$this->id    = (int) $file['id'];
		$import_data = $this->parse( $file['file'] );
		if ( is_wp_error( $import_data ) ) {
			/** @var WP_Error $import_error */
			$import_error = $import_data;
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
			echo esc_html( $import_error->get_error_message() ) . '</p>';
			return false;
		}

		$this->version = $import_data['version'];
		if ( $this->version > $this->max_wxr_version ) {
			echo '<div class="error"><p><strong>';
			printf( __( 'This WXR file (version %s) may not be supported by this version of the importer. Please consider updating.', 'wordpress-importer' ), esc_html( $import_data['version'] ) );
			echo '</strong></p></div>';
		}

		$this->get_authors_from_import( $import_data );

		return true;
	}

	/**
	 * Retrieve authors from parsed WXR data
	 *
	 * Uses the provided author information from WXR 1.1 files
	 * or extracts info from each post for WXR 1.0 files
	 *
	 * @param array $import_data Data returned by a WXR parser
	 */
	public function get_authors_from_import( $import_data ) {
		if ( ! empty( $import_data['authors'] ) ) {
			$this->authors = $import_data['authors'];
			// no author information, grab it from the posts
		} else {
			foreach ( $import_data['posts'] as $post ) {
				$login = sanitize_user( $post['post_author'], true );
				if ( empty( $login ) ) {
					printf( __( 'Failed to import author %s. Their posts will be attributed to the current user.', 'wordpress-importer' ), esc_html( $post['post_author'] ) );
					echo '<br />';
					continue;
				}

				if ( ! isset( $this->authors[ $login ] ) ) {
					$this->authors[ $login ] = array(
						'author_login'        => $login,
						'author_display_name' => $post['post_author'],
					);
				}
			}
		}
	}

	/**
	 * Display pre-import options, author importing/mapping and option to
	 * fetch attachments
	 */
	public function import_options() {
		$j = 0;
		// phpcs:disable Generic.WhiteSpace.ScopeIndent.Incorrect
		?>
<form action="<?php echo admin_url( 'admin.php?import=wordpress&amp;step=2' ); ?>" method="post">
	<?php wp_nonce_field( 'import-wordpress' ); ?>
	<input type="hidden" name="import_id" value="<?php echo $this->id; ?>" />

<?php if ( ! empty( $this->authors ) ) : ?>
	<h3><?php _e( 'Assign Authors', 'wordpress-importer' ); ?></h3>
	<p><?php _e( 'To make it simpler for you to edit and save the imported content, you may want to reassign the author of the imported item to an existing user of this site, such as your primary administrator account.', 'wordpress-importer' ); ?></p>
		<?php if ( $this->allow_create_users() ) : ?>
	<p><?php printf( __( 'If a new user is created by WordPress, a new password will be randomly generated and the new user&#8217;s role will be set as %s. Manually changing the new user&#8217;s details will be necessary.', 'wordpress-importer' ), esc_html( get_option( 'default_role' ) ) ); ?></p>
	<?php endif; ?>
	<ol id="authors">
		<?php foreach ( $this->authors as $author ) : ?>
		<li><?php $this->author_select( $j++, $author ); ?></li>
	<?php endforeach; ?>
	</ol>
		<?php endif; ?>

<?php if ( $this->allow_fetch_attachments() ) : ?>
	<h3><?php _e( 'Import Attachments', 'wordpress-importer' ); ?></h3>
	<p>
		<input type="checkbox" value="1" name="fetch_attachments" id="import-attachments" />
		<label for="import-attachments"><?php _e( 'Download and import file attachments', 'wordpress-importer' ); ?></label>
	</p>
		<?php endif; ?>

	<h3><?php _e( 'Content Options', 'wordpress-importer' ); ?></h3>
	<p>
		<input type="checkbox" value="1" name="rewrite_urls" id="rewrite-urls" checked="checked" />
		<label for="rewrite-urls"><?php _e( 'Change all imported URLs that currently link to the previous site so that they now link to this site', 'wordpress-importer' ); ?></label>
	</p>

	<p class="submit"><input type="submit" class="button" value="<?php esc_attr_e( 'Submit', 'wordpress-importer' ); ?>" /></p>
</form>
		<?php
		// phpcs:enable Generic.WhiteSpace.ScopeIndent.Incorrect
	}

	/**
	 * Display import options for an individual author. That is, either create
	 * a new user based on import info or map to an existing user
	 *
	 * @param int $n Index for each author in the form
	 * @param array $author Author information, e.g. login, display name, email
	 */
	public function author_select( $n, $author ) {
		_e( 'Import author:', 'wordpress-importer' );
		echo ' <strong>' . esc_html( $author['author_display_name'] );
		if ( '1.0' != $this->version ) {
			echo ' (' . esc_html( $author['author_login'] ) . ')';
		}
		echo '</strong><br />';

		if ( '1.0' != $this->version ) {
			echo '<div style="margin-left:18px">';
		}

		$create_users = $this->allow_create_users();
		if ( $create_users ) {
			echo '<label for="user_new_' . $n . '">';
			if ( '1.0' != $this->version ) {
				_e( 'or create new user with login name:', 'wordpress-importer' );
				$value = '';
			} else {
				_e( 'as a new user:', 'wordpress-importer' );
				$value = esc_attr( sanitize_user( $author['author_login'], true ) );
			}
			echo '</label>';

			echo ' <input type="text" id="user_new_' . $n . '" name="user_new[' . $n . ']" value="' . $value . '" /><br />';
		}

		echo '<label for="imported_authors_' . $n . '">';
		if ( ! $create_users && '1.0' == $this->version ) {
			_e( 'assign posts to an existing user:', 'wordpress-importer' );
		} else {
			_e( 'or assign posts to an existing user:', 'wordpress-importer' );
		}
		echo '</label>';

		echo ' ' . wp_dropdown_users(
			array(
				'name'            => "user_map[$n]",
				'id'              => 'imported_authors_' . $n,
				'multi'           => true,
				'show_option_all' => __( '- Select -', 'wordpress-importer' ),
				'show'            => 'display_name_with_login',
				'echo'            => 0,
			)
		);

		echo '<input type="hidden" name="imported_authors[' . $n . ']" value="' . esc_attr( $author['author_login'] ) . '" />';

		if ( '1.0' != $this->version ) {
			echo '</div>';
		}
	}

	/**
	 * Map old author logins to local user IDs based on decisions made
	 * in import options form. Can map to an existing user, create a new user
	 * or falls back to the current user in case of error with either of the previous
	 */
	public function get_author_mapping() {
		if ( ! isset( $_POST['imported_authors'] ) ) {
			return;
		}

		$create_users = $this->allow_create_users();

		foreach ( (array) $_POST['imported_authors'] as $i => $old_login ) {
			// Multisite adds strtolower to sanitize_user. Need to sanitize here to stop breakage in process_posts.
			$santized_old_login = sanitize_user( $old_login, true );
			$old_id             = isset( $this->authors[ $old_login ]['author_id'] ) ? intval( $this->authors[ $old_login ]['author_id'] ) : false;

			if ( ! empty( $_POST['user_map'][ $i ] ) ) {
				$user = get_userdata( intval( $_POST['user_map'][ $i ] ) );
				if ( isset( $user->ID ) ) {
					if ( $old_id ) {
						$this->processed_authors[ $old_id ] = $user->ID;
					}
					$this->author_mapping[ $santized_old_login ] = $user->ID;
				}
			} elseif ( $create_users ) {
				if ( ! empty( $_POST['user_new'][ $i ] ) ) {
					$user_id = wp_create_user( $_POST['user_new'][ $i ], wp_generate_password() );
				} elseif ( '1.0' != $this->version ) {
					$user_data = array(
						'user_login'   => $old_login,
						'user_pass'    => wp_generate_password(),
						'user_email'   => isset( $this->authors[ $old_login ]['author_email'] ) ? $this->authors[ $old_login ]['author_email'] : '',
						'display_name' => $this->authors[ $old_login ]['author_display_name'],
						'first_name'   => isset( $this->authors[ $old_login ]['author_first_name'] ) ? $this->authors[ $old_login ]['author_first_name'] : '',
						'last_name'    => isset( $this->authors[ $old_login ]['author_last_name'] ) ? $this->authors[ $old_login ]['author_last_name'] : '',
					);
					$user_id   = wp_insert_user( $user_data );
				}

				if ( ! is_wp_error( $user_id ) ) {
					if ( $old_id ) {
						$this->processed_authors[ $old_id ] = $user_id;
					}
					$this->author_mapping[ $santized_old_login ] = $user_id;
				} else {
					printf( __( 'Failed to create new user for %s. Their posts will be attributed to the current user.', 'wordpress-importer' ), esc_html( $this->authors[ $old_login ]['author_display_name'] ) );
					if ( defined( 'IMPORT_DEBUG' ) && IMPORT_DEBUG ) {
						echo ' ' . $user_id->get_error_message();
					}
					echo '<br />';
				}
			}

			// failsafe: if the user_id was invalid, default to the current user
			if ( ! isset( $this->author_mapping[ $santized_old_login ] ) ) {
				if ( $old_id ) {
					$this->processed_authors[ $old_id ] = (int) get_current_user_id();
				}
				$this->author_mapping[ $santized_old_login ] = (int) get_current_user_id();
			}
		}
	}

	/**
	 * Create new categories based on import information
	 *
	 * Doesn't create a new category if its slug already exists
	 */
	public function process_categories() {
		$this->categories = apply_filters( 'wp_import_categories', $this->categories );

		if ( empty( $this->categories ) ) {
			return;
		}

		foreach ( $this->categories as $cat ) {
			// if the category already exists leave it alone
			$term_id = term_exists( $cat['category_nicename'], 'category' );
			if ( $term_id ) {
				if ( is_array( $term_id ) ) {
					$term_id = $term_id['term_id'];
				}
				if ( isset( $cat['term_id'] ) ) {
					$this->processed_terms[ intval( $cat['term_id'] ) ] = (int) $term_id;
				}
				continue;
			}

			$parent      = empty( $cat['category_parent'] ) ? 0 : category_exists( $cat['category_parent'] );
			$description = isset( $cat['category_description'] ) ? $cat['category_description'] : '';

			$data = array(
				'category_nicename'    => $cat['category_nicename'],
				'category_parent'      => $parent,
				'cat_name'             => wp_slash( $cat['cat_name'] ),
				'category_description' => wp_slash( $description ),
			);

			$id = wp_insert_category( $data, true );
			if ( ! is_wp_error( $id ) && $id > 0 ) {
				if ( isset( $cat['term_id'] ) ) {
					$this->processed_terms[ intval( $cat['term_id'] ) ] = $id;
				}
			} else {
				printf( __( 'Failed to import category %s', 'wordpress-importer' ), esc_html( $cat['category_nicename'] ) );
				if ( defined( 'IMPORT_DEBUG' ) && IMPORT_DEBUG ) {
					echo ': ' . $id->get_error_message();
				}
				echo '<br />';
				continue;
			}

			$this->process_termmeta( $cat, $id );
		}

		unset( $this->categories );
	}

	/**
	 * Create new post tags based on import information
	 *
	 * Doesn't create a tag if its slug already exists
	 */
	public function process_tags() {
		$this->tags = apply_filters( 'wp_import_tags', $this->tags );

		if ( empty( $this->tags ) ) {
			return;
		}

		foreach ( $this->tags as $tag ) {
			// if the tag already exists leave it alone
			$term_id = term_exists( $tag['tag_slug'], 'post_tag' );
			if ( $term_id ) {
				if ( is_array( $term_id ) ) {
					$term_id = $term_id['term_id'];
				}
				if ( isset( $tag['term_id'] ) ) {
					$this->processed_terms[ intval( $tag['term_id'] ) ] = (int) $term_id;
				}
				continue;
			}

			$description = isset( $tag['tag_description'] ) ? $tag['tag_description'] : '';
			$args        = array(
				'slug'        => $tag['tag_slug'],
				'description' => wp_slash( $description ),
			);

			$id = wp_insert_term( wp_slash( $tag['tag_name'] ), 'post_tag', $args );
			if ( ! is_wp_error( $id ) ) {
				if ( isset( $tag['term_id'] ) ) {
					$this->processed_terms[ intval( $tag['term_id'] ) ] = $id['term_id'];
				}
			} else {
				printf( __( 'Failed to import post tag %s', 'wordpress-importer' ), esc_html( $tag['tag_name'] ) );
				if ( defined( 'IMPORT_DEBUG' ) && IMPORT_DEBUG ) {
					echo ': ' . $id->get_error_message();
				}
				echo '<br />';
				continue;
	
