<?php
/*
Plugin Name: Infinite Scroll
Plugin URI: http://uzbuz.com
Description: Adds infinite scrolling support to the front-end blog post view for themes, pulling the next set of posts automatically into view when the reader approaches the bottom of the page.
Version: 5
Author: Josua
Author URI: http://uzbuz.com
License: GNU General Public License v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

/**
 * Class: The_Infinite_Scroll relies on add_theme_support, expects specific
 * styling from each theme; including fixed footer.
 */

class The_Infinite_Scroll {
	/**
	 *
	 */
	function __construct() {
		add_filter( 'pre_get_posts',               array( $this, 'posts_per_page_query' ) );
		add_action( 'admin_init',                  array( $this, 'settings_api_init' ) );
		add_action( 'template_redirect',           array( $this, 'action_template_redirect' ) );
		add_action( 'template_redirect',           array( $this, 'ajax_response' ) );
    add_action( 'wp_enqueue_scripts',          array( $this, 'action_enqueue_scripts'), 11);
		add_action( 'custom_ajax_infinite_scroll', array( $this, 'query' ) );
		add_action( 'the_post',                    array( $this, 'preserve_more_tag' ) );
		add_action( 'get_footer',                  array( $this, 'footer' ) );

		// Parse IS settings from theme
		self::get_settings();
	}

	/**
	 * Initialize our static variables
	 */
	static $the_time = null;
	static $settings = null; // Don't access directly, instead use self::get_settings().

	static $option_name_enabled = 'infinite_scroll';

	/**
	 * Parse IS settings provided by theme
	 *
	 * @uses get_theme_support, infinite_scroll_has_footer_widgets, sanitize_title, add_action, get_option, wp_parse_args, is_active_sidebar
	 * @return object
	 */
	static function get_settings() {
		if ( is_null( self::$settings ) ) {
			$css_pattern = '#[^A-Z\d\-_]#i';

			$settings = $defaults = array(
				'type'           => 'scroll', // scroll | click
				'requested_type' => 'scroll', // store the original type for use when logic overrides it
				'footer_widgets' => false, // true | false | sidebar_id | array of sidebar_ids -- last two are checked with is_active_sidebar
				'container'      => 'content', // container html id
				'wrapper'        => true, // true | false | html class
				'render'         => false, // optional function, otherwise the `content` template part will be used
				'footer'         => true, // boolean to enable or disable the infinite footer | string to provide an html id to derive footer width from
				'posts_per_page' => false // int | false to set based on IS type
			);

			// Validate settings passed through add_theme_support()
			$_settings = get_theme_support( 'infinite-scroll' );

			if ( is_array( $_settings ) ) {
				// Preferred implementation, where theme provides an array of options
				if ( isset( $_settings[0] ) && is_array( $_settings[0] ) ) {
					foreach ( $_settings[0] as $key => $value ) {
						switch ( $key ) {
							case 'type' :
								if ( in_array( $value, array( 'scroll', 'click' ) ) )
									$settings[ $key ] = $settings['requested_type'] = $value;

								break;

							case 'footer_widgets' :
								if ( is_string( $value ) )
									$settings[ $key ] = sanitize_title( $value );
								elseif ( is_array( $value ) )
									$settings[ $key ] = array_map( 'sanitize_title', $value );
								elseif ( is_bool( $value ) )
									$settings[ $key ] = $value;

								break;

							case 'container' :
							case 'wrapper' :
								if ( 'wrapper' == $key && is_bool( $value ) ) {
									$settings[ $key ] = $value;
								}
								else {
									$value = preg_replace( $css_pattern, '', $value );

									if ( ! empty( $value ) )
										$settings[ $key ] = $value;
								}

								break;

							case 'render' :
								if ( false !== $value && is_callable( $value ) ) {
									$settings[ $key ] = $value;

									add_action( 'infinite_scroll_render', $value );
								}

								break;

							case 'footer' :
								if ( is_bool( $value ) ) {
									$settings[ $key ] = $value;
								}
								elseif ( is_string( $value ) ) {
									$value = preg_replace( $css_pattern, '', $value );

									if ( ! empty( $value ) )
										$settings[ $key ] = $value;
								}

								break;

							case 'posts_per_page' :
								if ( is_numeric( $value ) )
									$settings[ $key ] = (int) $value;

								break;

              // Add our custom posts arguments
							case 'posts_args' :
									$settings[ $key ] = $value;

								break;

							default:
								continue;

								break;
						}
					}
				}
				// Checks below are for backwards compatibility
				elseif ( is_string( $_settings[0] ) ) {
					// Container to append new posts to
					$settings['container'] = preg_replace( $css_pattern, '', $_settings[0] );

					// Wrap IS elements?
					if ( isset( $_settings[1] ) )
						$settings['wrapper'] = (bool) $_settings[1];
				}
			}

			// Always ensure all values are present in the final array
			$settings = wp_parse_args( $settings, $defaults );

			// Check if a legacy `infinite_scroll_has_footer_widgets()` function is defined and override the footer_widgets parameter's value.
			// Otherwise, if a widget area ID or array of IDs was provided in the footer_widgets parameter, check if any contains any widgets.
			// It is safe to use `is_active_sidebar()` before the sidebar is registered as this function doesn't check for a sidebar's existence when determining if it contains any widgets.
			if ( function_exists( 'infinite_scroll_has_footer_widgets' ) ) {
				$settings['footer_widgets'] = (bool) infinite_scroll_has_footer_widgets();
			}
			elseif ( is_array( $settings['footer_widgets'] ) ) {
				$sidebar_ids = $settings['footer_widgets'];
				$settings['footer_widgets'] = false;

				foreach ( $sidebar_ids as $sidebar_id ) {
					if ( is_active_sidebar( $sidebar_id ) ) {
						$settings['footer_widgets'] = true;
						break;
					}
				}

				unset( $sidebar_ids );
				unset( $sidebar_id );
			}
			elseif ( is_string( $settings['footer_widgets'] ) ) {
				$settings['footer_widgets'] = (bool) is_active_sidebar( $settings['footer_widgets'] );
			}

			// For complex logic, let themes filter the `footer_widgets` parameter.
			$settings['footer_widgets'] = apply_filters( 'infinite_scroll_has_footer_widgets', $settings['footer_widgets'] );

			// Finally, after all of the sidebar checks and filtering, ensure that a boolean value is present, otherwise set to default of `false`.
			if ( ! is_bool( $settings['footer_widgets'] ) )
				$settings['footer_widgets'] = false;

			// Ensure that IS is enabled and no footer widgets exist if the IS type isn't already "click".
			if ( 'click' != $settings['type'] ) {
				// Check the setting status
				$disabled = '' === get_option( self::$option_name_enabled ) ? true : false;

				// Footer content or Reading option check
				if ( $settings['footer_widgets'] || $disabled )
					$settings['type'] = 'click';
			}

			// Backwards compatibility for posts_per_page setting
			if ( false === $settings['posts_per_page'] )
				$settings['posts_per_page'] = 'click' == $settings['type'] ? (int) get_option( 'posts_per_page' ) : 7;

			// Store final settings in a class static to avoid reparsing
			self::$settings = apply_filters( 'infinite_scroll_settings', $settings );
		}

		return (object) self::$settings;
	}

