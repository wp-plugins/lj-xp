=== Plugin Name ===
Contributors: sillybean, CorneliousJD, freeatnet, Evan Broder
Donate link: http://stephanieleary.com/code/wordpress/lj-xp/
Text Domain: lj-xp
Domain Path: /languages
Tags: livejournal, lj, crosspost
Requires at least: 2.8
Tested up to: 3.6
Stable tag: 2.3.3

Automatically crossposts your WP entries to your LiveJournal or LJ-based clone.

== Description ==

LJ-XP automatically crossposts blog entries to your LiveJournal (or LiveJournal-based clone) account.

= Features =

* Crosspost entries to a LiveJournal account or community.
* Customize the crosspost header or footer notice using built-in shortcodes, or <a href="http://code.google.com/p/ljxp/wiki/CustomHeaderFields">create your own</a>.
* Force comments to be on one site or the other, or allow them on both.
* Edit privacy settings for the LiveJournal posts. Choose whether to crosspost private WordPress posts to LJ as private, for friends (including custom friends groups), or not at all.
* Assign tags based on WordPress categories and/or tags.
* Assign `<!--more-->` tag settings (use LJ-cut or just link back to the WordPress post).
* Crosspost only certain categories.
* Crosspost excerpts or full text.
* Choose LJ userpics for each post.
* Add the link to the LJ post to your WordPress post or theme.
* Relative links in the WordPress post are converted to full URLs in the crosspost.
* WordPress galleries, which rely on theme CSS for layout, are crossposted with inline styles.
* Option to <em>not</em> crosspost by default.

== Installation ==

