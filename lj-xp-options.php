<?php
// set defaults
function ljxp_get_options() {
	$defaults = array(
			'host'				=> 'www.livejournal.com',
			'username'			=> '',
			'password'			=> '',
			'custom_name_on'	=> 0,
			'custom_name'		=> '',
			'crosspost'			=> 1,
			'content'			=> 'full',
			'privacy'			=> 'public',
			'privacy_private'	=> 'no_lj',
			'allowmask'			=> array(),
			'comments'			=> 0,
			'tag'				=> '1',
			'more'				=> 'link',
			'community'			=> '',
			'skip_cats'			=> array(),
			'header_loc'		=> 0,		// 0 means top, 1 means bottom
			'custom_header'		=> '',
			'userpics'			=> array(),
			'cut_text'			=> __('Read the rest of this entry &raquo;', 'lj-xp'),
	);
	
	$options = get_option('ljxp');
	if (!is_array($options)) $options = array();
	
	// clean up options from old versions
	$old_options = get_option('ljxp_username');
	if (!empty($old_options)) {
		$old_option_list = array(	
					'ljxp_host',
					'ljxp_username',
					'ljxp_password',
					'ljxp_crosspost',
					'ljxp_custom_name_on',
					'ljxp_custom_name',
					'ljxp_privacy',
					'ljxp_comments',
					'ljxp_tag',
					'ljxp_more',
					'ljxp_community',
					'ljxp_skip_cats',
					'ljxp_header_loc',
					'ljxp_custom_header',
					'ljxp_delete_private',
					'ljxp_userpics',
					'ljxp_cut_text',
					);
		$old_options = array();
		foreach ($old_option_list as $_opt ) {
			$newkey = str_replace('ljxp_', '', (string)$_opt);
			$old_options[$newkey] = get_option($_opt);
			delete_option($_opt);
		} 
		if (is_array($old_options))
			$options = array_merge( $old_options, $options );
	}
	
	// still need to get the defaults for the new settings, so we'll merge again
	return array_merge( $defaults, $options );
}

// Validation/sanitization. Add errors to $msg[].
function ljxp_validate_options($input) {
	$msg = array();
	$linkmsg = '';
	$msgtype = 'error';
	$options = ljxp_get_options();
	
	// save password no matter what
	if (!isset($input['password']) || empty($input['password']))
		$input['password'] = $options['password']; // preserve!
	else
		$input['password'] = md5($input['password']);
		
	// If we're handling a submission, save the data
	if(isset($input['update_ljxp_options']) || isset($input['crosspost_all']) || isset($input['delete_all'])) {
		
		if (isset($input['delete_all'])) {
			// If we need to delete all, grab a list of all entries that have been crossposted
			$beenposted = get_posts(array('meta_key' => 'ljID', 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => '-1'));
			foreach ($beenposted as $post) {
				$repost_ids[] = $post->ID;
			}
			$msg[] .= __('Settings saved.', 'lj-xp');
			$msg[] .= ljxp_delete_all($repost_ids);
			$msgtype = 'updated';
		}

		$input['skip_cats'] = array_diff(get_all_category_ids(), (array)$input['category']);

		unset($input['category']);

		// trim and stripslash
		if (!empty($input['host']))			$input['host'] = 			trim($input['host']);
		if (!empty($input['username']))		$input['username'] = 		trim($input['username']);
		if (!empty($input['community']))	$input['community'] = 		trim($input['community']);
		if (!empty($input['custom_name']))	$input['custom_name'] = 	trim(stripslashes($input['custom_name']));
		if (!empty($input['custom_header'])) $input['custom_header'] = 	trim(stripslashes($input['custom_header']));

		if(isset($input['crosspost_all'])) {
			$msg[] .= __('Settings saved.', 'lj-xp');
			$msg[] .= ljxp_post_all();
			$msgtype = 'updated';
		}
		
	} // if updated
	unset($input['delete_all']);
	unset($input['crosspost_all']);
	unset($input['update_ljxp_options']);
	
	// If we are clearing the userpics, then get a new list of userpics from the server.
	if (isset($input['clear_userpics'])) {
		$input['userpics'] = array();
		$msg[] .= __('Userpic list cleared.', 'lj-xp');
		$msgtype = 'updated';
		unset($input['clear_userpics']);
	}
	elseif (isset($input['update_userpics'])) {
		$pics = ljxp_update_userpics($input['username']);
		$input['userpics'] = $pics['userpics'];
		$msg[] .= $pics['msg'];
		$msgtype = $pics['msgtype'];
		unset($input['update_userpics']);
	}
	else
		$input['userpics'] = $options['userpics']; // preserve
		
	// If we are updating friends groups, then get a new list of groups from the server.
	if (isset($input['update_groups'])) {
		$groups = ljxp_update_friendsgroups($input['username']);
		$input['friendsgroups'] = $groups['friendsgroups'];
		$msg[] .= $groups['msg'];
		$msgtype = $groups['msgtype'];
		unset($input['update_groups']);
	}
	else
		$input['friendsgroups'] = $options['friendsgroups']; // preserve
		
	// Send custom updated message
	$msg = implode('<br />', $msg);
	
	if (empty($msg)) {
		$msg = __('Settings saved.', 'lj-xp');
		$msgtype = 'updated';
	}
	
	add_settings_error( 'ljxp', 'ljxp', $msg, $msgtype );
	return $input;
}

