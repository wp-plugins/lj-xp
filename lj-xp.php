<?php
/*
Plugin Name: LiveJournal Crossposter
Plugin URI: http://code.google.com/p/ljxp/
Description: Automatically copies all posts to a LiveJournal or other LiveJournal-based blog. Editing or deleting a post will be replicated as well.
Version: 2.2.3-beta
Author: Arseniy Ivanov, Evan Broder, Corey DeGrandchamp, Stephanie Leary
Author URI: http://code.google.com/p/ljxp/
*/

require_once(ABSPATH . '/wp-includes/class-IXR.php');
require(ABSPATH . '/wp-includes/version.php');

// ---- Settings API -----
require_once ('lj-xp-options.php');

// set default options 
function ljxp_set_defaults() {
	$options = ljxp_get_options();
	add_option( 'ljxp', $options, '', 'no' );
}
register_activation_hook(__FILE__, 'ljxp_set_defaults');

//register our settings
function register_ljxp_settings() {
	register_setting( 'ljxp', 'ljxp', 'ljxp_validate_options');
}

// when uninstalled, remove option
function ljxp_remove_options() {
	delete_option('ljxp');
	delete_option('ljxp_error_notice');
	delete_option('lj_xp_error_notice');
}
register_uninstall_hook( __FILE__, 'ljxp_remove_options' );
// for testing only
// register_deactivation_hook( __FILE__, 'ljxp_remove_options' );


