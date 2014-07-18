<?php
/**
 * GravityView Frontend functions
 *
 * @package   GravityView
 * @license   GPL2+
 * @author    Katz Web Services, Inc.
 * @link      http://gravityview.co
 * @copyright Copyright 2014, Katz Web Services, Inc.
 *
 * @since 1.0.0
 */



class GravityView_frontend {

	function __construct() {

		// Shortcode to render view (directory)
		add_shortcode( 'gravityview', array( 'GravityView_frontend', 'render_view_shortcode' ) );
		add_action( 'init', array( 'GravityView_frontend', 'init_rewrite' ) );
		add_action( 'gravityview_init', array( 'GravityView_frontend', 'init_rewrite' ) );

		// Enqueue scripts and styles after GravityView_Template::register_styles()
		add_action( 'wp_enqueue_scripts', array( 'GravityView_frontend', 'add_scripts_and_styles' ), 20);

		add_filter( 'the_title', array( 'GravityView_frontend', 'single_entry_title' ), 1, 2 );
		add_filter( 'the_content', array( 'GravityView_frontend', 'insert_view_in_content' ) );
		add_filter( 'comments_open', array( 'GravityView_frontend', 'comments_open' ), 10, 2);

		add_action('add_admin_bar_menus', array($this, 'admin_bar_remove_links'), 80 );
		add_action('admin_bar_menu', array($this, 'admin_bar_add_links'), 85 );
		add_filter('get_edit_post_link', array($this, 'edit_post_link_filter') );
	}

	/**
	 * Modify the "Edit" link many templates add. This way it matches user expectations.
	 * @param  string      $url  Existing URL
	 * @return string            Modified URL
	 */
	function edit_post_link_filter( $url ) {

		if( is_admin() ) { return $url; }

		$entry_id = self::is_single_entry();

		if( !empty( $entry_id ) ) {

			// We need the attached form ID as well...
			$entry = gravityview_get_entry( $entry_id );

			$url = admin_url( sprintf('admin.php?page=gf_entries&amp;view=entry&amp;id=%d&lid=%d', $entry['form_id'], $entry_id ) );
		}

		return $url;
	}

	/**
	 * Add helpful GV links to the menu bar, like Edit Entry on single entry page.
	 * @filter default text
	 * @action default text
	 * @return [type]      [description]
	 */
	function admin_bar_add_links() {
		global $wp_admin_bar, $post, $wp, $wp_the_query;

		if( is_admin() ) { return; }

		$entry_id = self::is_single_entry();

		if( GFCommon::current_user_can_any('gravityforms_edit_entries') && !empty( $entry_id ) ) {

			// We need the attached form ID as well...
			$entry = gravityview_get_entry( $entry_id );

			$wp_admin_bar->add_menu( array(
				'id' => 'edit-entry',
				'title' => __('Edit Entry', 'gravity-view'),
				'href' => admin_url( sprintf('admin.php?page=gf_entries&amp;view=entry&amp;id=%d&lid=%d', $entry['form_id'], $entry_id ) ),
			) );

		}

	}

	/**
	 * Remove "Edit Page" or "Edit View" links when on single entry pages
	 * @return void
	 */
	function admin_bar_remove_links() {
		global $wp_admin_bar, $post, $wp, $wp_the_query;

		$is_single_entry = self::is_single_entry();
		$post_type = get_post_type( $post );

		// If we're on the single entry page, we don't want to cause confusion.
		if( is_admin() || ($is_single_entry && $post_type !== 'gravityview') ) {
			remove_action( 'admin_bar_menu', 'wp_admin_bar_edit_menu', 80 );
		}
	}

	/**
	 * Register rewrite rules to capture the single entry view
	 *
	 * @access public
	 * @static
	 * @return void
	 */
	public static function init_rewrite() {

		$endpoint = self::get_entry_var_name();

		add_rewrite_endpoint( "{$endpoint}", EP_ALL );
	}


	/**
	 * Return the query var / end point name for the entry
	 *
	 * @access public
	 * @static
	 * @return void
	 * @filter gravityview_directory_endpoint Change the slug used for single entries
	 */
	public static function get_entry_var_name() {
		return sanitize_title( apply_filters( 'gravityview_directory_endpoint', 'entry' ) );
	}