	/**
	 * Has infinite scroll been triggered?
	 */
	static function got_infinity() {
		return isset( $_GET[ 'infinity' ] );
	}

	/**
	 * The more tag will be ignored by default if the blog page isn't our homepage.
	 * Let's force the $more global to false.
	 */
	function preserve_more_tag( $array ) {
		global $more;

		if ( self::got_infinity() )
			$more = 0; //0 = show content up to the more tag. Add more link.

		return $array;
	}

	/**
	 * Add a checkbox field to Settings > Reading
	 * for enabling infinite scroll.
	 *
	 * Only show if the current theme supports infinity.
	 *
	 * @uses current_theme_supports, add_settings_field, __, register_setting
	 * @action admin_init
	 * @return null
	 */
	function settings_api_init() {
		if ( ! current_theme_supports( 'infinite-scroll' ) )
			return;

		// Add the setting field [infinite_scroll] and place it in Settings > Reading
		add_settings_field( self::$option_name_enabled, '<span id="infinite-scroll-options">' . __( 'To infinity and beyond', 'jetpack' ) . '</span>', array( $this, 'infinite_setting_html' ), 'reading' );
		register_setting( 'reading', self::$option_name_enabled, 'esc_attr' );
	}

	/**
	 * HTML code to display a checkbox true/false option
	 * for the infinite_scroll setting.
	 */
	function infinite_setting_html() {
		$notice = '<em>' . __( "We've disabled this option for you since you have footer widgets in Appearance &rarr; Widgets, or because your theme does not support infinite scroll.", 'jetpack' ) . '</em>';

		// If the blog has footer widgets, show a notice instead of the checkbox
		if ( self::get_settings()->footer_widgets || 'click' == self::get_settings()->requested_type ) {
			echo '<label>' . $notice . '</label>';
		} else {
			echo '<label><input name="infinite_scroll" type="checkbox" value="1" ' . checked( 1, '' !== get_option( self::$option_name_enabled ), false ) . ' /> ' . __( 'Scroll Infinitely', 'jetpack' ) . '</br><small>' . sprintf( __( '(Shows %s posts on each load)', 'jetpack' ), number_format_i18n( self::get_settings()->posts_per_page ) ) . '</small>' . '</label>';
		}
	}

