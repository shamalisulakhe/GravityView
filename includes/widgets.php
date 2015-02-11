<?php
/**
 * GravityView WP Widgets
 *
 * @package   GravityView
 * @license   GPL2+
 * @author    Katz Web Services, Inc.
 * @link      http://gravityview.co
 * @copyright Copyright 2014, Katz Web Services, Inc.
 *
 * @since 1.6
 */

/**
 * Class GravityView_Recent_Entries_Widget
 * @since 1.6
 */
class GravityView_Recent_Entries_Widget extends WP_Widget {


	function __construct( ) {

		$name = __('GravityView - Recent Entries', 'gravityview');

		$widget_options = array(
			'description' => __( 'Display the most recent entries for a View', 'gravityview' ),
		);

		parent::__construct( 'gv_recent_entries', $name, $widget_options );

		add_action('admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts') );

		add_action( 'wp_ajax_gv_get_view_merge_tag_data', array( $this, 'ajax_get_view_merge_tag_data' ) );
	}

	/**
	 * When the widget View is changed, update the Merge Tag data
	 *
	 * @since 1.6
	 */
	function ajax_get_view_merge_tag_data() {

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'gravityview_ajax_widget' ) ) {
			exit( false );
		}

		$form_id  = gravityview_get_form_id( $_POST['view_id'] );

		$form = RGFormsModel::get_form_meta( $form_id );

		$output = array(
			'form' => array(
				'id' => $form['id'],
				'title' => $form['title'],
				'fields' => $form['fields'],
			),
			'mergeTags' => GFCommon::get_merge_tags( $form['fields'], '', false ),
		);

		echo json_encode( $output );

		exit;
	}

	/**
	 * Enable the merge tags functionality
	 *
	 * @since 1.6
	 */
	function admin_enqueue_scripts() {
		global $pagenow;

		if( $pagenow === 'widgets.php' ) {

			$script_debug = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';

			GravityView_Admin_Views::enqueue_gravity_forms_scripts();

			wp_enqueue_script( 'gravityview_widgets', plugins_url('assets/js/admin-widgets'.$script_debug.'.js', GRAVITYVIEW_FILE), array( 'jquery', 'gform_gravityforms' ), GravityView_Plugin::version );

			wp_localize_script( 'gravityview_widgets', 'GVWidgets', array(
				'nonce' => wp_create_nonce( 'gravityview_ajax_widget' )
			));

			wp_enqueue_style( 'gravityview_views_styles', plugins_url('assets/css/admin-views.css', GRAVITYVIEW_FILE), array('dashicons' ), GravityView_Plugin::version );
		}

	}

	/**
	 * @since 1.6
	 * @see WP_Widget::widget()
	 */
	function widget( $args, $instance ) {

		$args['id']        = ( isset( $args['id'] ) ) ? $args['id'] : 'gv_recent_entries';
		$instance['title'] = ( isset( $instance['title'] ) ) ? $instance['title'] : '';

		$title = apply_filters( 'widget_title', $instance[ 'title' ], $instance, $args['id'] );

		echo $args['before_widget'];

		if ( !empty( $title ) ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}

		do_action( 'gravityview/widget/recent-entries/before_widget', $args, $instance );

		$form_id = gravityview_get_form_id( $instance['view_id'] );

		$form = gravityview_get_form( $form_id );

		$entries = $this->get_entries( $instance, $form_id );

		$list_items = array();

		if( empty( $entries ) ) {

			$output = '<div class="gv-no-results">'.gv_no_results().'</div>';

		} else {

			foreach( $entries as $entry ) {

				$link = GravityView_API::entry_link( $entry, $instance['view_id'] );
				$text = $instance['link_format'];

				$item_output = gravityview_get_link( $link, $text );

				if( !empty( $instance['after_link'] ) ) {
					$item_output .= '<div>'.$instance['after_link'].'</div>';
				}

				$item_output = Gravityview_API::replace_variables( $item_output, $form, $entry );

				$item_output = apply_filters( 'gravityview/widget/recent-entries/item', $item_output, $entry, $instance );

				$list_items[] = $item_output;
			}

			$output = '<ul><li>'. implode( '</li><li>', $list_items ) . '</li></ul>';

		}

		/**
		 * Modify the HTML before it's echo'd
		 * @param string $output HTML to be displayed
		 * @param array $instance Widget settings
		 */
		$output = apply_filters( 'gravityview/widget/recent-entries/output', $output, $list_items, $instance );

		echo $output;

		/**
		 * Modify the HTML before it's echo'd
		 * @param array $args Widget args
		 * @param array $instance Widget settings
		 */
		do_action( 'gravityview/widget/recent-entries/after_widget', $args, $instance );

		echo $args['after_widget'];
	}

	/**
	 * Get the entries that will be shown in the current widget
	 *
	 * @param  array $instance Settings for the current widget
	 *
	 * @return array $entries Multidimensional array of Gravity Forms entries
	 */
	private function get_entries( $instance, $form_id ) {

		// Get the settings for the View ID
		$view_settings = gravityview_get_template_settings( $instance['view_id'] );

		$view_settings['id'] = $instance['view_id'];
		$view_settings['page_size'] = $instance['number'];

		// Prepare paging criteria
		$criteria['paging'] = array(
			'offset' => 0,
			'page_size' => $instance['number']
		);

		// Prepare Search Criteria
		$criteria['search_criteria'] = array( 'field_filters' => array() );
		$criteria['search_criteria'] = GravityView_frontend::process_search_only_approved( $view_settings, $criteria['search_criteria']);
		$criteria['search_criteria']['status'] = apply_filters( 'gravityview_status', 'active', $view_settings );

		/**
		 * Modify the search parameters before the entries are fetched
		 */
		$criteria = apply_filters('gravityview/widget/recent-entries/criteria', $criteria, $instance, $form_id );

		$results = GVCommon::get_entries( $form_id, $criteria );

		return $results;
	}

	/**
	 * @since 1.6
	 * @see WP_Widget::update()
	 */
	public function update( $new_instance, $old_instance ) {

		$instance = $old_instance;

		$instance['title'] = $new_instance['title'];

		// Force positive number
		$instance['number'] = empty( $new_instance['number'] ) ? 10 : absint( $new_instance['number'] );

		$instance['view_id'] = (int) $new_instance['view_id'];

		$new_instance['link_format'] = GFCommon::trim_all( $new_instance['link_format'] );
		$instance['link_format'] = !empty( $new_instance['link_format'] ) ? $new_instance['link_format'] : $old_instance['link_format'];
		$instance['after_link'] = $new_instance['after_link'];


		return $instance;
	}

	/**
	 * @since 1.6
	 * @see WP_Widget::form()
	 */
	public function form( $instance ) {

		// Set up some default widget settings.
		$defaults = array(
			'title' 			=> __('Recent Entries'),
			'view_id'           => NULL,
			'number'            => 10,
			'link_format'       => __('Entry #{entry_id}', 'gravityview'),
			'after_link'        => ''
		);

		$instance = wp_parse_args( (array) $instance, $defaults ); ?>

		<!-- Title -->
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php _e( 'Title:', 'edd' ) ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['title'] ); ?>" />
		</p>

		<!-- Download -->
		<?php
		$args = array(
			'post_type'      => 'gravityview',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		);
		$views = get_posts( $args );

		// If there are no views set up yet, we get outta here.
		if( empty( $views ) ) {
			echo '<div id="select_gravityview_view"><div class="wrap">'. GravityView_Post_Types::no_views_text() .'</div></div>';
			return;
		}

		?>

		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'view_id' ) ); ?>"><?php esc_html_e('Select a View', 'gravityview'); ?></label>
			<select class="widefat gv-recent-entries-select-view" name="<?php echo esc_attr( $this->get_field_name( 'view_id' ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( 'view_id' ) ); ?>">
				<option value=""><?php esc_html_e( '&mdash; Select a View as Entries Source &mdash;', 'gravityview' ); ?></option>
				<?php

					foreach( $views as $view ) {
						$title = empty( $view->post_title ) ? __('(no title)', 'gravityview') : $view->post_title;
						echo '<option value="'. $view->ID .'"'.selected( absint( $instance['view_id'] ), $view->ID ).'>'. esc_html( sprintf('%s #%d', $title, $view->ID ) ) .'</option>';
					}

				?>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'number' ); ?>">
				<span><?php _e( 'Number of entries to show:', 'gravityview' ); ?></span>
			</label>
			<input id="<?php echo $this->get_field_id( 'number' ); ?>" name="<?php echo $this->get_field_name( 'number' ); ?>" type="number" value="<?php echo intval( $instance['number'] ); ?>" size="3" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'link_format' ); ?>">
				<span><?php _e( 'Entry link text (required)', 'gravityview' ); ?></span>
			</label>
			<input id="<?php echo $this->get_field_id( 'link_format' ); ?>" name="<?php echo $this->get_field_name( 'link_format' ); ?>" type="text" value="<?php echo esc_attr( $instance['link_format'] ); ?>" class="widefat merge-tag-support mt-position-right mt-hide_all_fields" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id( 'after_link' ); ?>">
				<span><?php _e( 'Text or HTML to display after the link (optional)', 'gravityview' ); ?></span>
			</label>
			<textarea id="<?php echo $this->get_field_id( 'after_link' ); ?>" name="<?php echo $this->get_field_name( 'after_link' ); ?>" rows="5" class="widefat code merge-tag-support mt-position-right mt-hide_all_fields"><?php echo esc_textarea( $instance['after_link'] ); ?></textarea>
		</p>

		<?php do_action( 'gravityview_recent_entries_widget_form' , $instance ); ?>

		<script>
			// When the widget is saved or added, refresh the Merge Tags (here for backward compatibility)
			// WordPress 3.9 added widget-added and widget-updated actions
			jQuery('#<?php echo $this->get_field_id( 'view_id' ); ?>').trigger( 'change' );
		</script>
	<?php }

}



