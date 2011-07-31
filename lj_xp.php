<?php
/*
Plugin Name: LiveJournal Crossposter
Plugin URI: http://code.google.com/p/ljxp/
Description: Automatically copies all posts to a LiveJournal or other LiveJournal-based blog. Editing or deleting a post will be replicated as well.
Version: 2.1
Author: Arseniy Ivanov, Evan Broder, Corey DeGrandchamp, Stephanie Leary
Author URI: http://code.google.com/p/ljxp/
*/

/*
SCL TODO:
- Settings API
- use built-in WP stuff for curl
- Fix comments display?
/**/

// i18n
$plugin_dir = basename(dirname(__FILE__)). '/lang';
load_plugin_textdomain( 'lj-xp', 'wp-content/plugins/' . $plugin_dir, $plugin_dir );

require_once(ABSPATH . '/wp-includes/class-IXR.php');
require(ABSPATH . '/wp-includes/version.php');

// Borrow wp-lj-comments by A-Bishop:
if(!function_exists('lj_comments')){
	function lj_comments($post_id){
	$link = plugins_url( "wp-lj-comments.php?post_id=".$post_id , __FILE__ );
		return '<img src="'.$link.'" border="0">';
	}
}

// Create the LJXP Options Page
function ljxp_add_pages() {
	$pg = add_options_page("LiveJournal", "LiveJournal", 'manage_options', basename(__FILE__), 'ljxp_display_options');
	add_action("admin_head-$pg", 'ljxp_settings_css');	
}

// Add link to options page from plugin list
add_action('plugin_action_links_' . plugin_basename(__FILE__), 'lj_xp_plugin_actions');
function lj_xp_plugin_actions($links) {
	$new_links = array();
	$new_links[] = '<a href="options-general.php?page=lj_crosspost.php">' . __('Settings', 'google-analyticator') . '</a>';
	return array_merge($new_links, $links);
}