1. Upload the `lj-xp` directory to your `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to Settings &rarr; LiveJournal and configure your settings.

== FAQ ==

See the <a href="http://code.google.com/p/ljxp/wiki/FAQ">FAQ on the Google Code wiki</a>.

== Changelog ==
= 2.3.3 =
* Fixed notices

= 2.3.2 =
* Fixed yet another bug in the per-post comments settings. (<a href="http://code.google.com/p/ljxp/issues/detail?id=149">#149</a>)
* Fixed the relative link function to correctly ignore https URLs. (<a href="http://code.google.com/p/ljxp/issues/detail?id=148">#148</a>)

= 2.3.1 =
* Turned off the debug mode that was accidentally left on in 2.3. My apologies!

= 2.3 =
* Added support for custom friends groups. (<a href="http://code.google.com/p/ljxp/issues/detail?id=37">#37</a>)
* Fixed a problem where entries that were edited after being crossposted had the backdate option set, making them disappear from friends lists. This option is now set only when crossposting all entries in bulk. (<a href="http://code.google.com/p/ljxp/issues/detail?id=146">#146</a>)
* Fixed another bug in the per-post comments settings. (<a href="http://code.google.com/p/ljxp/issues/detail?id=149">#149</a>)

= 2.2.2 =
* Fixed the userpic and comment settings on individual posts. (<a href="http://code.google.com/p/ljxp/issues/detail?id=144">#144</a>)
* Added some helpers to work around servers that do not support PHP's multibyte string functions.

= 2.2.1 =
* Fixed the new relative link function so it leaves full URLs alone. (<a href="http://code.google.com/p/ljxp/issues/detail?id=141">#141</a>)
* Now using WP's HTTP API rather than curl_*(), which means the userpic retrieval functions should work on more servers.

= 2.2 =
* New option: default LJ privacy levels for private WP posts. (<a href="http://code.google.com/p/ljxp/issues/detail?id=73">#73</a>)
* Added support for custom fields in the header/footer. <a href="http://code.google.com/p/ljxp/wiki/CustomHeaderFields">See the wiki for documentation.</a> (<a href="http://code.google.com/p/ljxp/issues/detail?id=113">#113</a>)
* Now auto-generates excerpts from the content, if crossposting excerpts and the post doesn't have an excerpt specified (<a href="http://code.google.com/p/ljxp/issues/detail?id=139">#139</a>)
* Added a filter, `ljxp_pre_process_excerpt`, applied to the excerpt before it's crossposted. Developers should use this in addition to `ljxp_pre_process_post` to support both excerpt and full-text options.
* Relative links are now converted to full URLs before the content is crossposted (<a href="http://code.google.com/p/ljxp/issues/detail?id=134">#134</a>)
* The LJ URL of the post is now stored in a custom field, so you can easily <a href="http://code.google.com/p/ljxp/wiki/LinkingToLJ">add the link to your WP entry</a>. (<a href="http://code.google.com/p/ljxp/issues/detail?id=51">#51</a>)
* Galleries are now crossposted with inline styles, so their grid layout is maintained (<a href="http://code.google.com/p/ljxp/issues/detail?id=117">#117</a>)
* When posting to a community, deleted WP entries are now deleted from the community correctly.
* New Help screen on the options page.
* Updated POT for translators.

= 2.1.2 = 
* Fixed category handling and a warning about arrays on line 89 that could also lead to "headers already sent" message on some servers.
* Translations: generated new POT from wordpress.org; updated old .po/.mo files to match the new text domain.

= 2.1.1 =
* Fix for `<!--more-->` tags containing text (<a href="http://code.google.com/p/ljxp/issues/detail?id=76">#76</a>)
* Added a filter, `ljxp_pre_process_post`, applied to the post content before it's crossposted (<a href="http://code.google.com/p/ljxp/issues/detail?id=120">#120</a>)
* Added option to not crosspost by default (<a href="http://code.google.com/p/ljxp/issues/detail?id=67">#67</a>)
* Added option to crosspost the excerpt instead of the full text (<a href="http://code.google.com/p/ljxp/issues/detail?id=111">#111</a>)
* Added `[author]` tag for header/footer (<a href="http://code.google.com/p/ljxp/issues/detail?id=34">#34</a>)
* Settings API! Much better security.
* General settings cleanup. Now using two settings instead of thirteen, and removing settings on plugin uninstall.
* More improvements to the error handling.

= 2.1 =
* send error back to the post edit screen when LJ is down (transport/socket errors) rather than using `wp_die()`, which stops all other plugins from working
* support userpics (<a href="http://code.google.com/p/ljxp/issues/detail?id=74">#74</a>)
* support cut text
* switch to new meta box format so you can collapse the LJ box or move it around the post edit screen
* fix a problem with gallery image IDs that would cause the wrong images to be shown when the `[gallery]` shortcode was crossposted
* options page cleanup
* get rid of `has_cap` deprecated argument notice
* less obnoxious default styling for the crosspost header/footer
* A-Bishop's <a href="http://wordpress.org/extend/plugins/livejournal-comments/">LiveJournal Comments</a> is now bundled with this plugin, eliminating the need for extra setup. This version has been edited to use a more reliable configuration, and this copy will be updated alongside LJ-XP.

== Upgrade Notice ==
= 2.1 =
* This version sends an error back to the post edit screen when LJ is down rather than stopping WordPress entirely. Added support for userpics, cut text, [gallery] tags with the right images, and a proper box for the options on the Edit screen.

= 2.1.1 = 
* 2.1 sends an error back to the screen when LJ is down rather than stopping WordPress entirely. Supports userpics, cut text; fixed [gallery] images and meta boxes. New in 2.1.1: turn off crossposting by default; crosspost excerpt or full text; [author] tag; `<!--more-->` tags with text.

= 2.1.2 =
* 2.1 shows an error when LJ is down rather than stopping WordPress. Supports userpics, cut text; fixed [gallery] images and meta boxes. New in 2.1.1: turn off crossposting by default; crosspost excerpt or full text; [author] tag; `<!--more-->` tags with text. 2.1.2: fix line 89 error.

= 2.2 =
* New: default LJ privacy levels for private WP posts; custom header/footer fields (see wiki); link to LJ post (see wiki); excerpts auto-generated; relative WP links crossposted as complete URLs; inline gallery styling to maintain grid layout; fix for deleting community posts; help screen.

= 2.2.1 =
* Fixed 2.2's relative link function so it leaves full URLs alone. The userpic retrieval functions should now work on more servers.

= 2.2.2 =
* Fixed the userpic and comment settings in the per-post options. Added some helpers to work around servers that do not support PHP's multibyte string functions.

= 2.3 =
* Support for custom friends groups! Fixed the backdate problem on entries that were edited after being crossposted; these now appear in friends lists as usual.

= 2.3.1 =
* Turned off the debug mode that was accidentally left on in 2.3. My apologies!

= 2.3.2 =
* Bug fixes for https URLs and comments settings.