	/**
	 * Does the legwork to determine whether the feature is enabled.
	 *
	 * @uses current_theme_supports, self::archive_supports_infinity, self::get_settings, self::set_last_post_time, add_filter, wp_enqueue_script, plugins_url, wp_enqueue_style, add_action
	 * @action template_redirect
	 * @return null
	 */
	function action_template_redirect() {
    echo current_theme_supports( 'infinite-scroll' );
		// Check that we support infinite scroll, and are on the home page.
		if ( ! current_theme_supports( 'infinite-scroll' ) || ! self::archive_supports_infinity() )
			return;

		$id = self::get_settings()->container;

		// Check that we have an id.
		if ( empty( $id ) )
			return;

    // Force set time for custom arguments
    if(self::$settings['posts_args']){
      $infinity_posts = array();

      $post_type = self::$settings['posts_args']['post_type'];
      if((is_array($post_type) && in_array('comment', $post_type) || !is_array($post_type) && $post_type=='comment')){
        $args = (self::$settings['posts_args']['comment_args'])?self::$settings['posts_args']['comment_args']:array(
          'orderby' => 'modified',
          'order'   => 'DESC'
        );
        $args['number'] = null; // Load all comments
        $exclude = (isset($args['exclude']))?$args['exclude']:'';
        unset($args['exclude']);
        unset($args['success_callback']);
        $comments = get_comments($args);
        if(count($comments)>0) {
          foreach($comments as $comment){
            $post = new StdClass();
            $post->ID = intval($comment->comment_ID);
            $post->post_title = $comment->comment_author;
            $post->post_type = 'comment';
            $post->post_date = $comment->comment_date_gmt;
            $post->post_date_gmt = $comment->comment_date_gmt;
            $post->post_modified_gmt = $comment->comment_date_gmt;
            $post->post_status = ($comment->comment_approved==1)?'approved':'pending';
            $post->post_author = get_current_user_id();
            $post->post_content = $comment->comment_content;
            $included = true;
            if(isset($exclude) && is_array($exclude) && count($exclude)>0){
              foreach($exclude as $key=>$ex){
                switch($key){
                  case 'comment_meta':
                    if(is_array($ex) && count($ex)>0){
                      foreach($ex as $cm){
                        ${$cm['name']} = get_comment_meta($comment->comment_ID, $cm['name'], true);
                        if($cm['compare']=='='){
                          if(${$cm['name']}==$cm['value']) $included = false;
                        }
                        else if($cm['compare']=='!='){
                          if(${$cm['name']}!=$cm['value']) $included = false;
                        }
                      }
                    }
                    break;
                }
              }
            }
            if($included) array_push($infinity_posts, $post);
          }
        }
      }

      $args = self::$settings['posts_args'];
      $exclude = (isset($args['exclude']))?$args['exclude']:'';
      unset($args['exclude']);
      $args['posts_per_page'] = -1; // Load all posts
      $loop = new WP_Query($args);
      $posts = $loop->posts;
      unset($loop);
      if(count($posts)>0) {
        foreach($posts as $post){
          $included = true;
          if(isset($exclude) && is_array($exclude) && count($exclude)>0){
            foreach($exclude as $key=>$ex){
              switch($key){
                case 'post_meta':
                  if(is_array($ex) && count($ex)>0){
                    foreach($ex as $pm){
                      ${$pm['name']} = get_post_meta($post->ID, $pm['name'], true);
                      if($pm['compare']=='='){
                        if(${$pm['name']}==$pm['value']) $included = false;
                      }
                      else if($pm['compare']=='!='){
                        if(${$pm['name']}!=$pm['value']) $included = false;
                      }
                    }
                  }
                  break;
              }
            }
          }
          if($included) array_push($infinity_posts, $post);
        }
        unset($posts);
      }
      if(count($infinity_posts>0)){
        $last_post = end($infinity_posts);
        unset($infinity_posts);
        self::$the_time = (isset($last_post->post_modified_gmt)?$last_post->post_modified_gmt:'');
      }
    }

		// Bail if there are not enough posts for infinity.
		if ( ! self::set_last_post_time() )
			return;

		// Add a class to the body.
		add_filter( 'body_class', array( $this, 'body_class' ) );

		// Add our scripts.
		wp_enqueue_script( 'the-neverending-homepage', plugins_url( 'infinity.js', __FILE__ ), array( 'jquery' ), '20121205' );

		// Add our default styles.
		wp_enqueue_style( 'the-neverending-homepage', plugins_url( 'infinity.css', __FILE__ ), array(), '20120612' );

		add_action( 'wp_head', array( $this, 'action_wp_head' ), 2 );

		add_action( 'wp_footer', array( $this, 'action_wp_footer' ), 99999999 );

		add_filter( 'infinite_scroll_results', array( $this, 'filter_infinite_scroll_results' ) );
	}

	/**
	 * Enqueue spinner scripts.
	 */
	function action_enqueue_scripts() {
    if(!wp_script_is('toolbox-spinner')){
      wp_enqueue_script('toolbox-spinner', plugins_url('spinner.min.js', __FILE__), array('jquery'), '20121205');
    }
	}

	/**
	 * Adds an 'infinite-scroll' class to the body.
	 */
	function body_class( $classes ) {
		$classes[] = 'infinite-scroll';

		if ( 'scroll' == self::get_settings()->type )
			$classes[] = 'neverending';

		return $classes;
	}

	/**
	 * Grab the timestamp for the last post.
	 * @return string 'Y-m-d H:i:s' or null
	 */
	function set_last_post_time( $date = false ) {
		global $posts;
		$count = count( $posts );

		if ( ! empty( $date ) && preg_match( '|\d{4}\-\d{2}\-\d{2}|', $_GET['date'] ) ) {
			self::$the_time = "$date 00:00:00";
			return self::$the_time;
		}

		// If we don't have enough posts for infinity, return early
		if ( ! $count || $count < self::get_settings()->posts_per_page )
			return self::$the_time;

		$last_post = end( $posts );

		// If the function is called again but we already have a value, return it
		if ( null != self::$the_time ) {
			return self::$the_time;
		}
		else if ( isset( $last_post->post_date_gmt ) ) {
			// Grab the latest post time in Y-m-d H:i:s gmt format
			self::$the_time = $last_post->post_date_gmt;
		}

		return self::$the_time;
	}