// Display the options page
function ljxp_display_options() {
	global $wpdb;

	// List all options to load
	$option_list = array(	'ljxp_host'		=> 'www.livejournal.com',
				'ljxp_username'		=> '',
				'ljxp_password'		=> '',
				'ljxp_custom_name_on'	=> false,
				'ljxp_custom_name'	=> '',
				'ljxp_privacy'		=> 'public',
				'ljxp_comments'		=> 0,
				'ljxp_tag'		=> '1',
				'ljxp_more'		=> 'link',
				'ljxp_community'	=> '',
				'ljxp_skip_cats'	=> array(),
				'ljxp_header_loc'	=> 0,		// 0 means top, 1 means bottom
				'ljxp_custom_header'	=> '',
				'ljxp_delete_private'	=> 1,
				'ljxp_userpics'		=> array(),
				'ljxp_cut_text'		=> __('Read the rest of this entry &raquo;', 'lj-xp'),
				); // I love trailing commas


	// Options to be filtered with 'stripslashes'
	$option_stripslash = array('ljxp_host', 'ljxp_username', 'ljxp_custom_name', 'ljxp_community', 'ljxp_custom_header', );

	foreach($option_list as $_opt => $_default){
		add_option($_opt); // Just in case it does not exist
		$options[$_opt] =(in_array($_opt, $option_stripslash) ? stripslashes(get_option($_opt))	 : get_option($_opt));	// Listed in $option_stripslash? Filter : Give away

		// If the option remains empty, set it to the default
		if($options[$_opt] == '' && $_default !== ''){
			update_option($_opt, $_default);
			$options[$_opt] = $_default;
		}

	}


	// If we're handling a submission, save the data
	if(isset($_REQUEST['update_lj_options']) || isset($_REQUEST['crosspost_all'])) {
		// Grab a list of all entries that have been crossposted
		$repost_ids = $wpdb->get_col("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='ljID'");

		// Set the update flag
		$need_update = 0;

		/*
		*   Warning. This is rather UNSAFE code. The only reason for it to remain unchanged so far is that it is inside a protected area. -- FreeAtNet
		*	TODO: fix security where appropriate
		*/

		$request_names = array('ljxp_host'				=> 'host',
								'ljxp_username'			=> 'username',
								'ljxp_custom_name_on'	=> 'custom_name_on',
								'ljxp_custom_name'		=> 'custom_name',
								'ljxp_privacy'			=> 'privacy',
								'ljxp_comments'			=> 'comments',
								'ljxp_tag'				=> 'tag',
								'ljxp_more'				=> 'more',
								'ljxp_community'		=> 'community',
								'ljxp_header_loc'		=> 'header_loc',
								'ljxp_custom_header'	=> 'custom_header',
								);

		foreach($request_names as $_orig => $_reqname){
			if(isset($_REQUEST[$_reqname]) && $_REQUEST[$_reqname] != $options[$_orig]){
				// Do the general stuff
				update_option($_orig, $_REQUEST[$_reqname]);
				$options[$_orig] = $_REQUEST[$_reqname]; // TODO: xss_clean($_REQUEST[$_reqname])

				// And then the custom actions
				switch($_orig){ // this is kinda harsh, I guess
					case 'ljxp_post' :
					case 'ljxp_username' :
					case 'ljxp_comments' :
					case 'ljxp_community' :
							ljxp_delete_all($repost_ids);
					case 'ljxp_custom_name_on' :
					case 'ljxp_privacy' :
					case 'ljxp_tag' :
					case 'ljxp_more' :
					case 'ljxp_custom_header' :
							$need_update = 1;
						break;
					case 'ljxp_custom_name' :
							if($options['ljxp_custom_name']) {
								$need_update = 1;
							}
						break;
					default:
							continue;
						break;
				}
			}
		}

		sort($options['ljxp_skip_cats']);
		$new_skip_cats = array_diff(get_all_category_ids(), (array)$_REQUEST['post_category']);
		sort($new_skip_cats);
		if($options['ljxp_skip_cats'] != $new_skip_cats) {
			update_option('ljxp_skip_cats', $new_skip_cats);
			$options['ljxp_skip_cats'] = $new_skip_cats;
		}

		unset($new_skip_cats);

		if($_REQUEST['password'] != "") {
			update_option('ljxp_password', md5($_REQUEST['password']));
		}

		if($need_update && isset($_REQUEST['update_lj_options'])) {
			@set_time_limit(0);
			ljxp_post_all($repost_ids);
		}

		if(isset($_REQUEST['crosspost_all'])) {
			@set_time_limit(0);
			ljxp_post_all($wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE post_status='publish' AND post_type='post'"));
		}

		echo '<div id="message" class="updated fade"><p><strong>';
		_e('Options saved.', 'lj-xp');
		echo '</strong></p></div>';
	}

	// If we are updating the userpics, then get a new list of userpics from the server.
	if(isset($_REQUEST['clear_userpics'])) {
		// Clear the options both in the database and for options
		update_option('ljxp_userpics', array());
		$options['ljxp_userpics'] = array();

		// Report what we did to the user
		echo '<div id="message" class="updated fade"><p><strong>';
		_e('Userpic list cleared.', 'lj-xp');
		echo '</strong></p></div>';
	}

	if(isset($_REQUEST['update_userpics'])) {
		// We keep a flag if we should keep processing since there are multiple steps here
		$keep_going = 1;

		// Userpics can be found from the user's domain, in an atom feed
		if(get_option('ljxp_username') == "") {
			// Report what we did to the user
			echo '<div id="message" class="updated fade"><p><strong>';
			_e('Cannot update userpic list unless username is set.', 'lj-xp');
			echo '</strong></p></div>';
			$keep_going = 0;
		}

		// Download the Atom feed from the server.
		if ($keep_going) {
			try {
				// Download the data into a string from LiveJournal
				// SCL: there are better built-in WP functions for this
				$curl = curl_init('http://' . get_option('ljxp_username') . '.livejournal.com/data/userpics');
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_HEADER, 0);
				$atom_data = curl_exec($curl);
				curl_close($curl);
				$keep_going = 1;
			} catch (Exception $e) {
				echo '<div id="message" class="updated fade"><p><strong>';
				_e('Cannot download Atom feed of userpics.', 'lj-xp');
				echo '</strong></p></div>';
				$keep_going = 0;
			}
		}

		// Parse the Atom feed and pull out the keywords
		if ($keep_going) {
			$new_userpics = array();

			try {
				// Parse the data as an XML string. The atom feed has many fields, but the category/@term
				// contains the name that is placed in the post metadata
				$atom_doc = new SimpleXmlElement($atom_data, LIBXML_NOCDATA);

				foreach($atom_doc->entry as $entry) {
					$attributes = $entry->category->attributes();
					$term = $attributes['term'];
					$new_userpics[] = html_entity_decode($term);
				}
			} catch (Exception $e) {
				echo '<div id="message" class="updated fade"><p><strong>';
				_e('Cannot parse Atom data from LiveJournal.', 'lj-xp');
				echo '</strong></p></div>';
				$keep_going = 0;
			}
		}

		// Finally, we have new userpics, so we save it in our array
		if ($keep_going) {
			// Sort these so they come in a consistent format.
			sort($new_userpics);
			// Persist our changes to the database and in our variables.
			update_option('ljxp_userpics', $new_userpics);
			$options['ljxp_userpics'] = $new_userpics;

			// Report our success
			echo '<div id="message" class="updated fade"><p><strong>';
			_e('Found ' . count($new_userpics) . ' userpics on LiveJournal.', 'lj-xp');
			echo '</strong></p></div>';
		}
	}

	// And, finally, output the form
	// May add some Javascript to disable the custom_name field later - don't
	// feel like it now, though
?>
<div class="wrap">
	<form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
		<h2><?php _e('LiveJournal Crossposter Options', 'lj-xp'); ?></h2>
		<table width="100%" cellspacing="2" cellpadding="5" class="form-table ui-tabs-panel">
			<tr valign="top">
				<th width="33%" scope="row"><?php _e('LiveJournal-compliant host:', 'lj-xp') ?></th>
				<td><input name="host" type="text" id="host" value="<?php print htmlentities($options['ljxp_host']); ?>" size="40" /><br />
				<span class="description">
				<?php
				_e('If you are using a LiveJournal-compliant site other than LiveJournal (like DeadJournal), enter the domain name here. LiveJournal users can use the default value', 'lj-xp');
				?>
				</span>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('LJ Username', 'lj-xp'); ?></th>
				<td><input name="username" type="text" id="username" value="<?php print htmlentities($options['ljxp_username'], ENT_COMPAT, 'UTF-8'); ?>" size="40" /></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('LJ Password', 'lj-xp'); ?></th>
				<td><input name="password" type="password" id="password" value="" size="40" /><br />
				<span  class="description"><?php
				_e('Only enter a value if you wish to change the stored password. Leaving this field blank will not erase any passwords already stored.', 'lj-xp');
				?></span>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Community', 'lj-xp'); ?></th>
				<td><input name="community" type="text" id="community" value="<?php print htmlentities($options['ljxp_community'], ENT_COMPAT, 'UTF-8'); ?>" size="40" /><br />
				<span class="description"><?php
				_e("If you wish your posts to be copied to a community, enter the community name here. Leaving this space blank will copy the posts to the specified user's journal instead", 'lj-xp');
				?></span>
				</td>
			</tr>
		</table>
		<fieldset class="options">
			<legend><h3><?php _e('Blog Header', 'lj-xp'); ?></h3></legend>
			<table width="100%" cellspacing="2" cellpadding="5" class="form-table ui-tabs-panel">
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('Crosspost header/footer location', 'lj-xp'); ?></th>
					<td>
					<label>
						<input name="header_loc" type="radio" value="0" <?php checked($options['ljxp_header_loc'], 0); ?>/>
						<?php _e('Top of post', 'lj-xp'); ?>
					</label>
					<br />
					<label>
						<input name="header_loc" type="radio" value="1" <?php checked($options['ljxp_header_loc'], 1); ?> /> 
						<?php _e('Bottom of post', 'lj-xp'); ?>
					</label></td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e('Set blog name for crosspost header/footer', 'lj-xp'); ?></th>
					<td>
						<label>
							<input name="custom_name_on" type="radio" value="0" <?php checked($options['ljxp_custom_name_on'], 0); ?>/>
							<?php printf(__('Use the title of your blog (%s)', 'lj-xp'), get_option('blogname')); ?>
						</label>
						<br />
						<label>
							<input name="custom_name_on" type="radio" value="1" <?php checked($options['ljxp_custom_name_on'], 1); ?>/>
							<?php _e('Use a custom title', 'lj-xp'); ?>
						</label>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e('Custom blog title', 'lj-xp'); ?></th>
					<td><input name="custom_name" type="text" id="custom_name" value="<?php print htmlentities($options['ljxp_custom_name'], ENT_COMPAT, 'UTF-8'); ?>" size="40" /><br />
					<span class="description"><?php
					_e('If you chose to use a custom title above, enter the title here. This will be used in the header which links back to this site at the top of each post on the LiveJournal.', 'lj-xp');
					?></span>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e('Custom crosspost header/footer', 'lj-xp'); ?></th>
					<td><textarea name="custom_header" id="custom_header" rows="3" cols="40"><?php print htmlentities($options['ljxp_custom_header'], ENT_COMPAT, 'UTF-8'); ?></textarea><br />
					<span  class="description"><?php
					_e("If you wish to use LJXP's dynamically generated post header/footer, you can ignore this setting. If you don't like the default crosspost header/footer, specify your own here. For flexibility, you can choose from a series of case-sensitive substitution strings, listed below:", 'lj-xp');
					?></span>
					<dl>
						<dt>[blog_name]</dt>
						<dd><?php _e('The title of your blog, as specified above', 'lj-xp'); ?></dd>

						<dt>[blog_link]</dt>
						<dd><?php _e("The URL of your blog's homepage", 'lj-xp'); ?></dd>

						<dt>[permalink]</dt>
						<dd><?php _e('A permanent URL to the post being crossposted', 'lj-xp'); ?></dd>

						<dt>[comments_link]</dt>
						<dd><?php _e('The URL for comments. Generally this is the permalink URL with #comments on the end', 'lj-xp'); ?></dd>

						<dt>[tags]</dt>
						<dd><?php _e('Tags with links list for the post', 'lj-xp'); ?></dd>

						<dt>[categories]</dt>
						<dd><?php _e('Categories with links list for the post', 'lj-xp'); ?></dd>

						<dt>[comments_count]</dt>
						<dd><?php _e('An image containing a comments counter', 'lj-xp'); ?></dd>

					</dl>
					</td>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('Post Privacy', 'lj-xp'); ?></h3></legend>
			<table width="100%" cellspacing="2" cellpadding="5" class="form-table ui-tabs-panel">
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('Privacy level for all posts to LiveJournal', 'lj-xp'); ?></th>
					<td>
						<label>
							<input name="privacy" type="radio" value="public" <?php checked($options['ljxp_privacy'], 'public'); ?>/>
							<?php _e('Public', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="privacy" type="radio" value="private" <?php checked($options['ljxp_privacy'], 'private'); ?> />
							<?php _e('Private', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="privacy" type="radio" value="friends" <?php checked($options['ljxp_privacy'], 'friends'); ?>/>
							<?php _e('Friends only', 'lj-xp'); ?>
						</label>
						<br />
					</td>
				</tr>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('LiveJournal Comments', 'lj-xp'); ?></h3></legend>
			<table width="100%" cellspacing="2" cellpadding="5" class="form-table ui-tabs-panel">
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('Should comments be allowed on LiveJournal?', 'lj-xp'); ?></th>
					<td>
					<label>
						<input name="comments" type="radio" value="0" <?php checked($options['ljxp_comments'], 0); ?>/>
						<?php _e('Require users to comment on WordPress', 'lj-xp'); ?>
					</label>
					<br />
					<label>
						<input name="comments" type="radio" value="1" <?php checked($options['ljxp_comments'], 1); ?>/>
						<?php _e('Allow comments on LiveJournal', 'lj-xp'); ?>
					</label>
					<br />
				</tr>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('LiveJournal Tags', 'lj-xp'); ?></h3></legend>
			<table width="100%" cellspacing="2" cellpadding="5" class="form-table ui-tabs-panel">
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('Tag entries on LiveJournal?', 'lj-xp'); ?></th>
					<td>
						<?php
							/* PHP-only comment:
							 *
							 * Yes, 1 -> 3 -> 2 -> 0 is a wierd order, but
							 * if categories = 1 and tags = 2,
							 * nothing would equal 0
							 * and
							 * tags+categories = 3
							 */
						?>
						<label>
							<input name="tag" type="radio" value="1" <?php checked($options['ljxp_tag'], 1); ?>/>
							<?php _e('Tag LiveJournal entries with WordPress categories only', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="tag" type="radio" value="3" <?php checked($options['ljxp_tag'], 3); ?>/>
							<?php _e('Tag LiveJournal entries with WordPress categories and tags', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="tag" type="radio" value="2" <?php checked($options['ljxp_tag'], 2); ?>/>
							<?php _e('Tag LiveJournal entries with WordPress tags only', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="tag" type="radio" value="0" <?php checked($options['ljxp_tag'], 0); ?>/>
							<?php _e('Do not tag LiveJournal entries', 'lj-xp'); ?>
						</label>
						<br />
						<span class="description">
						<?php
						_e('You may with to disable this feature if you are posting in an alphabet other than the Roman alphabet. LiveJournal does not seem to support non-Roman alphabets in tag names.', 'lj-xp');
						?>
						</span>
					</td>
				</tr>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('Handling of &lt;!--More--&gt;', 'lj-xp'); ?></h3></legend>
			<table width="100%" cellspacing="2" cellpadding="5" class="form-table ui-tabs-panel">
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('How should LJXP handle More tags?', 'lj-xp'); ?></th>
					<td>
						<label>
							<input name="more" type="radio" value="link" <?php checked($options['ljxp_more'], 'link'); ?>/>
							<?php _e('Link back to WordPress', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="more" type="radio" value="lj-cut" <?php checked($options['ljxp_more'], 'lj-cut'); ?>/>
							<?php _e('Use an lj-cut', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="more" type="radio" value="copy" <?php checked($options['ljxp_more'], 'copy'); ?>/>
							<?php _e('Copy the entire entry to LiveJournal', 'lj-xp'); ?>
						</label>
						<br />
					</td>
				</tr>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('Category Selection', 'lj-xp'); ?></h3></legend>
			<table width="100%" cellspacing="2" cellpadding="5" class="form-table ui-tabs-panel">
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('Select which categories should be crossposted', 'lj-xp'); ?></th>
					<td><ul id="category-children">
					<?php
					( function_exists('write_nested_categories') ?
						write_nested_categories(ljxp_cat_select(get_nested_categories(), $options['ljxp_skip_cats']))
						: wp_category_checklist(false, false, array_diff(get_all_category_ids(), (array)$options['ljxp_skip_cats']))
					);
					?></ul>
					<span class="description">
					<?php _e('Any post that has <em>at least one</em> of the above categories selected will be crossposted.'); ?>
					</span>
					</td>
				</tr>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('Userpics', 'lj-xp'); ?></h3></legend>
			<table width="100%" cellspacing="2" cellpadding="5" class="form-table ui-tabs-panel">
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('The following userpics are currently available', 'lj-xp'); ?></th>
					<td>
					<?php
						$userpics = $options['ljxp_userpics'];

						if (!$userpics)
						{
							_e('<p>No userpics have been downloaded, only the default will be available.</p>');
						}
						else
						{
							_e(implode(', ', $userpics));
						}
					?>
					<br/>
					<br/>
					<input type="submit" name="update_userpics" value="<?php _e('Update Userpics', 'lj-xp'); ?>" class="button-secondary" />

					<?php
						if (count($options['ljxp_userpics'])) {
					?>
						<input type="submit" name="clear_userpics" value="<?php _e('Clear ' . count($options['ljxp_userpics']). ' Userpics', 'lj-xp'); ?>" class="button-secondary" />

					<?php
						}
					?>
					</td>
				</tr>
			</table>
		</fieldset>
		<p class="submit">
			<input type="submit" name="crosspost_all" value="<?php _e('Update Options and Crosspost All WordPress entries', 'lj-xp'); ?>" />
			<input type="submit" name="update_lj_options" value="<?php _e('Update Options'); ?>" class="button-primary" />
		</p>
	</form>
</div>
<?php
}

function ljxp_cat_select($cats, $selected_cats) {
	foreach((array)$cats as $key=>$cat) {
		$cats[$key]['checked'] = !in_array($cat['cat_ID'], $selected_cats);
		$cats[$key]['children'] = ljxp_cat_select($cat['children'], $selected_cats);
	}
	return $cats;
}

function ljxp_post($post_id) {
	global $wpdb, $tags, $cats; // tags/cats are going to be filtered thru an external function

	// If the post was manually set to not be crossposted, give up now
	if(get_post_meta($post_id, 'no_lj', true)) {
		return $post_id;
	}

	// Get the relevent info out of the database
	$options = array(
						'host' => stripslashes(get_option('ljxp_host')),
						'user' => stripslashes(get_option('ljxp_username')),
						'pass' => get_option('ljxp_password'),
						'custom_name_on' => get_option('ljxp_custom_name_on'),
						'custom_name' => stripslashes(get_option('ljxp_custom_name')),
						'privacy' => ( (get_post_meta($post_id, 'ljxp_privacy', true) != 0) ?
									get_post_meta($post_id, 'ljxp_privacy', true) :
										get_option('ljxp_privacy') ),
						'comments' => ( (get_post_meta($post_id, 'ljxp_comments', true != 0) ) ? ( 2 - get_post_meta($post_id, 'ljxp_comments', true) ) : get_option('ljxp_comments') ),
						'tag' => get_option('ljxp_tag'),
						'more' => get_option('ljxp_more'),
						'community' => stripslashes(get_option('ljxp_community')),
						'skip_cats' => get_option('ljxp_skip_cats'),
						'copy_cats' => array_diff(get_all_category_ids(), get_option('ljxp_skip_cats')),
						'header_loc' => get_option('ljxp_header_loc'),
						'custom_header' => stripslashes(get_option('ljxp_custom_header')),
						'userpic' => get_post_meta($post_id, 'ljxp_userpic', true),
						'cut_text' => get_post_meta($post_id, 'ljxp_cut_text', true),
	);



	// If the post shows up in the forbidden category list and it has been
	// crossposted before (so the forbidden category list must have changed),
	// delete the post. Otherwise, just give up now
	$do_crosspost = 0;

	foreach(wp_get_post_cats(1, $post_id) as $cat) {
		if(in_array($cat, $options['copy_cats'])) {
			$do_crosspost = 1;
			break; // decision made and cannot be altered, fly on
		}
	}

	if(!$do_crosspost) {
		return ljxp_delete($post_id);
	}

	// And create our connection
	$client = new IXR_Client($options['host'], '/interface/xmlrpc');

	// Get the challenge string
	// Using challenge for the most security. Allows pwd hash to be stored
	// instead of pwd
	if (!$client->query('LJ.XMLRPC.getchallenge')) {
		update_option('lj_xp_error_notice', sprintf(__('Could not crosspost; something went wrong. Most likely, %s is down. (%s : %s)', 'lj-xp'), $options['host'], $client->getErrorCode(), $client->getErrorMessage() ) );

	}

	// And retrieve the challenge string
	$response = $client->getResponse();
	$challenge = $response['challenge'];

	$post = & get_post($post_id);

	// Insert the name of the page we're linking back to based on the options set
	if(!$options['custom_name_on']) {
		$blogName = get_option("blogname");
	}
	else {
		$blogName = $options['custom_name'];
	}



	// Tagging and categorizing — for LJ tags
	// Not to be moved down: the else case of custom header is using $cats and $tags

	$cats = array();
	$tags = array();

	$cats = wp_get_post_categories($post_id, array('fields' => 'all')); // wp_get_post_cats is deprecated as of WP2.5
																		// the new function can get names itself, too
	$tags = wp_get_post_tags($post_id, array('fields' => 'all'));


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
			$postHeader .= ' <a href="'.get_permalink($post_id).'">';
			$postHeader .= $blogName;
			$postHeader .= '</a>.';
		}
		// If the post is password protected, put up a special message
		else {
			$postHeader .= __('This post is password protected. You can read it at', 'lj-xp');
			$postHeader .= ' <a href="'.get_permalink($post_id).'">';
			$postHeader .= $blogName;
			$postHeader .= '</a>, ';
			$postHeader .= __('where it was originally posted', 'lj-xp');
			$postHeader .= '.';
		}

		// Depending on whether comments or allowed or not, alter the header
		// appropriately
		if($options['comments']) {
			$postHeader .= sprintf(__(' You can comment here or <a href="%s">there</a>.', 'lj-xp'), get_permalink($post_id).'#comments');
		}
		else {
			$postHeader .= sprintf(__(' Please leave any <a href="%s">comments</a> there.', 'lj-xp'), get_permalink($post_id).'#comments');
		}

		$postHeader .= '</small></p>';
	}
	else {
		$postHeader = $options['custom_header'];


		// pre-post formatting for tags and categories
		$htags = '';
		$hcats = '';

		foreach($tags as $_term_id => $_name) $htags[] = '<a href="'.get_tag_link($_term_id).'" rel="bookmark">'.$_name.'</a>';
		foreach($cats as $_term_id => $_name) $hcats[] = '<a href="'.get_category_link($_term_id).'" rel="bookmark">'.$_name.'</a>';

		$htags = implode(', ', (array)$htags);
		$hcats = implode(', ', (array)$hcats);

		$find = array('[blog_name]', '[blog_link]', '[permalink]', '[comments_link]', '[comments_count]', '[tags]', '[categories]');
		$replace = array($blogName, get_option('home'), get_permalink($post_id), get_permalink($post_id).'#comments', lj_comments($post_id), $htags, $hcats);
		$postHeader = str_replace($find, $replace, $postHeader);
	}

	// $the_event will eventually be passed to the LJ XML-RPC server.
	$the_event = "";

	// and if the post isn't password protected, we need to put together the
	// actual post
	if(!$post->post_password) {
		// and if there's no <!--more--> tag, we can spit it out and go on our
		// merry way
		if(strpos($post->post_content, "<!--more-->") === false) {
			$the_content = $post->post_content;
			$the_content = str_replace('[gallery', '[gallery id="'.$post->ID.'" ', $the_content); // fix gallery shortcodes
			$the_content = apply_filters('the_content', $the_content);
			$the_content = str_replace(']]>', ']]&gt;', $the_content);
			$the_event .= $the_content;
		}
		else {
			$content = explode("<!--more-->", $post->post_content, 2);
			$the_event .= apply_filters('the_content', $content[0]);
			switch($options['more']) {
			case "copy":
				$the_event .= apply_filters('the_content', $content[1]);
				break;
			case "link":
				$the_event .= sprintf('<p><a href="%s#more-%s">', get_permalink($post_id), $post_id) .
					$options['cut_text'] . '</a></p>';
				break;
			case "lj-cut":
				$the_event .= '<lj-cut text="'.$options['cut_text'].'">'.apply_filters('the_content', $content[1]).'</lj-cut>';
				break;
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
	$recent_id = $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_status='publish' AND post_type='post' ORDER BY post_date DESC LIMIT 1");

	// Get a timestamp for retrieving dates later
	$date = strtotime($post->post_date);

	$args = array('username'			=> $options['user'],
					'auth_method'		=> 'challenge',
					'auth_challenge'	=> $challenge,
					'auth_response'		=> md5($challenge . $options['pass']),	// By spec, auth_response is md5(challenge + md5(pass))
					'ver'				=> '1',		// Receive UTF-8 instead of ISO-8859-1
					'event'				=> $the_event,
					'subject'			=> apply_filters('the_title', $post->post_title),
					'year'				=> date('Y', $date),
					'mon'				=> date('n', $date),
					'day'				=> date('j', $date),
					'hour'				=> date('G', $date),
					'min'				=> date('i', $date),
					'props'				=> array('opt_nocomments'	=> !$options['comments'], // allow comments?
												 'opt_preformatted'	=> true, // event text is preformatted
												 'opt_backdated'	=> !($post_id == $recent_id), // prevent updated
																	// post from being show on top
												'taglist'			=> ($options['tag'] != 0 ? $cat_string : ''),
												'picture_keyword'		=> "",
												),
					'usejournal'		=> (!empty($options['community']) ? $options['community'] : $options['user']),
					);

	// Set the userpic, if the user has one selected
	if ($options['userpic'])
	{
		// Set the metadata which assigns a userpic (picture_keyword) to the post
		$args['props']['picture_keyword'] = $options['userpic'];
	}

	// Set the privacy level according to the settings
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

	// Assume this is a new post
	$method = 'LJ.XMLRPC.postevent';

	// But check to see if there's an LJ post associated with our WP post
	if(get_post_meta($post_id, 'ljID', true)) {
		// If there is, add the itemid attribute and change from posting to editing
		$args['itemid'] = get_post_meta($post_id, 'ljID', true);
		$method = 'LJ.XMLRPC.editevent';
	}

	// And awaaaayyy we go!
	if (!$client->query($method, $args)) {
		update_option('lj_xp_error_notice', sprintf(__('Could not crosspost; something went wrong. Most likely, %s is down. (%s : %s)', 'lj-xp'), $options['host'], $client->getErrorCode(), $client->getErrorMessage() ) );

	}

	// If we were making a new post on LJ, we need the itemid for future reference
	if('LJ.XMLRPC.postevent' == $method) {
		$response = $client->getResponse();
		// Store it to the metadata
		add_post_meta($post_id, 'ljID', $response['itemid']);
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
		return $post_id;
	}

	// Get the necessary login info
	$host = get_option('ljxp_host');
	$user = get_option('ljxp_username');
	$pass = get_option('ljxp_password');

	// And open the XMLRPC interface
	$client = new IXR_Client($host, '/interface/xmlrpc');

	// Request the challenge for authentication
	if (!$client->query('LJ.XMLRPC.getchallenge')) {
		update_option('lj_xp_error_notice', sprintf(__('Could not crosspost; something went wrong. Most likely, %s is down. (%s : %s)', 'lj-xp'), $options['host'], $client->getErrorCode(), $client->getErrorMessage() ) );
	}

	// And retrieve the challenge that LJ returns
	$response = $client->getResponse();
	$challenge = $response['challenge'];

	// Most of this is the same as before. The important difference is the
	// value of $args[event]. By setting it to a null value, LJ deletes the
	// entry. Really rather klunky way of doing things, but not my code!
	$args = array(

				'username' => $user,
				'auth_method' => 'challenge',
				'auth_challenge' => $challenge,
				'auth_response' => md5($challenge . $pass),
				'itemid' => $ljxp_post_id,
				'event' => "",
				'subject' => "Delete this entry",
				// I probably don't need to set these, but, hell, I've got it working
				'year' => date('Y'),
				'mon' => date('n'),
				'day' => date('j'),
				'hour' => date('G'),
				'min' => date('i'),

	);


	// And awaaaayyy we go!
	if (!$client->query('LJ.XMLRPC.editevent', $args)) {
		update_option('lj_xp_error_notice', sprintf(__('Could not crosspost; something went wrong. Most likely, %s is down. (%s : %s)', 'lj-xp'), $options['host'], $client->getErrorCode(), $client->getErrorMessage() ) );
	}

	delete_post_meta($post_id, 'ljID');

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

	// See if the post is currently published. If it's been crossposted and its
	// state isn't published, then it should be deleted
	// Also, if it has been crossposted but it's set to not crosspost, then
	// delete it
	if('publish' != $post->post_status || 1 == get_post_meta($post_id, 'no_lj', true)) {
		ljxp_delete($post_id);
	}

	return $post_id;
}

function lj_xp_error_notice() {
	$error = get_option('lj_xp_error_notice');
	if (!empty($error)) { 
    	add_action('admin_notices', 'lj_xp_print_notices');
	}
}

function lj_xp_print_notices() {
	$error = get_option('lj_xp_error_notice');
	echo '<div class="error"><p>'.$error.'<p></div>';
	update_option('lj_xp_error_notice', 0); // turn off the message
}

function ljxp_meta_box() {
	add_meta_box( 'ljxp_meta', __('LiveJournal Crossposting', 'lj-xp'), 'ljxp_sidebar', 'post', 'normal', 'high' );
}

function ljxp_sidebar() {
	global $post, $wp_version;
	$userpics = get_option('ljxp_userpics');
	if (is_array($userpics)) sort($userpics);
?>
	<div class="ljxp-radio-column">
	<h4><?php _e("Crosspost?", 'lj-xp'); ?></h4>
	<ul>
		<?php $ljxp_crosspost = get_post_meta($post->ID, 'no_lj', true); //if (!isset($ljxp_crosspost)) $ljxp_crosspost = 1; ?>
			<li><label class="selectit" for="ljxp_crosspost_go">
				<input type="radio" <?php checked($ljxp_crosspost, 0); ?> value="1" name="ljxp_crosspost" id="ljxp_crosspost_go"/>
				<?php _e('Crosspost', 'lj-xp'); ?>
			</label></li>

			<li><label class="selectit" for="ljxp_crosspost_nogo">
				<input type="radio" <?php checked($ljxp_crosspost, 1); ?> value="0" name="ljxp_crosspost" id="ljxp_crosspost_nogo"/>
				<?php _e('Do not crosspost', 'lj-xp'); ?>
			</label></li>

	</ul>
	</div>
	<div class="ljxp-radio-column">
	<h4><?php _e("Comments", 'lj-xp'); ?></h4>
	<ul>
		<?php 
		$ljxp_comments = get_post_meta($post->ID, 'ljxp_comments', true); 
		//if (empty($ljxp_comments))
		//	$ljxp_comments = get_option('ljxp_comments');
		?>
			<li><label class="selectit" for="ljxp_comments_default">
				<input type="radio" <?php checked($ljxp_comments, 0); ?> value="0" name="ljxp_comments" id="ljxp_comments_default"/>
				<?php _e('Default comments setting', 'lj-xp'); ?>
			</label></li>
			<li><label class="selectit" for="ljxp_comments_on">
				<input type="radio" <?php checked($ljxp_comments, 1); ?> value="1" name="ljxp_comments" id="ljxp_comments_on"/>
				<?php _e('Comments on', 'lj-xp'); ?>
			</label></li>
			<li><label class="selectit" for="ljxp_comments_off">
				<input type="radio" <?php checked($ljxp_comments, 2); ?> value="2" name="ljxp_comments" id="ljxp_comments_off"/>
				<?php _e('Comments off', 'lj-xp'); ?>
			</label></li>

		</ul>
		</div>
		<div class="ljxp-radio-column">
		<h4><?php _e("Privacy", 'lj-xp'); ?></h4>
		<ul>
			<?php 
			$ljxp_privacy = get_post_meta($post->ID, 'ljxp_privacy', true); 
			//if (empty($ljxp_privacy))
			//	$ljxp_privacy = get_option('ljxp_privacy');
			?>
			<li><label class="selectit" for="ljxp_privacy_default">
				<input type="radio" <?php checked($ljxp_privacy, 0); ?> value="0" name="ljxp_privacy" id="ljxp_privacy_default"/>
				<?php _e('Default post privacy setting', 'lj-xp'); ?>
			</label></li>
			<li><label class="selectit" for="ljxp_privacy_public">
				<input type="radio" <?php checked($ljxp_privacy, 'public'); ?> value="public" name="ljxp_privacy" id="ljxp_privacy_public"/>
				<?php _e('Public post', 'lj-xp'); ?>
			</label></li>
			<li><label class="selectit" for="ljxp_privacy_private">
				<input type="radio" <?php checked($ljxp_privacy, 'private'); ?> value="private" name="ljxp_privacy" id="ljxp_privacy_private"/>
				<?php _e('Private post', 'lj-xp'); ?>
			</label></li>
			<li><label class="selectit" for="ljxp_privacy_friends">
				<input type="radio" <?php checked($ljxp_privacy, 'friends'); ?> value="friends" name="ljxp_privacy" id="ljxp_privacy_friends"/>
				<?php _e('Friends only', 'lj-xp'); ?>
			</label></li>
			
			</ul>
		</div>
		
			<?php if ($userpics) : ?>
		<p class="ljxp-userpics">
					<label for="ljxp_userpic"><?php _e('Choose userpic: ', 'lj-xp'); ?></label>
					<select name="ljxp_userpic">
						<option value="-1"><?php _e('Use default', 'lj-xp'); ?></option>
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
		//if (empty($cuttext))
		//	$cuttext = get_option('ljxp_cut_text');
		 ?>
			<label for="ljxp_cut_text">
				<?php _e('Link text for LJ cut tag (if &lt;!--more--&gt; tag is used)', 'lj-xp'); ?>
				<input type="text" value="<?php esc_attr_e($cuttext); ?>" name="ljxp_cut_text" id="ljxp_cut_text" />
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

	// At least one of those hooks is probably unnecessary, but I can't figure
	// out which one
	if(isset($_POST['ljxp_crosspost'])) {
		delete_post_meta($post_id, 'no_lj');
		if(0 == $_POST['ljxp_crosspost']) {
			add_post_meta($post_id, 'no_lj', '1');
		}
	}
	if(isset($_POST['ljxp_comments'])) {
		delete_post_meta($post_id, 'ljxp_comments');
		if($_POST['ljxp_comments'] !== 0) {
			add_post_meta($post_id, 'ljxp_comments', $_POST['ljxp_comments']);
		}
	}

	if(isset($_POST['ljxp_privacy'])) {
			delete_post_meta($post_id, 'ljxp_privacy');
		if($_POST['ljxp_privacy'] !== 0) {
			add_post_meta($post_id, 'ljxp_privacy', $_POST['ljxp_privacy']);
		}
	}

	if(isset($_POST['ljxp_userpic'])) {
		delete_post_meta($post_id, 'ljxp_userpic');
		if($_POST['ljxp_userpic'] !== 0 && $_POST['ljxp_userpic'] !== "Use default") {
			add_post_meta($post_id, 'ljxp_userpic', $_POST['ljxp_userpic']);
		}
	}
	
	if(isset($_POST['ljxp_cut_text'])) {
		delete_post_meta($post_id, 'ljxp_cut_text');
		if(!empty($_POST['ljxp_cut_text'])) {
			add_post_meta($post_id, 'ljxp_cut_text', esc_html($_POST['ljxp_cut_text']));
		}
	}
}

function ljxp_delete_all($repost_ids) {
	foreach((array)$repost_ids as $id) {
		ljxp_delete($id);
	}
}

function ljxp_post_all($repost_ids) {
	foreach((array)$repost_ids as $id) {
		ljxp_post($id);
	}
}

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
	ul#category-children { list-style: none; }
 	ul.children { margin-left: 1.5em; }
	</style>
<?php
}

add_action('admin_menu', 'ljxp_add_pages');
$option = get_option('ljxp_username');
if (!empty($option)) {
	add_action('admin_init', 'ljxp_meta_box');
	add_action('admin_head-post-new.php', 'ljxp_css');
	add_action('admin_head-post.php', 'ljxp_css');
	add_action('publish_post', 'ljxp_post', 50);
	add_action('publish_future_post', 'ljxp_post', 50);
	add_action('edit_post', 'ljxp_edit', 50);
	add_action('delete_post', 'ljxp_delete', 50);
	add_action('publish_post', 'ljxp_save', 1);
	add_action('save_post', 'ljxp_save', 1);
	add_action('edit_post', 'ljxp_save', 1);
	add_action('admin_head-post.php', 'lj_xp_error_notice');
	add_action('admin_head-post-new.php', 'lj_xp_error_notice');
}
?>