// ---- Make it go -----
function ljxp_post($post_id, $bulk = false) {
	global $wpdb, $tags, $cats; // tags/cats are going to be filtered thru an external function
	$options = ljxp_get_options();
	$errors = array();
	$post = &get_post($post_id);
	
	// Get postmeta overrides
	$privacy = get_post_meta($post->ID, 'ljxp_privacy', true);
	if (isset($privacy) && $privacy != 0) $options['privacy'] = $options['privacy_private'] = $privacy;
	$comments = get_post_meta($post->ID, 'ljxp_comments', true);
	if (isset($comments) && $comments != 0) $options['comments'] = $comments;
		if ($options['comments'] == 2) $options['comments'] = 0;
	$options['userpic'] = get_post_meta($post->ID, 'ljxp_userpic', true);

	if (!is_array($options['skip_cats'])) $options['skip_cats'] = array();
	$options['copy_cats'] = array_diff(get_all_category_ids(), $options['skip_cats']);
		
	// If the post was manually set to not be crossposted, or nothing was set and the default is not to crosspost,
	// or it's private and the default is not to crosspost private posts, give up now
	if (0 == $options['crosspost'] || get_post_meta($post->ID, 'no_lj', true) || ('private' == $post->post_status && $options['privacy_private'] == 'no_lj')) {
		$errors['nopost'] = 'This post was set to not crosspost.';
		return $post->ID;
	}

	// If the post shows up in the forbidden category list and it has been
	// crossposted before (so the forbidden category list must have changed),
	// delete the post. Otherwise, just give up now
	$do_crosspost = 0;

	$postcats = wp_get_post_categories($post->ID);
	foreach($postcats as $cat) {
		if(in_array($cat, $options['copy_cats'])) {
			$do_crosspost = 1;
			break; // decision made and cannot be altered, fly on
		}
		else {
			$errors['nocats'] = 'This post was not in any of the right categories, so it was not crossposted.';
		}
	}

	if(!$do_crosspost) {
		return ljxp_delete($post->ID);
	}

	// And create our connection
	$client = new IXR_Client($options['host'], '/interface/xmlrpc');
	//$client->debug = true;

	// Get the challenge string
	// Using challenge for the most security. Allows pwd hash to be stored instead of pwd
	if (!$client->query('LJ.XMLRPC.getchallenge')) {
		$errors[$client->getErrorCode()] = $client->getErrorMessage();
	}

	// And retrieve the challenge string
	$response = $client->getResponse();
	$challenge = $response['challenge'];

	// Insert the name of the page we're linking back to based on the options set
	if (empty($options['custom_name_on']))
		$blogName = get_bloginfo('name');
	else
		$blogName = $options['custom_name'];

	// Tagging and categorizing — for LJ tags
	// Not to be moved down: the else case of custom header is using $cats and $tags

	$cats = array();
	$tags = array();

	$cats = wp_get_post_categories($post->ID, array('fields' => 'all')); 
	$tags = wp_get_post_tags($post->ID, array('fields' => 'all'));


	// Need advice on merging all ( /\ and \/ ) this code

	// convert retrieved objects to arrays of (term_id => name) pairs
	$modify = create_function('$f, $n, $obj', 'global $$f; $p = &$$f; unset($p[$n]); $p[$obj->term_id] = $obj->name;');

	if(count($tags) > 0) array_map($modify, array_fill(0, count($tags), 'tags'), array_keys($tags), array_values($tags));
	if(count($cats) > 0) array_map($modify, array_fill(0, count($cats), 'cats'), array_keys($cats), array_values($cats));


	switch($options['tag']){
		case 0 :
				// pass
			break;
		case 1 :
				$cat_string = implode(", ", $cats);
			break;
		case 2 :
				$cat_string = implode(", ", $tags);
			break;
		case 3 :
				$cat_string = implode(", ", array_unique(array_merge($cats, $tags)));
			break;
	}

	if($options['custom_header'] == '') {
		$postHeader = '<p><small>';

		// If the post is not password protected, follow standard procedure
		if(!$post->post_password) {
			$postHeader .= __('Originally published at', 'lj-xp');
			$postHeader .= ' <a href="'.get_permalink($post->ID).'">';
			$postHeader .= $blogName;
			$postHeader .= '</a>.';
		}
		// If the post is password protected, put up a special message
		else {
			$postHeader .= __('This post is password protected. You can read it at', 'lj-xp');
			$postHeader .= ' <a href="'.get_permalink($post->ID).'">';
			$postHeader .= $blogName;
			$postHeader .= '</a>, ';
			$postHeader .= __('where it was originally posted', 'lj-xp');
			$postHeader .= '.';
		}

		// Depending on whether comments or allowed or not, alter the header appropriately
		if($options['comments']) {
			$postHeader .= sprintf(__(' You can comment here or <a href="%s">there</a>.', 'lj-xp'), get_permalink($post->ID).'#comments');
		}
		else {
			$postHeader .= sprintf(__(' Please leave any <a href="%s">comments</a> there.', 'lj-xp'), get_permalink($post->ID).'#comments');
		}

		$postHeader .= '</small></p>';
	}
	else {
		$postHeader = $options['custom_header'];

		// find [author]
		$author = get_userdata( $post->post_author );
		$author = $author->display_name;
		
		// pre-post formatting for tags and categories
		$htags = '';
		$hcats = '';

		foreach($tags as $_term_id => $_name) $htags[] = '<a href="'.get_tag_link($_term_id).'" rel="bookmark">'.$_name.'</a>';
		foreach($cats as $_term_id => $_name) $hcats[] = '<a href="'.get_category_link($_term_id).'" rel="bookmark">'.$_name.'</a>';

		$htags = implode(', ', (array)$htags);
		$hcats = implode(', ', (array)$hcats);

		$find = array('[blog_name]', '[blog_link]', '[permalink]', '[comments_link]', '[comments_count]', '[tags]', '[categories]', '[author]');
		$replace = array($blogName, get_option('home'), get_permalink($post->ID), get_permalink($post->ID).'#comments', lj_comments($post->ID), $htags, $hcats, $author);
		$postHeader = str_replace($find, $replace, $postHeader);
		$postHeader = apply_filters('ljxp_post_header', $postHeader, $post->ID);
	}

	// $the_event will eventually be passed to the LJ XML-RPC server.
	$the_event = "";

	// and if the post isn't password protected, we need to put together the
	// actual post
	if(!$post->post_password) {
		if ($options['content'] == 'excerpt') {
			$excerpt = $post->post_excerpt;
			if (empty($excerpt)) {
				// cloned from wp_trim_excerpt()
				$excerpt = $post->post_content;	
	            $excerpt = strip_shortcodes( $excerpt );
	            $excerpt = apply_filters('the_content', $excerpt);
	            $excerpt = str_replace(']]>', ']]&gt;', $excerpt);
	            $excerpt = strip_tags($excerpt);
	            $excerpt_length = apply_filters('excerpt_length', 55);
	            $excerpt_more = apply_filters('excerpt_more', ' ' . '[...]');
	            $words = preg_split("/[\n\r\t ]+/", $excerpt, $excerpt_length + 1, PREG_SPLIT_NO_EMPTY);
	            if ( count($words) > $excerpt_length ) {
	                    array_pop($words);
	                    $excerpt = implode(' ', $words);
	                    $excerpt = $excerpt . $excerpt_more;
	            } else {
	                    $excerpt = implode(' ', $words);
	            }
			}	
			$excerpt = apply_filters('the_excerpt', $excerpt);
			$the_event = apply_filters('ljxp_pre_process_excerpt', $excerpt);
		}
		else {
			// and if there's no <!--more--> tag, we can spit it out and go on our merry way
			// after we fix [gallery] IDs, which now happens in the ljxp_inline_gallery filter
			$the_content = $post->post_content;
			add_filter( 'post_gallery', 'ljxp_inline_gallery', 9, 2);
			$the_content = apply_filters('the_content', $the_content);
			$the_content = str_replace(']]>', ']]&gt;', $the_content);
			$the_content = ljxp_fix_relative_links($the_content);
			$the_content = apply_filters('ljxp_pre_process_post', $the_content);
			remove_filter( 'post_gallery', 'ljxp_inline_gallery', 9, 2);
		
			if(strpos($the_content, "<!--more") === false) {
				$the_event .= $the_content;
			}
			else {
				$content = explode("<!--more", $the_content, 2);
				$split_content = explode("-->", $content[1], 2);
				$content[1] = $split_content[1];
				$more_text = trim( $split_content[0] );
				if (empty($more_text) )  
					$more_text = $options['cut_text'];
				$the_event .= $content[0];
				switch ($options['more']) {
					case "copy":
						$the_event .= $content[1];
						break;
					case "link":
						$the_event .= sprintf('<p><a href="%s#more-%s">', get_permalink($post->ID), $post->ID) .
							$more_text . '</a></p>';
						break;
					case "lj-cut":
						$the_event .= '<lj-cut text="'.$more_text.'">'.$content[1].'</lj-cut>';
						break;
				}
			}
		}
	}

	// Either prepend or append the header to $the_event, depending on the
	// config setting
	// Remember that 0 is at the top, 1 at the bottom
	if($options['header_loc']) {
		$the_event .= $postHeader;
	}
	else {
		$the_event = $postHeader.$the_event;
	}

	// Get the most recent post (to see if this is it - it it's not, backdate)
//	$recent_id = $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_status='publish' OR post_status='private' AND post_type='post' ORDER BY post_date DESC LIMIT 1");

	// Get a timestamp for retrieving dates later
	$date = strtotime($post->post_date);

	$args = array(	'username'			=> $options['username'],
					'auth_method'		=> 'challenge',
					'auth_challenge'	=> $challenge,
					'auth_response'		=> md5($challenge . $options['password']),					// By spec, auth_response is md5(challenge + md5(pass))
					'ver'				=> '1',														// Receive UTF-8 instead of ISO-8859-1
					'event'				=> $the_event,
					'subject'			=> apply_filters('the_title', $post->post_title),
					'year'				=> date('Y', $date),
					'mon'				=> date('n', $date),
					'day'				=> date('j', $date),
					'hour'				=> date('G', $date),
					'min'				=> date('i', $date),
					'props'				=> array(
												'opt_nocomments'	=> !$options['comments'], 		// allow comments?
												'opt_preformatted'	=> true, 						// event text is preformatted
												'opt_backdated'		=> $bulk,	 					// if true, posts will not appear in friends lists
												'taglist'			=> ($options['tag'] != 0 ? $cat_string : ''),
												'picture_keyword'	=> (!empty($options['userpic']) ? $options['userpic'] : ''),
											),
					'usejournal'		=> (!empty($options['community']) ? $options['community'] : $options['username']),
					);

	// Set the privacy level according to the settings
	if ($post->post_status == 'public') {
		switch($options['privacy']) {
			case "public":
				$args['security'] = 'public';
				break;
			case "private":
				$args['security'] = 'private';
				break;
			case "friends":
				$args['security'] = 'usemask';
				$args['allowmask'] = 1;
				break;
			default :
				$args['security'] = "public";
				break;
		}
	}
	elseif ($post->post_status == 'private') {
		switch($options['privacy_private']) {
			case "public":
				$args['security'] = 'public';
				break;
			case "private":
				$args['security'] = 'private';
				break;
			case "friends":
				$args['security'] = 'usemask';
				$args['allowmask'] = 1;
				break;
			default :
				$args['security'] = "private";
				break;
		}
	}

	// Assume this is a new post
	$method = 'LJ.XMLRPC.postevent';

	// But check to see if there's an LJ post associated with our WP post
	if(get_post_meta($post->ID, 'ljID', true)) {
		// If there is, add the itemid attribute and change from posting to editing
		$args['itemid'] = get_post_meta($post->ID, 'ljID', true);
		$method = 'LJ.XMLRPC.editevent';
	}
	
	// And awaaaayyy we go!
	if (!$client->query($method, $args)) {
		$errors[$client->getErrorCode()] = $client->getErrorMessage();
	}

	$response = $client->getResponse();
	// If we were making a new post on LJ, we need the itemid for future reference
	if ('LJ.XMLRPC.postevent' == $method)
		$ljID = add_post_meta($post->ID, 'ljID', $response['itemid'], true);
	// grab the URL either way
	if (!empty($response['url']))
		$ljURL = add_post_meta($post->ID, 'ljURL', $response['url'], true);
	
	// If there were errors, store them
	update_option('ljxp_error_notice', $errors);
	
	// If you don't return this, other plugins and hooks won't work
	return $post->ID;
}