	/**
	 * Create a where clause that will make sure post queries
	 * will always return results prior to (descending sort)
	 * or before (ascending sort) the last post date.
	 *
	 * @param string $where
	 * @param object $query
	 * @filter posts_where
	 * @return string
	 */
	function query_time_filter( $where, $query ) {
		global $wpdb;

		$operator = 'ASC' == $query->get( 'order' ) ? '>' : '<';

		// Construct the date query using our timestamp
		$where .= $wpdb->prepare( " AND post_date_gmt {$operator} %s", self::set_last_post_time() );

		return $where;
	}

	/**
	 * Let's overwrite the default post_per_page setting to always display a fixed amount.
	 *
	 * @global $wp_the_query Used to provide compatibility back to WP 3.2
	 * @param object $query
	 * @uses self::archive_supports_infinity, self::get_settings
	 * @return null
	 */
	function posts_per_page_query( $query ) {
    // Run this only if the location is outside admin,
    // otherwise the modification to $wp_the_query may cause some unwanted bug
    if(!function_exists('get_current_screen')){
      global $wp_the_query;

      if ( self::archive_supports_infinity() && $query === $wp_the_query ) // After 3.3, this line would be: if ( self::archive_supports_infinity() && $query->is_main_query() )
        $query->set( 'posts_per_page', self::get_settings()->posts_per_page );
    }
	}

	/**
	 * Check if the IS output should be wrapped in a div.
	 * Setting value can be a boolean or a string specifying the class applied to the div.
	 *
	 * @uses self::get_settings
	 * @return bool
	 */
	function has_wrapper() {
		return (bool) self::get_settings()->wrapper;
	}

	/**
	 * Returns the Ajax url
	 */
	function ajax_url() {
		global $wp;

		$base_url = home_url( trailingslashit( $wp->request ), is_ssl() ? 'https' : 'http' );

		$ajaxurl = add_query_arg( array( 'infinity' => 'scrolling' ), $base_url );

		return apply_filters( 'infinite_scroll_ajax_url', $ajaxurl );
	}

	/**
	 * Our own Ajax response, avoiding calling admin-ajax
	 */
	function ajax_response() {
		// Only proceed if the url query has a key of "Infinity"
		if ( ! self::got_infinity() )
			return false;

		define( 'DOING_AJAX', true );

		@header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		send_nosniff_header();

		do_action( 'custom_ajax_infinite_scroll' );
		die( '0' );
	}

	/**
	 * Prints the relevant infinite scroll settings in JS.
	 *
	 * @uses self::get_settings, esc_js, esc_url_raw, self::has_wrapper, __, apply_filters, do_action
	 * @action wp_head
	 * @return string
	 */
	function action_wp_head() {
		global $wp_query, $wp_rewrite;

		// Base JS settings
		$js_settings = array(
			'id'               => self::get_settings()->container,
			'ajaxurl'          => esc_js( esc_url_raw( self::ajax_url() ) ),
			'type'             => esc_js( self::get_settings()->type ),
			'wrapper'          => self::has_wrapper(),
			'wrapper_class'    => is_string( self::get_settings()->wrapper ) ? esc_js( self::get_settings()->wrapper ) : 'infinite-wrap',
			'footer'           => is_string( self::get_settings()->footer ) ? esc_js( self::get_settings()->footer ) : self::get_settings()->footer,
			'text'             => esc_js( __( 'Load more posts', 'jetpack' ) ),
			'totop'            => esc_js( __( 'Scroll back to top', 'jetpack' ) ),
			'order'            => 'DESC',
			'scripts'          => array(),
			'styles'           => array(),
			'google_analytics' => false,
			'offset'           => $wp_query->get( 'paged' ),
			'history'          => array(
				'host'                 => preg_replace( '#^http(s)?://#i', '', untrailingslashit( get_option( 'home' ) ) ),
				'path'                 => self::get_request_path(),
				'use_trailing_slashes' => $wp_rewrite->use_trailing_slashes
			)
		);

    // Remove the scripts and styles parameters for custom post infinite-scroll
    if(self::$settings['posts_args']){
      unset($js_settings['scripts']);
      unset($js_settings['styles']);
    }

		// Optional order param
		if ( isset( $_GET['order'] ) ) {
			$order = strtoupper( $_GET['order'] );

			if ( in_array( $order, array( 'ASC', 'DESC' ) ) )
				$js_settings['order'] = $order;
		}

		$js_settings = apply_filters( 'infinite_scroll_js_settings', $js_settings );

		do_action( 'infinite_scroll_wp_head' );

		?>
		<script type="text/javascript">
		//<![CDATA[
		var infiniteScroll = <?php echo json_encode( array( 'settings' => $js_settings ) ); ?>;
    <?php if(isset(self::$settings['posts_args']['success_callback'])){ ?>
      infiniteScroll.success_callback = <?=self::$settings['posts_args']['success_callback']?>
    <?php } ?>
		//]]>
		</script>
		<?php
	}