/**
 * Search widget class
 * @since 1.6
 */
class GravityView_Search_WP_Widget extends WP_Widget {

	public function __construct() {

		$widget_ops = array(
			'classname' => 'widget_gravityview_search',
			'description' => __( 'A search form for a specific GravityView.', 'gravityview')
		);

		$widget_display = array(
			'width' => 400
		);

		parent::__construct( 'gravityview_search', __( 'GravityView Search', 'gravityview' ), $widget_ops, $widget_display );

		if( !class_exists( 'GravityView_Widget_Search' ) ) {
			GravityView_Plugin::getInstance()->register_widgets();
		}

		$gravityview_widget = GravityView_Widget_Search::getInstance();

		// frontend - filter entries
		add_filter( 'gravityview_fe_search_criteria', array( $gravityview_widget, 'filter_entries' ), 10, 1 );

		// frontend - add template path
		add_filter( 'gravityview_template_paths', array( $gravityview_widget, 'add_template_path' ) );

	}

	public function widget( $args, $instance ) {

		/** This filter is documented in wp-includes/default-widgets.php */
		$title = apply_filters( 'widget_title', empty( $instance['title'] ) ? '' : $instance['title'], $instance, $this->id_base );

		echo $args['before_widget'];
		if ( $title ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}

		GravityView_Widget_Search::getInstance()->render_frontend( $instance );

		echo $args['after_widget'];
	}

