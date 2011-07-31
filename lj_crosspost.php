<?php
/*
Plugin Name: LiveJournal Crossposter
Plugin URI: http://ebroder.net/livejournal-crossposter/
Description: Automatically copies all posts to a LiveJournal or other LiveJournal-based blog. Editing or deleting a post will be replicated as well. This plugin was inspired by <a href="http://blog.mytechaid.com/">Scott Buchanan's</a> <a href="http://blog.mytechaid.com/archives/2005/01/10/xanga-crossposter/">Xanga Crossposter</a>
Version: 1.0.1
Author: Evan Broder
Author URI: http://ebroder.net/

	Copyright (c) 2005 Evan Broder

	Permission is hereby granted, free of charge, to any person obtaining a
	copy of this software and associated documentation files (the "Software"),
	to deal in the Software without restriction, including without limitation
	the rights to use, copy, modify, merge, publish, distribute, sublicense,
	and/or sell copies of the Software, and to permit persons to whom the
	Software is furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in
	all copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
	FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
	DEALINGS IN THE SOFTWARE. 
*/

require_once(ABSPATH . '/wp-includes/class-IXR.php');
require_once(ABSPATH . '/wp-includes/template-functions-links.php');

// Create the LJXP Options Page
function ljxp_add_pages() {
	add_options_page("LiveJournal", "LiveJournal", 6, __FILE__, 'ljxp_display_options');
}

// Display the options page
function ljxp_display_options() {
	// Just in case they don't exist, create the options
	add_option('ljxp_host');
	add_option('ljxp_username');
	add_option('ljxp_password');
	add_option('ljxp_custom_name_on');
	add_option('ljxp_custom_name');
	
	// Retrieve these for the form
	$old_host = get_option('ljxp_host');
	$old_username = get_option('ljxp_username');
	$old_name = get_option('ljxp_custom_name');
	$old_name_on = get_option('ljxp_custom_name_on');
	
	// host should default to LJ - it's what most people use anyway
	if("" == $old_host) {
		// This sets up a default value. If we don't store it, the default val
		// will never get stored to the database
		update_option('ljxp_host', 'www.livejournal.com');
		$old_host = "www.livejournal.com";
	}
	
	// I think that we should default to just using the name of the blog, so
	// let's set it - same reason as above
	if("" == $old_name_on) {
		update_option('ljxp_custom_name_on', '0');
		$old_name_on = "0";
	}
	
	// If we're handling a submission, save the data
	if(isset($_REQUEST[update_lj_options])) {
		// Avoiding useless queries - confirming that the value changed
		if($old_host != $_REQUEST[host]) {
			update_option('ljxp_host', $_REQUEST[host]);
			// So that the new value shows up in the form
			$old_host = $_REQUEST[host];
		}
		
		if($old_username != $_REQUEST[username]) {
			update_option('ljxp_username', $_REQUEST[username]);
			$old_username = $_REQUEST[username];
		}
		
		if($old_name_on != $_REQUEST['custom_name_on']) {
			update_option('ljxp_custom_name_on', $_REQUEST['custom_name_on']);
			$old_name_on = $_REQUEST['custom_name_on'];
		}
		
		if($old_name != $_REQUEST['custom_name']) {
			update_option('ljxp_custom_name', $_REQUEST['custom_name']);
			$old_name = $_REQUEST['custom_name'];
		}
		
		// If a password value is entered, md5 it for security and store to the
		// database
		// LJ challenge authentication works with only knowing the md5 of the
		// password
		if($_REQUEST[password] != "") {
			update_option('ljxp_password', md5($_REQUEST[password]));
		}
		
		// Copied from another options page
		echo '<div class="updated"><p><strong>Options saved.</strong></p></div>';
	}
	
	// And, finally, output the form
	// May add some Javascript to disable the custom_name field later - don't
	// feel like it now, though
?>
<div class="wrap">
	<h2>LiveJournal Crossposter Options</h2>
	<form method="post" action="<?php echo $_SERVER[REQUEST_URI]; ?>">
		<table width="100%" cellspacing="2" cellpadding="5" class="editform">
			<tr valign="top">
				<th width="33%" scope="row">LiveJournal-compliant host:</th>
				<td><input name="host" type="text" id="host" value="<?php echo $old_host; ?>" size="40" /><br />
				If you are using a LiveJournal-compliant site other than
				LiveJournal (like DeadJournal), enter the domain name here.
				LiveJournal users can use the default value</td>
			</tr>
			<tr valign="top">
				<th scope="row">LJ Username</th>
				<td><input name="username" type="text" id="username" value="<?php echo $old_username; ?>" size="40" /></td>
			</tr>
			<tr valign="top">
				<th scope="row">LJ Password</th>
				<td><input name="password" type="password" id="password" value="" size="40" /><br />
				Only enter a value if you wish to change the stored password.
				Leaving this field blank will not erase any passwords already 
				stored.</td>
			</tr>
		</table>
		<fieldset class="options">
			<legend>Blog Header</legend>
			<table width="100%" cellspacing="2" cellpadding="5" class="editform">
				<tr valign="top">
					<th width="33%" scope="row">Set blog name for crosspost header</th>
					<td><label><input name="custom_name_on" type="radio" value="0" <?php
					if($old_name_on == 0) {
						echo 'checked="checked"';
					}
					?>/> Use the title of your blog (<?php echo bloginfo('name'); ?>)</label><br />
					<label><input name="custom_name_on" type="radio" value="1" <?php
					if($old_name_on == 1) {
						echo 'checked="checked"';
					}
					?>/> Use a custom title</label></td>
				</tr>
				<tr valign="top">
					<th scope="row">Custom blog title</th>
					<td><input name="custom_name" type="text" id="custom_name" value="<?php echo $old_name; ?>" size="40" /><br />
					If you chose to use a custom title above, enter the title here. This will be used in the header which links back to this site at the top of each post on the LiveJournal.</td>
				</tr>
			</table>
		</fieldset>
		<p class="submit">
			<input type="submit" name="update_lj_options" value="Save Options &raquo;" />
		</p>
	</form>
</div>
<?php
}

