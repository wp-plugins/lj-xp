=== Plugin Name ===
Contributors: CorneliousJD, FreeAtNet, EBroder, Sillybean
Donate link: http://code.google.com/p/ljxp/
Tags: livejournal, lj, crosspost
Requires at least: 2.8
Tested up to: 3.2.1
Stable tag: 2.1

Automatically crossposts your WP entries to your LiveJournal or LJ based clone.

== Description ==

LJ-XP can automatically crosspost 
a blog entry to your LiveJournal (or LiveJournal-based clone) account.
It can crosspost to communities, and even has a customizeable
header and footer, and allows you to direct would-be LJ comments
to your WP blog instead!

= Features =

* Crosspost entries to a LiveJournal account.
* Crosspost entries to a LiveJournal community.
* Add a header/footer to force comments on your site.
* Fully customizable header and/or footer.
* Edit privacy settings for the LiveJournal posts.
* Assign tags based on WordPress categories.
* Assign `more` tag settings, like LJ-Cuts, or link-backs.
* Ability to only crosspost certain categories.

== Installation ==

1. Upload the `lj-xp` directory to your `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to Settings &rarr; LiveJournal and configure your settings.

== Changelog ==
= 2.1 =
* send error back to the post edit screen when LJ is down rather than using wp_die(), which stops all other plugins from working
* support userpics
* support cut text
* switch to new meta box format so you can collapse the LJ box or move it around the post edit screen
* fix a problem with gallery image IDs that would cause the wrong images to be shown when the [gallery] shortcode was crossposted
* options page cleanup
* get rid of has_cap deprecated argument notice
* less obnoxious default styling for the crosspost header/footer

== Upgrade Notice ==
= 2.1 =
* This version sends error back to the post edit screen when LJ is down rather than stopping WordPress entirely. Added support for userpics, cut text, [gallery] tags with the right images, and a proper box for the options on the Edit screen.