	/**
	 * Retrieve the default args for shortcode and theme function
	 *
	 * @access public
	 * @static
	 * @return void
	 */
	public static function get_default_args() {

		$defaults = array(
			'id' => NULL,
			'lightbox' => true,
			'page_size' => NULL,
			'sort_field' => NULL,
			'sort_direction' => 'ASC',
			'start_date' => NULL,
			'end_date' => 'now',
			'class' => NULL,
			'search_value' => NULL,
			'search_field' => NULL,
			'single_title' => NULL,
			'back_link_label' => NULL,
			'hide_empty' => true,
		);

		return $defaults;
	}


	/**
	 * Callback function for add_shortcode()
	 *
	 * @access public
	 * @static
	 * @param mixed $atts
	 * @return void
	 */
	public static function render_view_shortcode( $atts ) {

		do_action( 'gravityview_log_debug', '[render_view_shortcode] Init Shortcode. Attributes: ',  $atts );

		//confront attributes with defaults
		$args = shortcode_atts( self::get_default_args() , $atts, 'gravityview' );

		do_action( 'gravityview_log_debug', '[render_view_shortcode] Init Shortcode. Merged Attributes: ', $args );

		return self::render_view( $args );
	}

	/**
	 * Filter the title for the single entry view
	 * @todo Somehow make this work with multiple shortcodes on a page. The problem is that there's no form data passed...
	 * @param  string $title   current title
	 * @param  int $passed_post_id Post ID
	 * @return string          (modified) title
	 */
	public static function single_entry_title( $title, $passed_post_id ) {
		global $post, $gravityview_view;


		// Don't modify the title for anything other than the current view/post.
		// This is true for embedded shortcodes and Views.
		if( is_object($post) && (int)$post->ID !== (int)$passed_post_id ) {
			return $title;
		}


		$single_entry = self::is_single_entry();

		// If this is the directory view, return.
		if( empty( $single_entry ) ) {
			return $title;
		}

		$view_meta = gravityview_get_view_meta( $passed_post_id );

		if( !empty( $view_meta['atts']['single_title'] ) ) {

			// We are allowing HTML in the fields, so no escaping the output
			$title = $view_meta['atts']['single_title'];
			$entry = gravityview_get_entry( $single_entry );
			$form = gravityview_get_form( $view_meta['form_id'] );

			$title = GravityView_API::replace_variables( $title, $form, $entry );
		}

		return $title;
	}


	/**
	 * In case View post is called directly, insert the view in the post content
	 *
	 * @access public
	 * @static
	 * @param mixed $content
	 * @return void
	 */
	public static function insert_view_in_content( $content ) {

		// Plugins may run through the content in the header. WP SEO does this for its OpenGraph functionality.
		if( !did_action( 'loop_start' ) ) {

			do_action( 'gravityview_log_debug', '[insert_view_in_content] Not processing yet: loop_start hasn\'t run yet.');

			return $content;
		}

		// Otherwise, this is called on the Views page when in Excerpt mode.
		if( is_admin() ) { return $content; }

		$post = get_post();

		if( 'gravityview' === get_post_type( $post ) ) {
			$content .= self::render_view( array( 'id' => $post->ID ) );
		}

		return $content;
	}

	public static function comments_open( $open, $post_id ) {

		$post = get_post( $post_id );

		if( 'gravityview' === get_post_type( $post ) ) {
			return false;
		}

		return $open;
	}