function ljxp_delete($post_id) {
	// Pull the post_id
	$ljxp_post_id = get_post_meta($post_id, 'ljID', true);

	$errors = array();

	// Ensures that there's actually a value. If the post was never
	// cross-posted, the value wouldn't be set, and there's no point in
	// deleting entries that don't exist
	if($ljxp_post_id == 0) {
		return $post_id;
	}

	$options = ljxp_get_options();
	
	// And open the XMLRPC interface
	$client = new IXR_Client($options['host'], '/interface/xmlrpc');

	// Request the challenge for authentication
	if (!$client->query('LJ.XMLRPC.getchallenge')) {
		$errors[$client->getErrorCode()] = $client->getErrorMessage();
	}

	// And retrieve the challenge that LJ returns
	$response = $client->getResponse();
	$challenge = $response['challenge'];

	// Most of this is the same as before. The important difference is the
	// value of $args[event]. By setting it to a null value, LJ deletes the
	// entry. Really rather klunky way of doing things, but not my code!
	$args = array(
				'username' => $options['username'],
				'auth_method' => 'challenge',
				'auth_challenge' => $challenge,
				'auth_response' => md5($challenge . $options['password']),
				'itemid' => $ljxp_post_id,
				'event' => "",
				'subject' => "Delete this entry",
				'year' => date('Y'),
				'mon' => date('n'),
				'day' => date('j'),
				'hour' => date('G'),
				'min' => date('i'),
				'usejournal' => (!empty($options['community']) ? $options['community'] : $options['username']),
	);

	// And awaaaayyy we go!
	if (!$client->query('LJ.XMLRPC.editevent', $args))
		$errors[$client->getErrorCode()] = $client->getErrorMessage();

	delete_post_meta($post_id, 'ljID');
	delete_post_meta($post_id, 'ljURL');
	update_option('ljxp_error_notice', $errors );
	
	return $post_id;
}

