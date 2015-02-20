<?php
/**
 * Display the fileupload field type
 *
 * @package GravityView
 */

$gravityview_view = GravityView_View::getInstance();

extract( $gravityview_view->getCurrentField() );

// Tell the renderer not to wrap this field in an anchor tag.
$gravityview_view->setCurrentFieldSetting('show_as_link', false);

/**
 * Parse the stored value of the post image
 *
 * The image is stored with `|:|` dividing the fields. Break it into its parts and see what's set.
 *
 * @see GFCommon::get_lead_field_display()
 * @var array
 */
$ary = explode("|:|", $value);
$url = count($ary) > 0 ? $ary[0] : "";
$title = count($ary) > 1 ? $ary[1] : "";
$caption = count($ary) > 2 ? $ary[2] : "";
$description = count($ary) > 3 ? $ary[3] : "";

$link_atts = '';

/**
 * @since 1.5.4
 *
 * Dynamic data (get post featured image instead of GF entry field)
 */
if( !empty( $field_settings['dynamic_data'] ) && !empty( $entry['post_id'] ) && has_post_thumbnail( $entry['post_id'] ) ) {

	/**
	 * Modify what size is fetched for the post's Featured Image
	 * @param string $size The size to be fetched using `wp_get_attachment_image_src()` (default: 'large')
	 * @param array $entry Gravity Forms entry array
	 */
	$image_size = apply_filters( 'gravityview/fields/post_image/size', 'large', $entry );
	$image_url = wp_get_attachment_image_src( get_post_thumbnail_id( $entry['post_id'] ), $image_size );

	if( empty( $image_url[0] ) ) {
		do_action('gravityview_log_debug', 'Dynamic featured image for post #'.$entry['post_id'].' doesnt exist (size: '.$image_size.').' );
	} else {
		$url = $image_url[0];
	}
}

##
## Get the link URL
##

// Link to the post created by the entry
if( !empty( $field_settings['link_to_post'] ) ) {
	$href = get_permalink( $entry['post_id'] );
}
// Link to the single entry
else if ( !empty( $field_settings['show_as_link'] ) ) {
	$href = gv_entry_link( $entry );
}
// Link to the file itself
else {

	$href = $url;

	// Only show the lightbox if linking to the file itself
	if( $gravityview_view->getAtts('lightbox') ) {
		$link_atts .= "target='_blank' class='thickbox'";
	}

}


// Set the attributes for the link
$link_atts .= " href='{$href}'";

// Add the title as the link title, if exists. This will appear as caption in the lightbox.
$link_atts .= ' title="'.esc_attr( $title ).'"';



##
## Get the image
##
$image_atts = array(
	'src'	=> $url,
	'alt'	=> ( !empty( $caption ) ? $caption : $title ),
	'validate_src'	=> false, // Already validated by GF
);

$image = new GravityView_Image( $image_atts );


/**
 * Modify the values used for the image meta.
 *
 * @link https://gravityview.co/support/documentation/201606759 Read more about the filter
 *
 * @var array
 */
$image_meta = apply_filters('gravityview_post_image_meta', array(
	'title' => array(
		'label' => esc_attr_x( 'Title:', 'Post Image field title heading', 'gravityview'),
		'value' => $title,
		'tag_label' => 'span',
		'tag_value' => 'div'
	),
	'caption' => array(
		'label' => esc_attr_x( 'Caption:', 'Post Image field caption heading', 'gravityview'),
		'value' => $caption,
		'tag_label' => 'span',
		'tag_value' => GFFormsModel::is_html5_enabled() ? 'figcaption' : 'div',
	),
	'description' => array(
		'label' => esc_attr_x( 'Description:', 'Post Image field description heading', 'gravityview'),
		'value' => $description,
		'tag_label' => 'span',
		'tag_value' => 'div'
	),
));

// If HTML5 output is enabled, support the `figure` and `figcaption` tags
$wrappertag = GFFormsModel::is_html5_enabled() ? 'figure' : 'div';

/**
 * Whether to show labels for the image meta.
 *
 * @link https://gravityview.co/support/documentation/201606759 Read more about the filter
 * @var boolean
 */
$showlabels = apply_filters( 'gravityview_post_image_meta_show_labels', true );

// Wrapper tag
$output = '<'.$wrappertag.' class="gv-image">';

	// Image with link tag
	$output .= "<a {$link_atts}>{$image}</a>";

	foreach ( (array)$image_meta as $key => $meta ) {

		if( !empty( $meta['value'] ) ) {

			$output .= '<div class="gv-image-'.esc_attr( $key ).'">';

			// Display the label if the label's not empty
			if( !empty( $showlabels ) && !empty( $meta['label'] ) ) {
				$output .= '<'.esc_attr( $meta['tag_label'] ).' class="gv-image-label">';
				$output .= esc_html( $meta['label'] );
				$output .= '</'.esc_attr( $meta['tag_label'] ).'> ';
			}

			// Display the value
			$output .= '<'.esc_attr( $meta['tag_value'] ).' class="gv-image-value">';
			$output .= esc_html( $meta['value'] );
			$output .= '</'.esc_attr( $meta['tag_value'] ).'>';

			$output .= '</div>';
		}

	}

$output .= '</'.$wrappertag.'>';

echo $output;