	/**
	 * Core function to render a View based on a set of arguments ($args):
	 *   $id - View id
	 *   $page_size - Page
	 *   $sort_field - form field id to sort
	 *   $sort_direction - ASC / DESC
	 *   $start_date - Ymd
	 *   $end_date - Ymd
	 *   $class - assign a html class to the view
	 *   $offset (optional) - This is the start point in the current data set (0 index based).
	 *
	 *
	 * @access public
	 * @static
	 * @param mixed $args
	 * @return void
	 */
	public static function render_view( $passed_args ) {
		global $post;

		do_action( 'gravityview_log_debug', '[render_view] Init View. Arguments: ', $passed_args );

		// validate attributes
		if( empty( $passed_args['id'] ) ) {
			do_action( 'gravityview_log_error', '[render_view] Returning; no ID defined.');
			return;
		}

		$view_meta = gravityview_get_view_meta( $passed_args['id'] );

		do_action( 'gravityview_log_debug', '[render_view] View Meta: ', $view_meta );

		// The passed args were always winning, even if they were NULL.
		// This prevents that. Filters NULL, FALSE, and empty strings.
		$passed_args = array_filter( $passed_args, 'strlen' );

		//Override shortcode args over View template settings
		// array_filter prevents empty arguments from winning over defaults.
		$atts = $view_meta['atts'] = wp_parse_args( $passed_args, $view_meta['atts'] );

		// Add this so it can be passed to get_view_entries() below.
		$atts['id'] = $view_meta['id'];

		do_action( 'gravityview_log_debug', '[render_view] Arguments after merging with View settings: ', $atts );

		// It's password protected and you need to log in.
		if( post_password_required( $view_meta['id'] ) ) {

			do_action( 'gravityview_log_error', sprintf('[render_view] Returning: View %d is password protected.', $view_meta['id'] ) );

			// If we're in an embed or on an archive page, show the password form
			if( get_the_ID() !== $view_meta['id'] ) { return get_the_password_form(); }

			// Otherwise, just get outta here
			return;
		}

		$dir_fields = gravityview_get_directory_fields( $view_meta['id'] );
		do_action( 'gravityview_log_debug', '[render_view] Fields: ', $dir_fields );

		// remove fields according to visitor visibility permissions (if logged-in)
		$dir_fields = self::filter_fields( $dir_fields );
		do_action( 'gravityview_log_debug', '[render_view] Fields after visibility filter: ', $dir_fields );

		// If not set, the default is hide empty fields.
		$hide_empty_fields = isset( $atts['hide_empty'] ) ? !empty( $atts['hide_empty'] ) : true;
		// Allow filtering
		$hide_empty_fields = apply_filters( 'gravityview_hide_empty_fields', $hide_empty_fields );
		// Per template
		$hide_empty_fields = apply_filters( 'gravityview_hide_empty_fields_template_'.$view_meta['template_id'], $hide_empty_fields );
		// And per view
		$hide_empty_fields = apply_filters( 'gravityview_hide_empty_fields_view_'.$view_meta['id'], $hide_empty_fields );

		ob_start();

		// set globals for templating
		global $gravityview_view;
		$gravityview_view = new GravityView_View(array(
			'form_id' => $view_meta['form_id'],
			'view_id' => $view_meta['id'],
			'fields'  => $dir_fields,
			'hide_empty_fields' => $hide_empty_fields,
		));

		// check if user requests single entry
		$single_entry = self::is_single_entry();

		if( empty( $single_entry ) ) {

			// user requested Directory View
			do_action( 'gravityview_log_debug', '[render_view] Executing Directory View' );

			//fetch template and slug
			$view_slug =  apply_filters( 'gravityview_template_slug_'. $view_meta['template_id'], 'table', 'directory' );
			do_action( 'gravityview_log_debug', '[render_view] View template slug: ', $view_slug );

			$view_entries = self::get_view_entries( $atts, $view_meta['form_id'] );

			do_action( 'gravityview_log_debug', sprintf( '[render_view] Get Entries. Found %s entries', $view_entries['count'] ) );

			$gravityview_view->paging = $view_entries['paging'];
			$gravityview_view->context = 'directory';
			$sections = array( 'header', 'body', 'footer' );

		} else {
			// user requested Single Entry View
			do_action( 'gravityview_log_debug', '[render_view] Executing Single View' );

			$entry = gravityview_get_entry( $single_entry );

			// We're in single view, but the view being processed is not the same view the single entry belongs to.
			if( $view_meta['form_id'] !== $entry['form_id'] ) {

				$view_id = isset( $view_entries['entries'][0]['id'] ) ? $view_entries['entries'][0]['id'] : '(empty)';
				do_action( 'gravityview_log_debug', '[render_view] In single entry view, but the entry does not belong to this View. Perhaps there are multiple views on the page. View ID: '. $view_id);
				return;
			}

			//fetch template and slug
			$view_slug =  apply_filters( 'gravityview_template_slug_'. $view_meta['template_id'], 'table', 'single' );
			do_action( 'gravityview_log_debug', '[render_view] View single template slug: ', $view_slug );

			//fetch entry detail
			$view_entries['count'] = 1;
			$view_entries['entries'][] = $entry;
			do_action( 'gravityview_log_debug', '[render_view] Get single entry: ', $view_entries['entries'] );

			// set back link label
			$gravityview_view->back_link_label = isset( $atts['back_link_label'] ) ? $atts['back_link_label'] : NULL;

			$gravityview_view->context = 'single';
			$sections = array( 'single' );

		}

		// add template style
		self::add_style( $view_meta['template_id'] );

		// Prepare to render view and set vars
		$gravityview_view->entries = $view_entries['entries'];
		$gravityview_view->total_entries = $view_entries['count'];

		// If Edit
		if ( apply_filters( 'gravityview_is_edit_entry', false ) ) {

			do_action( 'gravityview_edit_entry' );

			return;

		} else {

			// finaly we'll render some html
			$sections = apply_filters( 'gravityview_render_view_sections', $sections, $view_meta['template_id'] );
			do_action( 'gravityview_log_debug', '[render_view] Sections to render: ', $sections );
			foreach( $sections as $section ) {
				do_action( 'gravityview_log_debug', '[render_view] Rendering '. $section . ' section.' );
				$gravityview_view->render( $view_slug, $section, false );
			}

		}

		if( 'gravityview' === get_post_type( $post ) ) {
			// Print the View ID to enable proper cookie pagination ?>
			<input type="hidden" id="gravityview-view-id" value="<?php echo $view_meta['id']; ?>">
<?php
		}
		$output = ob_get_clean();

		return $output;
	}