function ljxp_edit($post_id) {
	// This function will delete a post from LJ if it's changed from the
	// published status or if crossposting was just disabled on this post
	
	// Pull the post_id
	$ljxp_post_id = get_post_meta($post_id, 'ljID', true);

	// Ensures that there's actually a value. If the post was never
	// cross-posted, the value wouldn't be set, so we're done
	if(0 == $ljxp_post_id) {
		return $post_id;
	}

	$post = & get_post($post_id);
	$options = ljxp_get_options();

	// If any of the following are true, delete it:
	// - It's changed to private, and we've chosen not to crosspost private entries
	// - It now isn't published or private (trash, pending, draft, etc.)
	// - It was crossposted but now it's set to not crosspost
	if (
		('private' == $post->post_status && $options['privacy_private'] == 'no_lj') || 
		('publish' != $post->post_status && 'private' != $post->post_status) || 
		1 == get_post_meta($post_id, 'no_lj', true)
		) {
		ljxp_delete($post_id);
	}

	if ('private' == $post->post_status && $options['privacy_private'] != 'no_lj')
		ljxp_post($post_id);

	return $post_id;
}

// ---- Filters and Helpers -----
function ljxp_inline_gallery($output, $attr) {
	global $post;
	
	// We're trusting author input, so let's at least make sure it looks like a valid orderby statement
	if ( isset( $attr['orderby'] ) ) {
		$attr['orderby'] = sanitize_sql_orderby( $attr['orderby'] );
		if ( !$attr['orderby'] )
			unset( $attr['orderby'] );
	}

	extract(shortcode_atts(array(
		'order'      => 'ASC',
		'orderby'    => 'menu_order ID',
		'id'         => $post->ID,
		'itemtag'    => 'dl',
		'icontag'    => 'dt',
		'captiontag' => 'dd',
		'columns'    => 3,
		'size'       => 'thumbnail',
		'include'    => '',
		'exclude'    => ''
	), $attr));

	$id = intval($id);
	if ( 'RAND' == $order )
		$orderby = 'none';

	if ( !empty($include) ) {
		$include = preg_replace( '/[^0-9,]+/', '', $include );
		$_attachments = get_posts( array('include' => $include, 'post_status' => 'inherit', 'post_type' => 'attachment', 'post_mime_type' => 'image', 'order' => $order, 'orderby' => $orderby) );

		$attachments = array();
		foreach ( $_attachments as $key => $val ) {
			$attachments[$val->ID] = $_attachments[$key];
		}
	} elseif ( !empty($exclude) ) {
		$exclude = preg_replace( '/[^0-9,]+/', '', $exclude );
		$attachments = get_children( array('post_parent' => $id, 'exclude' => $exclude, 'post_status' => 'inherit', 'post_type' => 'attachment', 'post_mime_type' => 'image', 'order' => $order, 'orderby' => $orderby) );
	} else {
		$attachments = get_children( array('post_parent' => $id, 'post_status' => 'inherit', 'post_type' => 'attachment', 'post_mime_type' => 'image', 'order' => $order, 'orderby' => $orderby) );
	}

	if ( empty($attachments) )
		return '';

	if ( is_feed() ) {
		$output = "\n";
		foreach ( $attachments as $att_id => $attachment )
			$output .= wp_get_attachment_link($att_id, $size, true) . "\n";
		return $output;
	}

	$itemtag = tag_escape($itemtag);
	$captiontag = tag_escape($captiontag);
	$columns = intval($columns);
	$itemwidth = $columns > 0 ? floor(100/$columns) : 100;
	$float = is_rtl() ? 'right' : 'left';

	$selector = "gallery-{$instance}";

	$gallery_style = $gallery_div = '';
	$size_class = sanitize_html_class( $size );
	$gallery_div = "<div style='margin: auto;'>";
	
	$i = 0;
	foreach ( $attachments as $id => $attachment ) {
		$link = isset($attr['link']) && 'file' == $attr['link'] ? wp_get_attachment_link($id, $size, false, false) : wp_get_attachment_link($id, $size, true, false);

		$output .= "<{$itemtag} style='float: {$float}; margin-top: 10px; text-align: center; width: {$itemwidth}%;'>";
		$output .= "
			<{$icontag} class='gallery-icon'>
				$link
			</{$icontag}>";
		if ( $captiontag && trim($attachment->post_excerpt) ) {
			$output .= "
				<{$captiontag} style='margin-left: 0;'>
				" . wptexturize($attachment->post_excerpt) . "
				</{$captiontag}>";
		}
		$output .= "</{$itemtag}>";
		if ( $columns > 0 && ++$i % $columns == 0 )
			$output .= '<br style="clear: both" />';
	}

	$output .= "
			<br style='clear: both;' />
		</div>\n";

	return $output;
}