// ---- Options Page -----

function ljxp_add_pages() {
	$pg = add_options_page("LiveJournal", "LiveJournal", 'manage_options', basename(__FILE__), 'ljxp_display_options');
	add_action("admin_head-$pg", 'ljxp_settings_css');
	// register setting
	add_action( 'admin_init', 'register_ljxp_settings' );
	
	// Help screen 
	$text = '<h3>'.__('How To', 'lj-xp')."</h3>
    <ul>
		<li>" . sprintf(__('<a href="%s">Add a link to the LiveJournal post</a> in your WordPress theme', 'lj-xp' ), 'http://code.google.com/p/ljxp/wiki/LinkingToLJ')."</li>        
		<li>" . sprintf(__('<a href="%s">Add custom fields</a> ([foo]) to the crosspost header or footer', 'lj-xp' ), 'http://code.google.com/p/ljxp/wiki/CustomHeaderFields')."</li>
		<li>" . sprintf(__('<strong>Tip:</strong> If LJ has been down for a while and you just need to crosspost your last few entries, <a href="%s">using Bulk Edit</a> is much faster than the Crosspost All button.', 'lj-xp' ), 'http://code.google.com/p/ljxp/wiki/BulkEditvsCrosspostAll')."</li>
    </ul>";
	$text .= '<h3>' . __( 'More Help', 'lj-xp' ) . '</h3>';

	$text .= '<ul>';
	$text .= '<li><a href="http://code.google.com/p/ljxp/">' . __( 'Plugin Home Page', 'lj-xp' ) . '</a></li>';
	$text .= '<li><a href="http://code.google.com/p/ljxp/issues/list">' . __( 'Bug Tracker', 'lj-xp' ) . '</a> &mdash; report problems here</li>';
	$text .= '</ul>';

	add_contextual_help( $pg, $text );	
}

// Add link to options page from plugin list
add_action('plugin_action_links_' . plugin_basename(__FILE__), 'lj_xp_plugin_actions');
function lj_xp_plugin_actions($links) {
	$new_links = array();
	$new_links[] = '<a href="options-general.php?page=lj_crosspost.php">' . __('Settings', 'lj-xp') . '</a>';
	return array_merge($new_links, $links);
}

