=== Campaign Monitor Synchronization ===
Contributors: carloroosen, pilotessa
Donate link:
Tags: Campaign Monitor, user management, mailing list
Requires at least: 3.0.1
Tested up to: 3.6.1
Stable tag: 1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Use the user list in your Wordpress installation as your mailing list for Campaign Monitor. 

== Description ==

If you have a registration process for users on your website you don't want an extra form just to let the same people subscribe for your mailing list. So you will find yourself copy-pasting users from your Wordpress website to your Campaign Monitor list. 

The Campaign Monitor Synchronization plugin does this for you. The plugin sends a copy of the WordPress user list to Campaign Monitor, and keeps that external copy in sync.

= Technical details =

The Campaign Monitor Synchronization plugin checks every 15 minutes whether there has been changes in the user list on Wordpress, without contacting Campaign Monitor.

Only if there has been a change, it compares the WordPress user table with the version on Campaign Monitor. This can also be forced by pressing "save and sync" on the plugin options page.

= Minimize bandwidth =

When there are differences only the modifications will be sent to Campaign Monitor in a single batch using its API. So by all means the plugin tries to minimise the number of external requests, while maintaining a reasonable level of synchronization.

When a user unsubscribes on the Campaign Monitor website, this will not be overwritten by the plugin, nor will this be stored back in the Wordpress database.

= Links =

* [Author's website](http://carloroosen.com/)
* [Plugin page](http://carloroosen.com/campaign-monitor-synchronization/)

== Installation ==

1. Register on http://campaignmonitor.com and create a list
1. On your wordpress website, upload `campaign-monitor-synchronization.zip` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. In the plugin options, enter the API key and list ID that can be found on your Campaign Monitor pages.
1. Select which fields you want to copy to Campaign Monitor. E-mail address will always be copied.

== Screenshots ==

1. Option page

== Changelog ==

= 1.0 =
* First commit