function ljxp_fix_relative_links($content) {
	// find all href attributes
	preg_match_all('/<a[^>]* href=[\'"]?([^>\'" ]+)/', $content, $matches);
	for ($i=0; $i<count($matches[0]); $i++) {
		$hrefs[] = $matches[1][$i];
	}
	if (!empty($hrefs)) {
		$count = count($hrefs);
		if (MULTISITE)
			$site = network_site_url();
		else
			$site = site_url();

		foreach ($hrefs as $href) {
			if (preg_match('/^http:\/\//', $href)) { 
				$linkpath = $href; // no change			
			}
			// href="/images/foo"
			elseif ('/' == substr($href, 0, 1)) { 
				$linkpath = $site . $href;
			}
			// href="../../images/foo" or href="images/foo"
			else { 
				$linkpath = $site . '/' . $href;
			}
			
		 	if ( $linkpath != $href )
				$linkpath = ljxp_remove_dot_segments($linkpath);
				$content = str_replace($href, $linkpath, $content);
		} // foreach
	} // if empty
	return $content;
}

function ljxp_remove_dot_segments( $path ) {
	$inSegs  = preg_split( '!/!u', $path );
	$outSegs = array( );
	foreach ( $inSegs as $seg )
	{
	    if ( empty( $seg ) || $seg == '.' )
	        continue;
	    if ( $seg == '..' )
	        array_pop( $outSegs );
	    else
	        array_push( $outSegs, $seg );
	}
	$outPath = implode( '/', $outSegs );
	if ( $path[0] == '/' )
	    $outPath = '/' . $outPath;
	if ( $outPath != '/' && (mb_strlen($path)-1) == mb_strrpos( $path, '/', 'UTF-8' ) )
    	$outPath .= '/';
	$outPath = str_replace('http:/', 'http://', $outPath);
	$outPath = str_replace('https:/', 'https://', $outPath);
	$outPath = str_replace(':///', '://', $outPath);
	return $outPath;
}