	/**
	 * Build path data for current request.
	 * Used for Google Analytics and pushState history tracking.
	 *
	 * @global $wp_rewrite
	 * @global $wp
	 * @uses user_trailingslashit, sanitize_text_field, add_query_arg
	 * @return string|bool
	 */
	private function get_request_path() {
		global $wp_rewrite;

		if ( $wp_rewrite->using_permalinks() ) {
			global $wp;

			// If called too early, bail
			if ( ! isset( $wp->request ) )
				return false;

			// Determine path for paginated version of current request
			if ( false != preg_match( '#' . $wp_rewrite->pagination_base . '/\d+/?$#i', $wp->request ) )
				$path = preg_replace( '#' . $wp_rewrite->pagination_base . '/\d+$#i', $wp_rewrite->pagination_base . '/%d', $wp->request );
			else
				$path = $wp->request . '/' . $wp_rewrite->pagination_base . '/%d';

			// Slashes everywhere we need them
			if ( 0 !== strpos( $path, '/' ) )
				$path = '/' . $path;

			$path = user_trailingslashit( $path );
		}
		else {
			// Clean up raw $_GET input
			$path = array_map( 'sanitize_text_field', $_GET );
			$path = array_filter( $path );

			$path['paged'] = '%d';

			$path = add_query_arg( $path, '/' );
		}

		return empty( $path ) ? false : $path;
	}

	/**
	 * Provide IS with a list of the scripts and stylesheets already present on the page.
	 * Since posts may contain require additional assets that haven't been loaded, this data will be used to track the additional assets.
	 *
	 * @global $wp_scripts, $wp_styles
	 * @action wp_footer
	 * @return string
	 */
	function action_wp_footer() {
		global $wp_scripts, $wp_styles;

		$scripts = is_a( $wp_scripts, 'WP_Scripts' ) ? $wp_scripts->done : array();
		$styles = is_a( $wp_styles, 'WP_Styles' ) ? $wp_styles->done : array();

		?><script type="text/javascript">
			jQuery.extend( infiniteScroll.settings.scripts, <?php echo json_encode( $scripts ); ?> );
			jQuery.extend( infiniteScroll.settings.styles, <?php echo json_encode( $styles ); ?> );
		</script><?php
	}

	/**
	 * Identify additional scripts required by the latest set of IS posts and provide the necessary data to the IS response handler.
	 *
	 * @global $wp_scripts
	 * @uses sanitize_text_field, add_query_arg
	 * @filter infinite_scroll_results
	 * @return array
	 */
	function filter_infinite_scroll_results( $results ) {
		// Don't bother unless there are posts to display
		if ( 'success' != $results['type'] )
			return $results;

		// Parse and sanitize the script handles already output
		$initial_scripts = isset( $_GET['scripts'] ) && is_array( $_GET['scripts'] ) ? array_map( 'sanitize_text_field', $_GET['scripts'] ) : false;

		if ( is_array( $initial_scripts ) ) {
			global $wp_scripts;

			// Identify new scripts needed by the latest set of IS posts
			$new_scripts = array_diff( $wp_scripts->done, $initial_scripts );

			// If new scripts are needed, extract relevant data from $wp_scripts
			if ( ! empty( $new_scripts ) ) {
				$results['scripts'] = array();

				foreach ( $new_scripts as $handle ) {
					// Abort if somehow the handle doesn't correspond to a registered script
					if ( ! isset( $wp_scripts->registered[ $handle ] ) )
						continue;

					// Provide basic script data
					$script_data = array(
						'handle'     => $handle,
						'footer'     => ( is_array( $wp_scripts->in_footer ) && in_array( $handle, $wp_scripts->in_footer ) ),
						'extra_data' => $wp_scripts->print_extra_script( $handle, false )
					);

					// Base source
					$src = $wp_scripts->registered[ $handle ]->src;

					// Take base_url into account
					if ( strpos( $src, 'http' ) !== 0 )
						$src = $wp_scripts->base_url . $src;

					// Version and additional arguments
					if ( null === $wp_scripts->registered[ $handle ]->ver )
						$ver = '';
					else
						$ver = $wp_scripts->registered[ $handle ]->ver ? $wp_scripts->registered[ $handle ]->ver : $wp_scripts->default_version;

					if ( isset($wp_scripts->args[ $handle ] ) )
						$ver = $ver ? $ver . '&amp;' . $wp_scripts->args[$handle] : $wp_scripts->args[$handle];

					// Full script source with version info
					$script_data['src'] = add_query_arg( 'ver', $ver, $src );

					// Add script to data that will be returned to IS JS
					array_push( $results['scripts'], $script_data );
				}
			}
		}

		// Parse and sanitize the style handles already output
		$initial_styles = isset( $_GET['styles'] ) && is_array( $_GET['styles'] ) ? array_map( 'sanitize_text_field', $_GET['styles'] ) : false;

		if ( is_array( $initial_styles ) ) {
			global $wp_styles;

			// Identify new styles needed by the latest set of IS posts
			$new_styles = array_diff( $wp_styles->done, $initial_styles );

			// If new styles are needed, extract relevant data from $wp_styles
			if ( ! empty( $new_styles ) ) {
				$results['styles'] = array();

				foreach ( $new_styles as $handle ) {
					// Abort if somehow the handle doesn't correspond to a registered stylesheet
					if ( ! isset( $wp_styles->registered[ $handle ] ) )
						continue;

					// Provide basic style data
					$style_data = array(
						'handle' => $handle,
						'media'  => 'all'
					);

					// Base source
					$src = $wp_styles->registered[ $handle ]->src;

					// Take base_url into account
					if ( strpos( $src, 'http' ) !== 0 )
						$src = $wp_styles->base_url . $src;

					// Version and additional arguments
					if ( null === $wp_styles->registered[ $handle ]->ver )
						$ver = '';
					else
						$ver = $wp_styles->registered[ $handle ]->ver ? $wp_styles->registered[ $handle ]->ver : $wp_styles->default_version;

					if ( isset($wp_styles->args[ $handle ] ) )
						$ver = $ver ? $ver . '&amp;' . $wp_styles->args[$handle] : $wp_styles->args[$handle];

					// Full stylesheet source with version info
					$style_data['src'] = add_query_arg( 'ver', $ver, $src );

					// Parse stylesheet's conditional comments if present, converting to logic executable in JS
					if ( isset( $wp_styles->registered[ $handle ]->extra['conditional'] ) && $wp_styles->registered[ $handle ]->extra['conditional'] ) {
						// First, convert conditional comment operators to standard logical operators. %ver is replaced in JS with the IE version
						$style_data['conditional'] = str_replace( array(
							'lte',
							'lt',
							'gte',
							'gt'
						), array(
							'%ver <=',
							'%ver <',
							'%ver >=',
							'%ver >',
						), $wp_styles->registered[ $handle ]->extra['conditional'] );

						// Next, replace any !IE checks. These shouldn't be present since WP's conditional stylesheet implementation doesn't support them, but someone could be _doing_it_wrong().
						$style_data['conditional'] = preg_replace( '#!\s*IE(\s*\d+){0}#i', '1==2', $style_data['conditional'] );

						// Lastly, remove the IE strings
						$style_data['conditional'] = str_replace( 'IE', '', $style_data['conditional'] );
					}

					// Parse requested media context for stylesheet
					if ( isset( $wp_styles->registered[ $handle ]->args ) )
						$style_data['media'] = esc_attr( $wp_styles->registered[ $handle ]->args );

					// Add stylesheet to data that will be returned to IS JS
					array_push( $results['styles'], $style_data );
				}
			}
		}

		// Lastly, return the IS results array
		return $results;
	}