	/**
	 * Process the start and end dates for a view - overrides values defined in shortcode (if needed)
	 *
	 * The `start_date` and `end_date` keys need to be in a format processable by GFFormsModel::get_date_range_where(),
	 * which uses \DateTime() format.
	 *
	 * You can set the `start_date` or `end_date` to any value allowed by {@link http://www.php.net//manual/en/function.strtotime.php strtotime()},
	 * including strings like "now" or "-1 year" or "-3 days".
	 *
	 * @todo  Compress into one
	 * @param  array      $args            View settings
	 * @param  array      $search_criteria Search being performed, if any
	 * @return array                       Modified `$search_criteria` array
	 */
	static function process_search_dates( $args, $search_criteria ) {

		foreach ( array( 'start_date', 'end_date' ) as $key ) {

			// Is the start date or end date set in the view or shortcode?
			// If so, we want to make sure that the search doesn't go outside the bounds defined.
			if( !empty( $args[ $key ] ) ) {

				// Get a timestamp and see if it's a valid date format
				$date = strtotime( $args[ $key ] );

				// The date was invalid
				if( empty( $date ) ) {
					do_action( 'gravityview_log_error', '[process_search_dates] Invalid '.$key.' date format: ' . $args[ $key ]);
					continue;
				}

				if(
					// If there is no search being performed
					empty( $search_criteria[ $key ] ) ||

					// Or if there is a search being performed
					( !empty( $search_criteria[ $key ] )
						// And the search is for entries before the start date defined by the settings
						&& (
							( $key === 'start_date' && strtotime( $search_criteria[ $key ] ) < $date ) ||
							( $key === 'end_date' && strtotime( $search_criteria[ $key ] ) > $date )
						)
					)
				) {
					// Then we override the search and re-set the start date
					$search_criteria[ $key ] = date( 'Y-m-d H:i:s' , $date );
				}
			}

		}

		return $search_criteria;
	}