// in case this server doesn't have php_mbstring enabled in php.ini...
if (!function_exists('mb_strlen')) {
	function mb_strlen($string) {
		return strlen(utf8_decode($string));
	}
}
if (!function_exists('mb_strrpos')) {
	function mb_strrpos($haystack, $needle, $offset = 0) {
		return strrpos(utf8_decode($haystack), $needle, $offset);
	}
}

// Borrow wp-lj-comments by A-Bishop:
if (!function_exists('lj_comments')) {
	function lj_comments($post_id) {
		if ( !is_wp_error( $post_id ) ) {
			$link = plugins_url( "wp-lj-comments.php?post_id=".$post_id , __FILE__ );
			return '<img src="'.$link.'" border="0">';
		}
	return '';
	}
}

// ---- Error Reporting -----
function ljxp_error_notice() {
	$errors = get_option('ljxp_error_notice');
	if (!empty($errors)) { 
    	add_action('admin_notices', 'lj_xp_print_notices');
	}
}

function lj_xp_print_notices() {
	$errors = get_option('ljxp_error_notice');
	$options = ljxp_get_options();
	$class = 'updated';
	if (!empty($errors) && isset($_GET['action']) && $_GET['action'] == 'edit') { // show this only after we've posted something
		foreach ($errors as $code => $error) {
			$code = trim( (string)$code);
			switch ($code) {
				case '-32300' :
					$msg .= sprintf(__('Could not connect to %s. This post has not been crossposted. (%s : %s)', 'lj-xp'), $options['host'], $code, $error );
					$class = 'error';
					break;
				case '-32701' :
				case '-32702' :
					$msg .= sprintf(__('There was a problem with the encoding of your post, and it could not be crossposted to %s. (%s : %s)', 'lj-xp'), $options['host'], $code, $error );
					$class = 'error';
					break;
				case '101' : 
					$msg .= sprintf(__('Could not crosspost. Please reenter your %s password in the <a href="%s">options screen</a> and try again. (%s : %s)', 'lj-xp'), $options['host'], 'options-general.php?page=lj-xp-options.php', $code, $error );
					$class = 'error';
					break;
				case '302' : 
					$msg .= sprintf(__('Could not crosspost the updated entry to %s. (%s : %s)', 'lj-xp'), $options['host'], $code, $error );
					$class = 'error';
					break;
				case 'ljID':
					$class = 'error';
					if (!$error) $msg .= 'Could not add post meta for ljID.';
				case 'ljURL':
					$class = 'error';
					if (!$error) $msg .= 'Could not add post meta for ljURL.';
				default: 
					$msg .= sprintf(__('Error from %s: %s : %s', 'lj-xp'), $options['host'], $code, $error );
					$class = 'error';
					break;
			}
		}
	}
	if ($class == 'updated') // still good?
		$msg = sprintf(__("Crossposted to %s.", 'lj-xp'), $options['host']); 
	echo '<div class="'.$class.'"><p>'.$msg.'<p></div>';
	update_option('ljxp_error_notice', ''); // turn off the message
}

// ---- Custom Fields -----
function ljxp_meta_box() {
	add_meta_box( 'ljxp_meta', __('LiveJournal Crossposting', 'lj-xp'), 'ljxp_sidebar', 'post', 'normal', 'high' );
}