	/**
	 * Runs the query and returns the results via JSON.
	 * Triggered by an AJAX request.
	 *
	 * @global $wp_query
	 * @global $wp_the_query
	 * @uses current_user_can, get_option, self::set_last_post_time, current_user_can, apply_filters, self::get_settings, add_filter, WP_Query, remove_filter, have_posts, wp_head, do_action, add_action, this::render, this::has_wrapper, esc_attr, wp_footer, sharing_register_post_for_share_counts, get_the_id
	 * @return string or null
	 */
	function query() {
		global $wp_query, $wp_the_query;

    // Sometimes page=1 is redirected by wordpress to /1/. We need to trick it.
    if(!isset($_GET['page']) && basename($_SERVER['REDIRECT_URL'])) $_GET['page']=basename($_SERVER['REDIRECT_URL']);

		if ( ! isset( $_GET['page'] ) || ! current_theme_supports( 'infinite-scroll' ) )
			die;

		$page = (int) $_GET['page'];
		$sticky = get_option( 'sticky_posts' );

		if ( ! empty( $_GET['date'] ) )
			self::set_last_post_time( $_GET['date'] );

		$post_status = array( 'publish' );
		if ( current_user_can( 'read_private_posts' ) )
			array_push( $post_status, 'private' );

		$order = in_array( $_GET['order'], array( 'ASC', 'DESC' ) ) ? $_GET['order'] : 'DESC';

    $custom_fetch_with_comments = false;
    // When using custom arguments, replace default settings with our settings
    if(self::get_settings()->posts_args){
      $wp_the_query->query_vars = self::get_settings()->posts_args;
      $post_type = self::get_settings()->posts_args['post_type'];
      if((is_array($post_type) && in_array('comment', $post_type) || !is_array($post_type) && $post_type=='comment')){
        $custom_fetch_with_comments = true;
      }
    }

    /*
     * Modify a little bit, if the args has post type comment,
     * but please preserve original plugin functionality
     */
    if(self::get_settings()->posts_args){
      $infinity_posts = array();

      if($custom_fetch_with_comments){
        $args = (self::$settings['posts_args']['comment_args'])?self::$settings['posts_args']['comment_args']:array(
          'orderby' => 'modified',
          'order'   => 'DESC'
        );
        $args['number'] = null; // Load all comments
        $comments_exclude = (isset($args['exclude']))?$args['exclude']:'';
        unset($args['exclude']);
        unset($args['success_callback']);
        $comments = get_comments($args);
        /*
         * Make comments as posts
         */
        if(count($comments)>0) {
          foreach($comments as $comment){
            $post = new StdClass();
            $post->ID = intval($comment->comment_ID);
            $post->post_title = $comment->comment_author;
            $post->post_type = 'comment';
            $post->post_date = $comment->comment_date;
            $post->post_date_gmt = $comment->comment_date_gmt;
            $post->post_modified_gmt = $comment->comment_date_gmt;
            $post->post_status = ($comment->comment_approved==1)?'approved':'pending';
            $post->post_author = get_current_user_id();
            $post->post_content = $comment->comment_content;
            array_push($infinity_posts, $post);
          }
          unset($loaded_posts);
        }
      }

      $args = self::$settings['posts_args'];
      $posts_exclude = (isset($args['exclude']))?$args['exclude']:'';
      unset($args['exclude']);
      $args['posts_per_page'] = -1; // Load all posts
      $loop = new WP_Query($args);
      $posts = $loop->posts;
      if(count($posts)>0) {
        foreach($posts as $post){
          array_push($infinity_posts, $post);
        }
        unset($posts);
      }

      $loaded_posts = array();
      $per_page = self::$settings['posts_args']['posts_per_page'];
      if(count($infinity_posts)>0){
        function isort($a,$b) {
          return strcmp($a->post_modified_gmt, $b->post_modified_gmt)<0;
        }
        usort($infinity_posts, "isort");

        if($custom_fetch_with_comments){
          $projects_count = 0;
          $reviews_count = 0;
          $init_fetch = array();
          for($i=0;$i<count($infinity_posts) && $i<$per_page;$i++){
            $ip = $infinity_posts[$i];
            if($ip->post_type=="showroom") $projects_count++;
            if($ip->post_type=="comment") $reviews_count++;
            array_push($init_fetch, $ip);
          }
          $next_projects = array();
          $next_reviews = array();
          if($projects_count<$per_page || $reviews_count<$per_page){
            if(count($infinity_posts)>$per_page){
              for($i=$per_page;$i<count($infinity_posts) && ($projects_count<$per_page || $reviews_count<$per_page);$i++){
                $ip = $infinity_posts[$i];
                if($ip->post_type=="showroom" && $projects_count<$per_page){
                  array_push($next_projects, $ip);
                  $projects_count++;
                  unset($infinity_posts[$i]);
                }
                if($ip->post_type=="comment" && $reviews_count<$per_page){
                  array_push($next_reviews, $ip);
                  $reviews_count++;
                  unset($infinity_posts[$i]);
                }
              }
            }
          }
          $reset_infinity = array_merge($infinity_posts);
          $rest_infinity = array();
          if(count($reset_infinity)>10){
            for($i=10;$i<count($reset_infinity);$i++){
              array_push($rest_infinity, $reset_infinity[$i]);
            }
          }
          $infinity_posts = array_merge($init_fetch, $next_projects);
          $infinity_posts = array_merge($infinity_posts, $next_reviews);
          unset($next_projects);
          unset($next_reviews);
          usort($infinity_posts, "isort");
          $infinity_posts = array_merge($infinity_posts, $rest_infinity);
          unset($rest_infinity);
        }

        $count_infinity = count($infinity_posts);
        for($i=$page*$per_page;$i<($page*$per_page)+$per_page && $i<$count_infinity;$i++){
          $included = true;
          if($infinity_posts[$i]->post_type=='comment'){
            if(isset($comments_exclude) && is_array($comments_exclude) && count($comments_exclude)>0){
              foreach($comments_exclude as $key=>$ex){
                switch($key){
                  case 'comment_meta':
                    if(is_array($ex) && count($ex)>0){
                      foreach($ex as $cm){
                        ${$cm['name']} = get_comment_meta($infinity_posts[$i]->ID, $cm['name'], true);
                        if($cm['compare']=='='){
                          if(${$cm['name']}==$cm['value']) $included = false;
                        }
                        else if($cm['compare']=='!='){
                          if(${$cm['name']}!=$cm['value']) $included = false;
                        }
                      }
                    }
                    break;
                }
              }
            }
          } else {
            if(isset($posts_exclude) && is_array($posts_exclude) && count($posts_exclude)>0){
              foreach($posts_exclude as $key=>$ex){
                switch($key){
                  case 'post_meta':
                    if(is_array($ex) && count($ex)>0){
                      foreach($ex as $pm){
                        ${$pm['name']} = get_post_meta($infinity_posts[$i]->ID, $pm['name'], true);
                        if($pm['compare']=='='){
                          if(${$pm['name']}==$pm['value']) $included = false;
                        }
                        else if($pm['compare']=='!='){
                          if(${$pm['name']}!=$pm['value']) $included = false;
                        }
                      }
                    }
                    break;
                }
              }
            }
          }
          if($included) array_push($loaded_posts, $infinity_posts[$i]);
        }
      }

      /*
       * Resemble the actual $wp_query content
       */
      $loop->post_count    = count($loaded_posts);
      $loop->current_post  = -1;
      $loop->found_posts   = count($infinity_posts);
      $loop->max_num_pages = round($loop->found_posts/$per_page);
      $loop->posts         = $loaded_posts;
      $wp_query = $loop;
      unset($loop);
      unset($infinity_posts);
      unset($loaded_posts);
    }
    else {
      $query_args = array_merge( $wp_the_query->query_vars, array(
        'paged'          => $page,
        'post_status'    => $post_status,
        'posts_per_page' => self::get_settings()->posts_per_page,
        'post__not_in'   => ( array ) $sticky,
        'order'          => $order
      ) );

      // By default, don't query for a specific page of a paged post object.
      // This argument comes from merging $wp_the_query.
      // Since IS is only used on archives, we should always display the first page of any paged content.
      unset( $query_args['page'] );

      $query_args = apply_filters( 'infinite_scroll_query_args', $query_args );

      // Add query filter that checks for posts below the date
      add_filter( 'posts_where', array( $this, 'query_time_filter' ), 10, 2 );

      $wp_query = new WP_Query( $query_args );

      remove_filter( 'posts_where', array( $this, 'query_time_filter' ), 10, 2 );
    }

    $results = array();

    if ( have_posts() ) {

      // Fire wp_head to ensure that all necessary scripts are enqueued. Output isn't used, but scripts are extracted in self::action_wp_footer.
      ob_start();
      wp_head();
      ob_end_clean();

      $results['type'] = 'success';

      // First, try theme's specified rendering handler, either specified via `add_theme_support` or by hooking to this action directly.
      ob_start();
      do_action( 'infinite_scroll_render' );
      $results['html'] = ob_get_clean();

      // Fall back if a theme doesn't specify a rendering function. Because themes may hook additional functions to the `infinite_scroll_render` action, `has_action()` is ineffective here.
      if ( empty( $results['html'] ) ) {
        add_action( 'infinite_scroll_render', array( $this, 'render' ) );
        rewind_posts();

        ob_start();
        do_action( 'infinite_scroll_render' );
        $results['html'] = ob_get_clean();
      }

      // If primary and fallback rendering methods fail, prevent further IS rendering attempts. Otherwise, wrap the output if requested.
      if ( empty( $results['html'] ) ) {
        unset( $results['html'] );
        do_action( 'infinite_scroll_empty' );
        $results['type'] = 'empty';
      }
      elseif ( $this->has_wrapper() ) {
        $wrapper_classes = is_string( self::get_settings()->wrapper ) ? self::get_settings()->wrapper : 'infinite-wrap';
        $wrapper_classes .= ' infinite-view-' . $page;
        $wrapper_classes = trim( $wrapper_classes );

        $results['html'] = '<div class="' . esc_attr( $wrapper_classes ) . '" id="infinite-view-' . $page . '" data-page-num="' . $page . '">' . $results['html'] . '</div>';
      }

      // Fire wp_footer to ensure that all necessary scripts are enqueued. Output isn't used, but scripts are extracted in self::action_wp_footer.
      ob_start();
      wp_footer();
      ob_end_clean();

      // Loop through posts to capture sharing data for new posts loaded via Infinite Scroll
      if ( 'success' == $results['type'] && function_exists( 'sharing_register_post_for_share_counts' ) ) {
        global $jetpack_sharing_counts;

        while( have_posts() ) {
          the_post();
          // Fix permalink bug when jetpack loaded
          if(get_permalink(get_the_ID()))
            sharing_register_post_for_share_counts( get_the_ID() );
        }

        $results['postflair'] = array_flip( $jetpack_sharing_counts );
      }
    } else {
      do_action( 'infinite_scroll_empty' );
      $results['type'] = 'empty';
    }
		echo json_encode( apply_filters( 'infinite_scroll_results', $results ) );
		die;
	}