// Display the options page
function ljxp_display_options() {
?>
<div class="wrap">
	<form method="post" id="ljxp" action="options.php">
		<?php 
		settings_fields('ljxp');
		get_settings_errors( 'ljxp' );	
		settings_errors( 'ljxp' );
		$options = ljxp_get_options();
		?>
		<h2><?php _e('LiveJournal Crossposter Options', 'lj-xp'); ?></h2>
		<!--	<pre><?php //print_r($options); ?></pre>   -->
		<table class="form-table ui-tabs-panel">
			<tr valign="top">
				<th scope="row"><?php _e('LiveJournal-compliant host:', 'lj-xp') ?></th>
				<td><input name="ljxp[host]" type="text" id="host" value="<?php esc_attr_e($options['host']); ?>" size="40" /><br />
				<span class="description">
				<?php
				_e('If you are using a LiveJournal-compliant site other than LiveJournal (like DeadJournal), enter the domain name here. LiveJournal users can use the default value', 'lj-xp');
				?>
				</span>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('LJ Username', 'lj-xp'); ?></th>
				<td><input name="ljxp[username]" type="text" id="username" value="<?php esc_attr_e($options['username']); ?>" size="40" /></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('LJ Password', 'lj-xp'); ?></th>
				<td><input name="ljxp[password]" type="password" id="password" size="40" /><br />
				<span  class="description"><?php
				_e('Only enter a value if you wish to change the stored password. Leaving this field blank will not erase any passwords already stored.', 'lj-xp');
				?></span>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Community', 'lj-xp'); ?></th>
				<td><input name="ljxp[community]" type="text" id="community" value="<?php esc_attr_e($options['community']); ?>" size="40" /><br />
				<span class="description"><?php
				_e("If you wish your posts to be copied to a community, enter the community name here. Leaving this space blank will copy the posts to the specified user's journal instead", 'lj-xp');
				?></span>
				</td>
			</tr>
		</table>
		<fieldset class="options">
			<legend><h3><?php _e('Crosspost Default', 'lj-xp'); ?></h3></legend>
			<table class="form-table ui-tabs-panel">
				<tr valign="top">
					<th scope="row"><?php _e('If no crosspost setting is specified for an individual post:', 'lj-xp'); ?></th>
					<td>
					<label>
						<input name="ljxp[crosspost]" type="radio" value="1" <?php checked($options['crosspost'], 1); ?>/>
						<?php _e('Crosspost', 'lj-xp'); ?>
					</label>
					<br />
					<label>
						<input name="ljxp[crosspost]" type="radio" value="0" <?php checked($options['crosspost'], 0); ?>/>
						<?php _e('Do not crosspost', 'lj-xp'); ?>
					</label>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e('Content to crosspost:', 'lj-xp'); ?></th>
					<td>
					<label>
						<input name="ljxp[content]" type="radio" value="full" <?php checked($options['content'], 'full'); ?>/>
						<?php _e('Full text', 'lj-xp'); ?>
					</label>
					<br />
					<label>
						<input name="ljxp[content]" type="radio" value="excerpt" <?php checked($options['content'], 'excerpt'); ?>/>
						<?php _e('Excerpt only', 'lj-xp'); ?>
					</label>
				</tr>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('Blog Header', 'lj-xp'); ?></h3></legend>
			<table class="form-table ui-tabs-panel">
				<tr valign="top">
					<th scope="row"><?php _e('Crosspost header/footer location', 'lj-xp'); ?></th>
					<td>
					<label>
						<input name="ljxp[header_loc]" type="radio" value="0" <?php checked($options['header_loc'], 0); ?>/>
						<?php _e('Top of post', 'lj-xp'); ?>
					</label>
					<br />
					<label>
						<input name="ljxp[header_loc]" type="radio" value="1" <?php checked($options['header_loc'], 1); ?> /> 
						<?php _e('Bottom of post', 'lj-xp'); ?>
					</label></td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e('Set blog name for crosspost header/footer', 'lj-xp'); ?></th>
					<td>
						<label>
							<input name="ljxp[custom_name_on]" type="radio" value="0" <?php checked($options['custom_name_on'], 0); ?>
							onclick="javascript: jQuery('#custom_name_row').hide('fast');"/>
							<?php printf(__('Use the title of your blog (%s)', 'lj-xp'), get_option('blogname')); ?>
						</label>
						<br />
						<label>
							<input name="ljxp[custom_name_on]" type="radio" value="1" <?php checked($options['custom_name_on'], 1); ?> 
							onclick="javascript: jQuery('#custom_name_row').show('fast');"/>
							<?php _e('Use a custom title', 'lj-xp'); ?>
						</label>
					</td>
				</tr>
				<tr valign="top" id="custom_name_row" <?php if ($options['custom_name_on']) echo 'style="display: table-row"'; else echo 'style="display: none"'; ?>>
					<th scope="row"><?php _e('Custom blog title', 'lj-xp'); ?></th>
					<td><input name="ljxp[custom_name]" type="text" id="custom_name" value="<?php esc_attr_e($options['custom_name']); ?>" size="40" /><br />
					<span class="description"><?php
					_e('If you chose to use a custom title above, enter the title here. This will be used in the header which links back to this site at the top of each post on the LiveJournal.', 'lj-xp');
					?></span>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e('Custom crosspost header/footer', 'lj-xp'); ?></th>
					<td><textarea name="ljxp[custom_header]" id="custom_header" rows="3" cols="40"><?php echo esc_textarea($options['custom_header']); ?></textarea><br />
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

						<dt>[author]</dt>
						<dd><?php _e('The display name of the post\'s author', 'lj-xp'); ?></dd>
					</dl>
					<span class="description"><?php printf(__('You can also <a href="%s">define your own fields</a>.', 'lj-xp'), 'http://code.google.com/p/ljxp/wiki/CustomHeaderFields'); ?></span>
					</td>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('Post Privacy', 'lj-xp'); ?></h3></legend>
			<table class="form-table ui-tabs-panel">
				<tr valign="top">
					<th scope="row"><?php _e('LiveJournal privacy level for all published WordPress posts', 'lj-xp'); ?></th>
					<td>
						<label>
							<input name="ljxp[privacy]" type="radio" value="public" <?php checked($options['privacy'], 'public'); ?>/>
							<?php _e('Public', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="ljxp[privacy]" type="radio" value="private" <?php checked($options['privacy'], 'private'); ?> />
							<?php _e('Private', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="ljxp[privacy]" type="radio" value="friends" <?php checked($options['privacy'], 'friends'); ?>/>
							<?php _e('All friends', 'lj-xp'); ?>
						</label>
						<br />
						<?php
						if (!empty($options['friendsgroups'])) { ?>
						<label>
							<input name="ljxp[privacy]" type="radio" value="groups" <?php checked($options['privacy'], 'groups'); ?> />
							<?php _e('Friends groups:', 'lj-xp'); ?>
						</label>
							<ul id="friendsgroups">
								<?php foreach ($options['friendsgroups'] as $groupid => $groupname) { ?>
									<li><label>
										<input name="ljxp[allowmask_public][<?php esc_attr_e($groupid) ?>]" type="checkbox" value="<?php esc_attr_e($groupid); ?>" <?php checked($options['allowmask_public'][$groupid], $groupid); ?>/> <?php esc_html_e($groupname); ?>
									</label></li>
								<?php } // foreach ?>
							</ul>
						<?php } // if there are groups 
						else { ?>
							<label>
								<input name="ljxp[privacy]" type="radio" value="groups" disabled="disabled" />
								<?php _e('No friends groups set. Use the button below to update group list.', 'lj-xp'); ?>
							</label>
							<br />
						<?php } ?>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e('LiveJournal privacy level for all private WordPress posts', 'lj-xp'); ?></th>
					<td>
						<label>
							<input name="ljxp[privacy_private]" type="radio" value="public" <?php checked($options['privacy_private'], 'public'); ?>/>
							<?php _e('Public', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="ljxp[privacy_private]" type="radio" value="private" <?php checked($options['privacy_private'], 'private'); ?> />
							<?php _e('Private', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="ljxp[privacy_private]" type="radio" value="friends" <?php checked($options['privacy_private'], 'friends'); ?>/>
							<?php _e('All friends', 'lj-xp'); ?>
						</label>
						<br />
						<?php
						if (!empty($options['friendsgroups'])) { ?>
						<label>
							<input name="ljxp[privacy_private]" type="radio" value="groups" <?php checked($options['privacy_private'], 'groups'); ?> />
							<?php _e('Friends groups:', 'lj-xp'); ?>
						</label>
							<ul id="friendsgroups">
								<?php foreach ($options['friendsgroups'] as $groupid => $groupname) { ?>
									<li><label>
										<input name="ljxp[allowmask_private][<?php esc_attr_e($groupid) ?>]" type="checkbox" value="<?php esc_attr_e($groupid); ?>" <?php checked($options['allowmask_private'][$groupid], $groupid); ?>/> <?php esc_html_e($groupname); ?>
									</label></li>
								<?php } // foreach ?>
							</ul>
						<?php } // if there are groups 
						else { ?>
							<label>
								<input name="ljxp[privacy_private]" type="radio" value="groups" disabled="disabled" />
								<?php _e('No friends groups set. Use the button below to update group list.', 'lj-xp'); ?>
							</label>
							<br />
						<?php } ?>
						<label>
							<input name="ljxp[privacy_private]" type="radio" value="no_lj" <?php checked($options['privacy_private'], 'no_lj'); ?>/>
							<?php _e('Do not crosspost at all', 'lj-xp'); ?>
						</label>
						<br />
					</td>
				</tr>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('LiveJournal Comments', 'lj-xp'); ?></h3></legend>
			<table class="form-table ui-tabs-panel">
				<tr valign="top">
					<th scope="row"><?php _e('Should comments be allowed on LiveJournal?', 'lj-xp'); ?></th>
					<td>
					<label>
						<input name="ljxp[comments]" type="radio" value="0" <?php checked($options['comments'], 0); ?>/>
						<?php _e('Require users to comment on WordPress', 'lj-xp'); ?>
					</label>
					<br />
					<label>
						<input name="ljxp[comments]" type="radio" value="1" <?php checked($options['comments'], 1); ?>/>
						<?php _e('Allow comments on LiveJournal', 'lj-xp'); ?>
					</label>
					<br />
				</tr>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('LiveJournal Tags', 'lj-xp'); ?></h3></legend>
			<table class="form-table ui-tabs-panel">
				<tr valign="top">
					<th scope="row"><?php _e('Tag entries on LiveJournal?', 'lj-xp'); ?></th>
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
							<input name="ljxp[tag]" type="radio" value="1" <?php checked($options['tag'], 1); ?>/>
							<?php _e('Tag LiveJournal entries with WordPress categories only', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="ljxp[tag]" type="radio" value="3" <?php checked($options['tag'], 3); ?>/>
							<?php _e('Tag LiveJournal entries with WordPress categories and tags', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="ljxp[tag]" type="radio" value="2" <?php checked($options['tag'], 2); ?>/>
							<?php _e('Tag LiveJournal entries with WordPress tags only', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="ljxp[tag]" type="radio" value="0" <?php checked($options['tag'], 0); ?>/>
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
			<table class="form-table ui-tabs-panel">
				<tr valign="top">
					<th scope="row"><?php _e('How should LJXP handle More tags?', 'lj-xp'); ?></th>
					<td>
						<label>
							<input name="ljxp[more]" type="radio" value="link" <?php checked($options['more'], 'link'); ?>/>
							<?php _e('Link back to WordPress', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="ljxp[more]" type="radio" value="lj-cut" <?php checked($options['more'], 'lj-cut'); ?>/>
							<?php _e('Use an lj-cut', 'lj-xp'); ?>
						</label>
						<br />
						<label>
							<input name="ljxp[more]" type="radio" value="copy" <?php checked($options['more'], 'copy'); ?>/>
							<?php _e('Copy the entire entry to LiveJournal', 'lj-xp'); ?>
						</label>
						<br />
					</td>
				</tr>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('Category Selection', 'lj-xp'); ?></h3></legend>
			<table class="form-table ui-tabs-panel">
				<tr valign="top">
					<th scope="row"><?php _e('Select which categories should be crossposted', 'lj-xp'); ?></th>
					<td>
						<ul id="category-children">
							<li><label class="selectit"><input type="checkbox" class="checkall"> 
								<em><?php _e("Check all", 'lj-xp'); ?></em></label></li>
							<?php
							if (!is_array($options['skip_cats'])) $options['skip_cats'] = (array)$options['skip_cats'];
							$selected = array_diff(get_all_category_ids(), $options['skip_cats']);
							wp_category_checklist(0, 0, $selected, false, $walker = new LJXP_Walker_Category_Checklist, false);
							?>
						</ul>
					<span class="description">
					<?php _e('Any post that has <em>at least one</em> of the above categories selected will be crossposted.'); ?>
					</span>
					</td>
				</tr>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('Userpics', 'lj-xp'); ?></h3></legend>
			<table class="form-table ui-tabs-panel">
				<tr valign="top">
					<th scope="row"><?php _e('The following userpics are currently available', 'lj-xp'); ?></th>
					<td>
					<?php
						$userpics = $options['userpics'];
						if (empty($userpics))
							_e('<p>No userpics have been downloaded, only the default will be available.</p>');
						else
							echo implode(', ', $userpics);
					?>
					<br/>
					<br/>
					<input type="submit" name="ljxp[update_userpics]" value="<?php esc_attr_e('Update Userpics', 'lj-xp'); ?>" class="button-secondary" />

					<?php if (count($options['userpics'])) { ?>
						<input type="submit" name="ljxp[clear_userpics]" value="<?php printf(esc_attr('Clear %d Userpics', 'lj-xp'), count($options['userpics'])); ?>" class="button-secondary" />
					<?php } ?>
					</td>
				</tr>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('Custom Friends Groups', 'lj-xp'); ?></h3></legend>
			<table class="form-table ui-tabs-panel">
				<tr valign="top">
					<th scope="row"><?php _e('The following groups are currently available', 'lj-xp'); ?></th>
					<td>
					<?php
						if (empty($options['friendsgroups']))
							_e('<p>No friends groups have been set.</p>');
						else
							echo implode(', ', $options['friendsgroups']);
					?>
					<br/>
					<br/>
					<input type="submit" name="ljxp[update_groups]" value="<?php esc_attr_e('Update Friends Groups', 'lj-xp'); ?>" class="button-secondary" />
					</td>
				</tr>
			</table>
		</fieldset>
		<fieldset class="options">
			<legend><h3><?php _e('Crosspost or delete all entries', 'lj-xp'); ?></h3></legend>
			<table class="form-table ui-tabs-panel">
				<tr valign="top">
					<th scope="row"> </th>
					<td>
					<?php printf(__('If you have changed your username or community, you might want to crosspost all your entries, or delete all the old ones from your journal. These buttons are hidden so you don\'t press them by accident. <a href="%s" %s>Show the buttons.</a>', 'lj-xp'), '#scary-buttons', 'onclick="javascript: jQuery(\'#scary-buttons\').show(\'fast\');"'); ?>
					</td>
				</tr>
				<tr valign="top" id="scary-buttons">
					<th scope="row"> </th>
					<td>
					<input type="submit" name="ljxp[crosspost_all]" id="crosspost_all" value="<?php esc_attr_e('Update options and crosspost all WordPress entries', 'lj-xp'); ?>" class="button-secondary" />
					<input type="submit" name="ljxp[delete_all]" id="delete_all" value="<?php esc_attr_e('Update options and delete all journal entries', 'lj-xp'); ?>" class="button-secondary" />
					</td>
				</tr>
			</table>
		</fieldset>
		<p class="submit">
			<input type="submit" name="ljxp[update_ljxp_options]" value="<?php esc_attr_e('Update Options'); ?>" class="button-primary" />
		</p>
	</form>
	<script type="text/javascript">
	jQuery(document).ready(function($){
		$(function () { // this line makes sure this code runs on page load
			$('.checkall').click(function () {
				$(this).parents('fieldset:eq(0)').find(':checkbox').attr('checked', this.checked);
			});
		});
	});
	</script>
</div>
<?php
}

function ljxp_update_friendsgroups($username) {
	$msgtype = 'error';
	
	if (empty($username)) {
		// Report what we did to the user
		$msg[] .= __('Cannot get friends groups unless username is set.', 'lj-xp');
		return $msg;
	}
	
	$options = get_option('ljxp');
	
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
	
	$args = array(	'username'			=> $options['username'],
					'auth_method'		=> 'challenge',
					'auth_challenge'	=> $challenge,
					'auth_response'		=> md5($challenge . $options['password']),	// By spec, auth_response is md5(challenge + md5(pass))
					'ver'				=> '1',										// Receive UTF-8 instead of ISO-8859-1
					);
	
	$method = 'LJ.XMLRPC.getfriendgroups';
	
	// And awaaaayyy we go!
	if (!$client->query($method, $args)) {
		$errors[$client->getErrorCode()] = $client->getErrorMessage();
	}

	$response = $client->getResponse();
	$groups = array();
	foreach ($response['friendgroups'] as $groupinfo) {
		$groupid = $groupinfo['id'];
		$groups[$groupid] = $groupinfo['name'];
	}
	$options['friendsgroups'] = $groups; // overwrite old values
	update_option('ljxp', $options);
	
	// Report our success
	$msg[] .= sprintf(__('Found %d friendsgroups.', 'lj-xp'), count($groups));
	$msgtype = 'updated';
	$msg = implode('<br />', $msg);
	
	return array('friendsgroups' => $groups, 'msg' => $msg, 'msgtype' => $msgtype);
}

function ljxp_update_userpics($username) {
	$msgtype = 'error';
	
	// We keep a flag if we should keep processing since there are multiple steps here
	$keep_going = 1;

	// Userpics can be found from the user's domain, in an atom feed
	if (empty($username)) {
		// Report what we did to the user
		$msg[] .= __('Cannot update userpic list unless username is set.', 'lj-xp');
		$keep_going = 0;
	}

	// Download the Atom feed from the server.
	if ($keep_going) {
		$atom_data = wp_remote_get('http://' . $username . '.livejournal.com/data/userpics');

		if( is_wp_error( $atom_data ) ) {
		    $msg[] .= __('Cannot download Atom feed of userpics.', 'lj-xp');
			$keep_going = 0;
		}
		/* // DEBUG
		else {
		   $msg[] .= 'Response:<pre>';
		   $msg[] .= print_r( $atom_data, true );
		   $msg[] .= '</pre>';
		}
		/**/
	}

	// Parse the Atom feed and pull out the keywords
	if ($keep_going) {
		$new_userpics = array();

		try {
			// Parse the data as an XML string. The atom feed has many fields, but the category/@term
			// contains the name that is placed in the post metadata
			// and content/@src contains the URL
			$atom_doc = new SimpleXmlElement($atom_data['body'], LIBXML_NOCDATA);

			foreach($atom_doc->entry as $entry) {
				$attributes = $entry->category->attributes();
				$term = $attributes['term'];
//				$content = $entry->content->attributes();
//				$src = $content['src'];
				$new_userpics[] = html_entity_decode($term);
			}
		} catch (Exception $e) {
			$msg[] .= __('Cannot parse Atom data from LiveJournal.', 'lj-xp');
			$keep_going = 0;
		}
	}

	// Finally, we have new userpics, so we save it in our array
	if ($keep_going) {
		// Sort these so they come in a consistent format.
		sort($new_userpics);

		// Report our success
		$msg[] .= sprintf(__('Found %d userpics on LiveJournal.', 'lj-xp'), count($new_userpics));
		$msgtype = 'updated';
	}
	$msg = implode('<br />', $msg);
	return array('userpics' => $new_userpics, 'msg' => $msg, 'msgtype' => $msgtype);
}

// pre-3.1 compatibility
if (!function_exists('esc_textarea')) {
	function esc_textarea( $text ) {
	     $safe_text = htmlspecialchars( $text, ENT_QUOTES );
	     return apply_filters( 'esc_textarea', $safe_text, $text );
	}
}


// custom walker so we can change the name attribute of the category checkboxes (until #16437 is fixed)
// mostly a duplicate of Walker_Category_Checklist
class LJXP_Walker_Category_Checklist extends Walker {
     var $tree_type = 'category';
     var $db_fields = array ('parent' => 'parent', 'id' => 'term_id'); 

 	function start_lvl(&$output, $depth, $args) {
         $indent = str_repeat("\t", $depth);
         $output .= "$indent<ul class='children'>\n";
     }
 
 	function end_lvl(&$output, $depth, $args) {
         $indent = str_repeat("\t", $depth);
         $output .= "$indent</ul>\n";
     }
 
 	function start_el(&$output, $category, $depth, $args) {
         extract($args);
         if ( empty($taxonomy) )
             $taxonomy = 'category';
 
		// This is the part we changed
         $name = 'ljxp['.$taxonomy.']';
 
         $class = in_array( $category->term_id, $popular_cats ) ? ' class="popular-category"' : '';
         $output .= "\n<li id='{$taxonomy}-{$category->term_id}'$class>" . '<label class="selectit"><input value="' . $category->term_id . '" type="checkbox" name="'.$name.'[]" id="in-'.$taxonomy.'-' . $category->term_id . '"' . checked( in_array( $category->term_id, $selected_cats ), true, false ) . disabled( empty( $args['disabled'] ), false, false ) . ' /> ' . esc_html( apply_filters('the_category', $category->name )) . '</label>';
     }
 
 	function end_el(&$output, $category, $depth, $args) {
         $output .= "</li>\n";
     }
}
?>