function ljxp_sidebar() {
	global $post;
	$options = ljxp_get_options();
	$userpics = $options['userpics'];
	if (is_array($userpics)) sort($userpics);
?>
	<div class="ljxp-radio-column">
	<h4><?php _e("Crosspost?", 'lj-xp'); ?></h4>
	<ul>
		<?php $ljxp_crosspost = get_post_meta($post->ID, 'no_lj', true);  ?>
			<li><label class="selectit" for="ljxp_crosspost_go">
				<input type="radio" <?php checked($ljxp_crosspost, 1); ?> value="1" name="ljxp_crosspost" id="ljxp_crosspost_go"/>
				<?php _e('Crosspost', 'lj-xp'); if ($options['crosspost'] == 1) _e(' <em>(default)</em>', 'lj-xp'); ?>
			</label></li>

			<li><label class="selectit" for="ljxp_crosspost_nogo">
				<input type="radio" <?php checked($ljxp_crosspost, 0); ?> value="0" name="ljxp_crosspost" id="ljxp_crosspost_nogo"/>
				<?php _e('Do not crosspost', 'lj-xp'); if ($options['crosspost'] == 0) _e(' <em>(default)</em>', 'lj-xp'); ?>
			</label></li>

	</ul>
	</div>
	<div class="ljxp-radio-column">
	<h4><?php _e("Comments", 'lj-xp'); ?></h4>
	<ul>
		<?php 
		$ljxp_comments = get_post_meta($post->ID, 'ljxp_comments', true); ?>
			<li><label class="selectit" for="ljxp_comments_on">
				<input type="radio" <?php checked($ljxp_comments, 1); ?> value="1" name="ljxp_comments" id="ljxp_comments_on"/>
				<?php _e('Comments on', 'lj-xp'); if ($options['comments'] == 1) _e(' <em>(default)</em>', 'lj-xp'); ?>
			</label></li>
			<li><label class="selectit" for="ljxp_comments_off">
				<input type="radio" <?php checked($ljxp_comments, 0); ?> value="0" name="ljxp_comments" id="ljxp_comments_off"/>
				<?php _e('Comments off', 'lj-xp'); if ($options['comments'] == 0) _e(' <em>(default)</em>', 'lj-xp'); ?>
			</label></li>

		</ul>
		</div>
		<div class="ljxp-radio-column">
		<h4><?php _e("Privacy", 'lj-xp'); ?></h4>
		<ul>
			<?php 
			$ljxp_privacy = get_post_meta($post->ID, 'ljxp_privacy', true); ?>
			<li><label class="selectit" for="ljxp_privacy_public">
				<input type="radio" <?php checked($ljxp_privacy, 'public'); ?> value="public" name="ljxp_privacy" id="ljxp_privacy_public"/>
				<?php _e('Public post', 'lj-xp'); if ($options['privacy'] == 'public') _e(' <em>(default)</em>', 'lj-xp'); ?>
			</label></li>
			<li><label class="selectit" for="ljxp_privacy_private">
				<input type="radio" <?php checked($ljxp_privacy, 'private'); ?> value="private" name="ljxp_privacy" id="ljxp_privacy_private"/>
				<?php _e('Private post', 'lj-xp'); if ($options['privacy'] == 'private') _e(' <em>(default)</em>', 'lj-xp'); ?>
			</label></li>
			<li><label class="selectit" for="ljxp_privacy_friends">
				<input type="radio" <?php checked($ljxp_privacy, 'friends'); ?> value="friends" name="ljxp_privacy" id="ljxp_privacy_friends"/>
				<?php _e('Friends only', 'lj-xp'); if ($options['privacy'] == 'friends') _e(' <em>(default)</em>', 'lj-xp'); ?>
			</label></li>
			
			</ul>
		</div>
		
			<?php if (!empty($userpics)) : ?>
		<p class="ljxp-userpics">
					<label for="ljxp_userpic"><?php _e('Choose userpic: ', 'lj-xp'); ?></label>
					<select name="ljxp_userpic">
						<option value="0"><?php _e('Use default', 'lj-xp'); ?></option>
					<?php
						$selected_userpic = get_post_meta($post->ID, 'ljxp_userpic', true);
						foreach ($userpics as $userpic) { ?>
							<option <?php selected($selected_userpic, $userpic); ?> value="<?php esc_attr_e($userpic); ?>"><?php esc_html_e($userpic); ?></option>
						<?php } ?>
					</select>
			</p>
			<?php endif; // $userpics
			?>
		<p class="ljxp-cut-text">
		<?php 
		$cuttext = get_post_meta($post->ID, 'ljxp_cut_text', true);
		 ?>
			<label for="ljxp_cut_text">
				<?php _e('Link text for LJ cut tag (if &lt;!--more--&gt; tag is used)', 'lj-xp'); ?>
				<input type="text" value="<?php esc_attr_e($cuttext); ?>" name="ljxp_cut_text" id="ljxp_cut_text" />
				<p><span class="description"><?php printf(__('Default: %s', 'lj-xp'), $options['cut_text']); ?></span></p>
			</label>
		</p>
		<?php
}