function ljxp_post($post_id) {
	global $wpdb;
	
	// Get the relevent info out of the database
	$host = get_option('ljxp_host');
	$user = get_option('ljxp_username');
	$pass = get_option('ljxp_password');
	$custom_name_on = get_option('ljxp_custom_name_on');
	$custom_name = get_option('ljxp_custom_name');
	
	// And create our connection
	$client = new IXR_Client($host, '/interface/xmlrpc');
	
	//$client->debug = true;
	
	// Get the challenge string
	// Using challenge for the most security. Allows pwd hash to be stored
	// instead of pwd
	if (!$client->query('LJ.XMLRPC.getchallenge')) {
		die('Something went wrong - '.$client->getErrorCode().' : '.$client->getErrorMessage());
	}
	
	// And retrieve the challenge string
	$response = $client->getResponse();
	$challenge = $response[challenge];
	
	// Same header as Xanga
	$postHeader = '<p style="border: 1px solid black; padding: 3px;"><b>Originally published at <a href="'.
		get_permalink($post_id).'">';
	
	// Insert the name of the page we're linking back to based on the options set
	if(!$custom_name_on) {
		$postHeader .= bloginfo('name');
	}
	else {
		$postHeader .= $custom_name;
	}
	
	$postHeader .= '</a>.  Please leave any <a href="'.get_permalink($post_id).
		'#comments">comments</a> there.</b></p>';
	
	// Bypassing the get_post() function because it caches and teh cache isn't
	// updated when the entry is. A noble, query-saving idea, but not so hot
	// in the implimentation, really
	$post = & get_post($post_id);
	
	// Get a timestamp for retrieving dates later
	$date = strtotime($post->post_date);
	
	$args = array();
	$args[username] = $user;
	$args[auth_method] = 'challenge';
	$args[auth_challenge] = $challenge;
	// Formula for challenge response is
	// md5(challenge + md5(pwd))
	$args[auth_response] = md5($challenge . $pass);
	
	// The header + the actual post
	$args[event] = $postHeader . $post->post_content;
	$args[subject] = $post->post_title;
	
	// All of the relevent dates and times
	$args[year] = date('Y', $date);
	$args[mon] = date('n', $date);
	$args[day] = date('j', $date);
	$args[hour] = date('G', $date);
	$args[min] = date('i', $date);
	$args[props] = array("opt_nocomments" => true);
	
	// Assume this is a new post
	$method = 'LJ.XMLRPC.postevent';
	
	// But check to see if there's an LJ post associated with our WP post
	if(get_post_meta($post_id, 'ljID', true)) {
		// If there is, add the itemid attribute and change from posting to editing
		$args[itemid] = get_post_meta($post_id, 'ljID', true);
		$method = 'LJ.XMLRPC.editevent';
	}
	
	// And awaaaayyy we go!
	if (!$client->query($method, $args)) {
		die('Something went wrong - '.$client->getErrorCode().' : '.$client->getErrorMessage());
	}
	
	// If we were making a new post on LJ, we need the itemid for future reference
	if('LJ.XMLRPC.postevent' == $method) {
		$response = $client->getResponse();
		// Store it to the metadata
		add_post_meta($post_id, 'ljID', $response[itemid]);
	}
	
	// If you don't return this, other plugins and hooks won't work
	return $post_id;
}