	/**
	 * Core function to calculate View multi entries (directory) based on a set of arguments ($args):
	 *   $id - View id
	 *   $page_size - Page
	 *   $sort_field - form field id to sort
	 *   $sort_direction - ASC / DESC
	 *   $start_date - Ymd
	 *   $end_date - Ymd
	 *   $class - assign a html class to the view
	 *   $offset (optional) - This is the start point in the current data set (0 index based).
	 *
	 *
	 * @access public
	 * @static
	 * @param mixed $args
	 * @param int $form_id
	 * @return void
	 */
	public static function get_view_entries( $args, $form_id ) {

		do_action( 'gravityview_log_debug', '[get_view_entries] init' );
		// start filters and sorting

		// Search Criteria
		$search_criteria = apply_filters( 'gravityview_fe_search_criteria', array( 'field_filters' => array() ) );
		do_action( 'gravityview_log_debug', '[get_view_entries] Search Criteria after hook gravityview_fe_search_criteria: ', $search_criteria );

		// implicity search
		if( !empty( $args['search_value'] ) ) {
			$search_criteria['field_filters'][] = array(
				'key' => ( ( !empty( $args['search_field'] ) && is_numeric( $args['search_field'] ) ) ? $args['search_field'] : null ), // The field ID to search
				'value' => esc_attr( $args['search_value'] ), // The value to search
				'operator' => 'contains', // What to search in. Options: `is` or `contains`
			);
		}
		do_action( 'gravityview_log_debug', '[get_view_entries] Search Criteria after implicity search: ', $search_criteria );

		// Handle setting date range
		$search_criteria = self::process_search_dates( $args, $search_criteria );

		do_action( 'gravityview_log_debug', '[get_view_entries] Search Criteria after date params: ', $search_criteria );


		// Sorting
		$sorting = array();
		if( !empty( $args['sort_field'] ) ) {
			$sorting = array( 'key' => $args['sort_field'], 'direction' => $args['sort_direction'] );
		}

		do_action( 'gravityview_log_debug', '[get_view_entries] Sort Criteria : ', $sorting );


		// Paging & offset
		$page_size = !empty( $args['page_size'] ) ? $args['page_size'] : 25;

		if( isset( $args['offset'] ) ) {
			$offset = $args['offset'];
		} else {
			$curr_page = empty( $_GET['pagenum'] ) ? 1 : intval( $_GET['pagenum'] );
			$offset = ( $curr_page - 1 ) * $page_size;
		}
		$paging = array( 'offset' => $offset, 'page_size' => $page_size );

		do_action( 'gravityview_log_debug', '[get_view_entries] Paging: ', $paging );


		// remove not approved entries
		if( !empty( $args['show_only_approved'] ) ) {
			$search_criteria['field_filters'][] = array( 'key' => 'is_approved', 'value' => 'Approved' );
			$search_criteria['field_filters']['mode'] = 'all'; // force all the criterias to be met

			do_action( 'gravityview_log_debug', '[get_view_entries] Search Criteria if show only approved: ', $search_criteria );
		}

		// Only show active listings
		$search_criteria['status'] = apply_filters( 'gravityview_status', 'active', $args );

		/**
		 * Filter get entries criteria
		 *
		 * Passes and returns array with `search_criteria`, `sorting` and `paging` keys.
		 *
		 * @var array
		 */
		$parameters = apply_filters( 'gravityview_get_entries', apply_filters( 'gravityview_get_entries_'.$args['id'], compact( 'search_criteria', 'sorting', 'paging' ) ) );

		do_action( 'gravityview_log_debug', '[get_view_entries] $parameters passed to gravityview_get_entries(): ', $parameters );

		//fetch entries
		$count = 0;
		$entries = gravityview_get_entries( $form_id, $parameters, $count );

		do_action( 'gravityview_log_debug', sprintf( '[get_view_entries] Get Entries. Found: %s entries', $count ) );

		return compact( 'count', 'entries', 'paging' );
	}




	// helper functions