function ljxp_save($post_id) {
	// If the magic crossposting variable isn't equal to 'crosspost', then the
	// box wasn't checked
	// Using publish_post hook for the case of a state change---this will
	// be called before crossposting occurs
	// Using save_post for the case where it's draft or private - the value
	// still needs to be saved
	// Using edit_post for the case in which it's changed from crossposted to
	// not crossposted in an edit
	if(isset($_POST['ljxp_crosspost'])) {
		delete_post_meta($post_id, 'no_lj');
		if(0 == $_POST['ljxp_crosspost']) {
			add_post_meta($post_id, 'no_lj', '1', true);
		}
	}
	if(isset($_POST['ljxp_comments'])) {
		delete_post_meta($post_id, 'ljxp_comments');
		if($_POST['ljxp_comments'] !== 0) {
			add_post_meta($post_id, 'ljxp_comments', $_POST['ljxp_comments'], true);
		}
	}

	if(isset($_POST['ljxp_privacy'])) {
			delete_post_meta($post_id, 'ljxp_privacy');
		if($_POST['ljxp_privacy'] !== 0) {
			add_post_meta($post_id, 'ljxp_privacy', $_POST['ljxp_privacy'], true);
		}
	}

	if(isset($_POST['ljxp_userpic'])) {
		delete_post_meta($post_id, 'ljxp_userpic');
		if(!empty($_POST['ljxp_userpic'])) {
			add_post_meta($post_id, 'ljxp_userpic', $_POST['ljxp_userpic'], true);
		}
	}
	
	if(isset($_POST['ljxp_cut_text'])) {
		delete_post_meta($post_id, 'ljxp_cut_text');
		if(!empty($_POST['ljxp_cut_text'])) {
			add_post_meta($post_id, 'ljxp_cut_text', esc_html($_POST['ljxp_cut_text']), true);
		}
	}
}

// ----- Bulk Processes -----
function ljxp_delete_all($repost_ids) {
	foreach((array)$repost_ids as $id) {
		ljxp_delete($id);
	}
	return __('Deleted all entries from the other journal.', 'lj-xp');
}

function ljxp_post_all($repost_ids = '') {
	if (empty($repost_ids)) {
		global $wpdb;
		$repost_ids = $wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE post_status='publish' OR post_status='private' AND post_type='post'");
	}
	@set_time_limit(0);
	foreach((array)$repost_ids as $id) {
		ljxp_post($id, true); // true here sets the backdate option on the posts so they don't flood the friends list
	}
	return __('Posted all entries to the other journal.', 'lj-xp');
}

// ---- Style -----
function ljxp_css() { ?>
	<style type="text/css">
	div.ljxp-radio-column ul li { list-style: none; padding: 0; text-indent: 0; margin-left: 0; }
	div#post-body-content div.ljxp-radio-column, div#post-body-content p.ljxp-userpics { float: left; width: 22%; margin-right: 2%; }
	div#side-info-column div.ljxp-radio-column ul { margin: 1em; }
	p.ljxp-userpics label { font-weight: bold; }
	p.ljxp-userpics select { display: block; margin: 1em 0; }
	p.ljxp-cut-text { clear: both; }
	input#ljxp_cut_text { width: 90%; }
	</style>
<?php 
}

function ljxp_settings_css() { ?>
	<style type="text/css">
	table.editform th { text-align: left; }
	dl { width: 47%; margin-right: 2%; margin-top: 1em; float: left; color: #666; }
	dt { font-weight: bold; }
	#ljxp dd { font-style: italic; }
	ul#category-children { list-style: none; height: 15em; width: 20em; overflow-y: scroll; border: 1px solid #dfdfdf; padding: 0 1em; background: #fff; border-radius: 4px; -moz-border-radius: 4px; -webkit-border-radius: 4px; }
 	ul.children { margin-left: 1.5em; }
	tr#scary-buttons { display: none; }
	#delete_all { font-weight: bold; color: #c00; }
	</style>
<?php
}


// ---- Hooks -----
add_action('admin_menu', 'ljxp_add_pages');
$options = get_option('ljxp');
if (!empty($options)) {
	add_action('admin_init', 'ljxp_meta_box', 1);
	add_action('add_meta_boxes', 'ljxp_meta_box');
	add_action('admin_head-post-new.php', 'ljxp_css');
	add_action('admin_head-post.php', 'ljxp_css');
	add_action('publish_post', 'ljxp_post');
	add_action('publish_future_post', 'ljxp_post');
	add_action('draft_to_private', 'ljxp_post');
	add_action('new_to_private', 'ljxp_post');
	add_action('pending_to_private', 'ljxp_post');
	add_action('private_to_public', 'ljxp_edit');
	add_action('private_to_password', 'ljxp_edit');
	add_action('untrashed_post', 'ljxp_edit');
	add_action('edit_post', 'ljxp_edit');
	add_action('delete_post', 'ljxp_delete');
	add_action('save_post', 'ljxp_save', 1);
	add_action('admin_head-post.php', 'ljxp_error_notice');
	add_action('admin_head-post-new.php', 'ljxp_error_notice');
}

// i18n
$plugin_dir = basename(dirname(__FILE__)). '/lang';
load_plugin_textdomain( 'lj-xp', 'wp-content/plugins/' . $plugin_dir, $plugin_dir );
?>