function ljxp_delete($post_id) {
	// Pull the post_id
	$ljxp_post_id = get_post_meta($post_id, 'ljID', true);
	
	// Ensures that there's actually a value. If the post was never
	// cross-posted, the value wouldn't be set, and there's no point in
	// deleting entries that don't exist
	if($ljxp_post_id == 0) {
		return;
	}
	
	// Get the necessary login info	
	$host = get_option('ljxp_host');
	$user = get_option('ljxp_username');
	$pass = get_option('ljxp_password');
	
	// And open the XMLRPC interface
	$client = new IXR_Client($host, '/interface/xmlrpc');
	
	//$client->debug = true;
	
	// Request the challenge for authentication
	if (!$client->query('LJ.XMLRPC.getchallenge')) {
		die('Something went wrong - '.$client->getErrorCode().' : '.$client->getErrorMessage());
	}
	
	// And retrieve the challenge that LJ returns
	$response = $client->getResponse();
	$challenge = $response[challenge];
	
	// Most of this is the same as before. The important difference is the
	// value of $args[event]. By setting it to a null value, LJ deletes the
	// entry. Really rather klunky way of doing things, but not my code!
	$args = array();
	$args[username] = $user;
	$args[auth_method] = 'challenge';
	$args[auth_challenge] = $challenge;
	$args[auth_response] = md5($challenge . $pass);
	$args[itemid] = $ljxp_post_id;
	$args[event] = "";
	$args[subject] = "Delete this entry";
	// I probably don't need to set these, but, hell, I've got it working
	$args[year] = date('Y');
	$args[mon] = date('n');
	$args[day] = date('j');
	$args[hour] = date('G');
	$args[min] = date('i');
	
	// And awaaaayyy we go!
	if (!$client->query('LJ.XMLRPC.editevent', $args)) {
		die('Something went wrong - '.$client->getErrorCode().' : '.$client->getErrorMessage());
	}
	
	return $post_id;
}

add_action('admin_menu', 'ljxp_add_pages');
if(get_option('ljxp_username') != "") {
	add_action('publish_post', 'ljxp_post', 8);
	add_action('delete_post', 'ljxp_delete', 8);
}

?>