	/**
	 * Filter area fields based on specified conditions
	 *
	 * @access public
	 * @param array $dir_fields
	 * @return void
	 */
	public static function filter_fields( $dir_fields ) {

		if( empty( $dir_fields ) || !is_array( $dir_fields ) ) {
			return $dir_fields;
		}

		foreach( $dir_fields as $area => $fields ) {
			foreach( $fields as $uniqid => $properties ) {

				if( self::hide_field_check_conditions( $properties ) ) {
					unset( $dir_fields[ $area ][ $uniqid ] );
				}

			}
		}

		return $dir_fields;

	}


	/**
	 * Check wether a certain field should not be presented based on its own properties.
	 *
	 * @access public
	 * @param array $properties
	 * @return true (field should be hidden) or false (field should be presented)
	 */
	public static function hide_field_check_conditions( $properties ) {

		// logged-in visibility
		if( !empty( $properties['only_loggedin'] ) && !current_user_can( $properties['only_loggedin_cap'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Verify if user requested a single entry view
	 * @return boolean|string false if not, single entry id if true
	 */
	public static function is_single_entry() {
		$single_entry = get_query_var( self::get_entry_var_name() );
		if( empty( $single_entry ) ){
			return false;
		} else {
			return $single_entry;
		}
	}




	/**
	 * Register styles and scripts
	 *
	 * @filter  gravity_view_lightbox_script Modify the lightbox JS slug. Default: `thickbox`
	 * @filter  gravity_view_lightbox_style Modify the thickbox CSS slug. Default: `thickbox`
	 * @access public
	 * @return void
	 */
	public static function add_scripts_and_styles() {
		global $post, $posts;

		foreach ($posts as $p) {

			// enqueue template specific styles
			if( has_gravityview_shortcode( $p ) ) {

				$view_meta = gravityview_get_view_meta( $p );

				// By default, no thickbox
				$js_dependencies = array( 'jquery', 'gravityview-jquery-cookie' );
				$css_dependencies = array();

				// If the thickbox is enqueued, add dependencies
				if( !empty( $view_meta['atts']['lightbox'] ) ) {
					$js_dependencies[] = apply_filters( 'gravity_view_lightbox_script', 'thickbox' );
					$css_dependencies[] = apply_filters( 'gravity_view_lightbox_style', 'thickbox' );
				}

				wp_register_script( 'gravityview-jquery-cookie', plugins_url('includes/lib/jquery-cookie/jquery.cookie.js', GRAVITYVIEW_FILE), array( 'jquery' ), GravityView_Plugin::version, true );

				$script_debug = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';
				wp_enqueue_script( 'gravityview-fe-view', plugins_url('includes/js/fe-views'.$script_debug.'.js', GRAVITYVIEW_FILE), $js_dependencies, GravityView_Plugin::version, true );

				wp_enqueue_style( 'gravityview_default_style', plugins_url('templates/css/gv-default-styles.css', GRAVITYVIEW_FILE), $css_dependencies, GravityView_Plugin::version, 'all' );

				self::add_style( $view_meta['template_id'] );
			}

		}

	}

	/**
	 * Add template extra style if exists
	 * @param string $template_id
	 */
	public static function add_style( $template_id ) {

		if( !empty( $template_id ) && wp_style_is( 'gravityview_style_' . $template_id, 'registered' ) ) {
			do_action( 'gravityview_log_debug', sprintf( '[add_style] Adding extra template style for %s', $template_id ) );
			wp_enqueue_style( 'gravityview_style_' . $template_id );
		}

	}


}

new GravityView_frontend;


/**
 * Theme function to get a GravityView view
 *
 * @access public
 * @param string $view_id (default: '')
 * @param array $atts (default: array())
 * @return void
 */
function get_gravityview( $view_id = '', $atts = array() ) {
	if( !empty( $view_id ) ) {
		$atts['id'] = $view_id;
		$args = wp_parse_args( $atts, GravityView_frontend::get_default_args() );
		return GravityView_frontend::render_view( $args );
	}
	return '';
}

/**
 * Theme function to render a GravityView view
 *
 * @access public
 * @param string $view_id (default: '')
 * @param array $atts (default: array())
 * @return void
 */
function the_gravityview( $view_id = '', $atts = array() ) {
	echo get_gravityview( $view_id, $atts );
}