	/**
	 * Rendering fallback used when themes don't specify their own handler.
	 *
	 * @uses have_posts, the_post, get_template_part, get_post_format
	 * @action infinite_scroll_render
	 * @return string
	 */
	function render() {
		while ( have_posts() ) {
			the_post();

			get_template_part( 'content', get_post_format() );
		}
	}

	/**
	 * Allow plugins to filter what archives Infinite Scroll supports
	 *
	 * @uses apply_filters, current_theme_supports, is_home, is_archive, self::get_settings
	 * @return bool
	 */
	public function archive_supports_infinity() {
		return (bool) apply_filters( 'infinite_scroll_archive_supported', current_theme_supports( 'infinite-scroll' ) /*&& ( is_home() || is_archive() )*/, self::get_settings() );
	}

	/**
	 * The Infinite Blog Footer
	 *
	 * @uses self::get_settings, self::set_last_post_time, self::archive_supports_infinity, __, wp_get_theme, get_current_theme, apply_filters, home_url, esc_attr, get_bloginfo, bloginfo
	 * @return string or null
	 */
	function footer() {
		// Bail if theme requested footer not show
		if ( false == self::get_settings()->footer )
			return;

		// Bail if there are not enough posts for infinity.
		if ( ! self::set_last_post_time() )
			return;

		// We only need the new footer for the 'scroll' type
		if ( 'scroll' != self::get_settings()->type || ! self::archive_supports_infinity() )
			return;

		$credits = '<a href="http://wordpress.org/" rel="generator">Proudly powered by WordPress</a> ';
		$credits .= sprintf( __( 'Theme: %1$s.', 'jetpack' ), function_exists( 'wp_get_theme' ) ? wp_get_theme()->Name : get_current_theme() );
		$credits = apply_filters( 'infinite_scroll_credit', $credits );

		?>
		<div id="infinite-footer">
			<div class="container">
				<div class="blog-info">
					<a id="infinity-blog-title" href="<?php echo home_url( '/' ); ?>" title="<?php echo esc_attr( get_bloginfo( 'name', 'display' ) ); ?>" rel="home">
						<?php bloginfo( 'name' ); ?>
					</a>
				</div>
				<div class="blog-credits">
					<?php echo $credits; ?>
				</div>
			</div>
		</div><!-- #infinite-footer -->
		<?php
	}
};

/**
 * Initialize The_Infinite_Scroll
 */
function the_infinite_scroll_init() {
	if ( ! current_theme_supports( 'infinite-scroll' ) )
		return;

	new The_Infinite_Scroll;
}
add_action( 'init', 'the_infinite_scroll_init', 20 );

/**
 * Check whether the current theme is infinite-scroll aware.
 * If so, include the files which add theme support.
 */
function the_infinite_scroll_theme_support() {
	$theme_name = get_stylesheet();

	$customization_file = apply_filters( 'infinite_scroll_customization_file', dirname( __FILE__ ) . "/themes/{$theme_name}.php", $theme_name );

	if ( is_readable( $customization_file ) )
		require_once( $customization_file );
}
add_action( 'after_setup_theme', 'the_infinite_scroll_theme_support', 5 );