	public function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$new_instance = wp_parse_args( (array) $new_instance, array( 'title' => '', 'view' => 0, 'search_fields' => '' ) );
		$instance['title'] = strip_tags($new_instance['title']);
		$instance['view'] = absint($new_instance['view']);
		$instance['search_fields'] = $new_instance['search_fields'];
		return $instance;
	}

	public function form( $instance ) {
		$instance = wp_parse_args( (array) $instance, array( 'title' => '', 'view' => 0, 'search_fields' => '' ) );
		$title           = $instance['title'];
		$view            = $instance['view'];
		$search_fields = $instance['search_fields'];

		$views = GVCommon::get_all_views();

		// If there are no views set up yet, we get outta here.
		if( empty( $views ) ) : ?>
			<div id="select_gravityview_view">
				<div class="wrap"><?php echo GravityView_Post_Types::no_views_text(); ?></div>
			</div>
			<?php return;
		endif;
		?>

		<p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>" /></label></p>

		<p>
			<label for="gravityview_view_id"><?php _e( 'View:', 'gravityview' ); ?></label>
			<select id="gravityview_view_id" name="<?php echo $this->get_field_name('view'); ?>" class="widefat">
				<option value=""><?php esc_html_e( '&mdash; Select a View &mdash;', 'gravityview' ); ?></option>
				<?php
				foreach( $views as $view_option ) {
					$title = empty( $view_option->post_title ) ? __('(no title)', 'gravityview') : $view_option->post_title;
					echo '<option value="'. $view_option->ID .'" ' . selected( esc_attr($view), $view_option->ID, false ) . '>'. esc_html( sprintf('%s #%d', $title, $view_option->ID ) ) .'</option>';
				}
				?>
			</select>

		</p>




		<?php // @todo: move style to CSS ?>
		<div style="margin-bottom: 1em;">
			<label for="<?php echo $this->get_field_id('search_fields'); ?>"><?php _e( 'Searchable fields:', 'gravityview' ); ?></label>
			<div class="gv-widget-search-fields" title="<?php esc_html_e('Search Fields', 'gravityview'); ?>">
				<input id="<?php echo $this->get_field_id('search_fields'); ?>" name="<?php echo $this->get_field_name('search_fields'); ?>" type="hidden" value="<?php echo esc_attr( $search_fields ); ?>" class="gv-search-fields-value">
			</div>

		</div>




	<?php
	}



}



/**
 * Register GravityView widgets
 *
 * @since 1.6
 * @return void
 */
function gravityview_register_widgets() {

	register_widget( 'GravityView_Recent_Entries_Widget' );

	register_widget( 'GravityView_Search_WP_Widget' );
	
}

add_action( 'widgets_init', 'gravityview_register_widgets' );