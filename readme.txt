=== Campaign Monitor Synchronization ===
Contributors: carloroosen, pilotessa
Tags: Campaign Monitor, user management, mailing list
Requires at least: 3.0.1
Tested up to: 3.9
Stable tag: 1.0.9
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Use the user list in your Wordpress installation as your mailing list for Campaign Monitor. 

== Description ==
This plugin automatically creates and maintains a mailinglist on Campaign Monitor mirroring the list of WordPress users. Typically this plugin is useful when you have information (or functionality) on your website that is accessible for registered users only, and you want to send email updates about that information (or functionality) to those users alone. 

= Example use case =
For instance, members can subscribe for events on your Wordpress website, and you send out announcements to those members using CampaignMonitor. With this plugin you can maintain your list on WordPress, manage their permissions, and the list on Campaign Monitor will always be an exact copy.

= Warning =
This plugin performs a one-way synchronization from WordPress to Campaign Monitor. For instance, it will remove users from your Campaign Monitor list if they do not exist as users in WordPress. If this behavior is too strict for you, we recommend our other plugin [Campaign Monitor Dual Registration ](http://wordpress.org/plugins/campaign-monitor-dual-registration/).

* Don't use this plugin in combination with a subscription form that stores subscribers directly in the same CampaignMonitor list. 
* Also don't modify the list in CampaignMonitor directly, thos e changes will be lost. The only exception is when people unsubscribe from the mailinglist, this will be stored in Campaign Monitor only, and can only be changed there.

= Technical details =

The Campaign Monitor Synchronization plugin checks every 15 minutes whether there has been changes in the user list on Wordpress, without contacting Campaign Monitor.

Only if there has been a change, it compares the WordPress user table with the version on Campaign Monitor. This can also be forced by pressing "save and sync" on the plugin options page.

When there are differences only the modifications will be sent to Campaign Monitor in batches using its API. This way the plugin tries to minimise the number of external requests, while maintaining a reasonable level of synchronization.

When a user unsubscribes on the Campaign Monitor website, this will not be overwritten by the plugin, nor will this be stored back in the Wordpress database.

= Links =

* [Author's website](http://carloroosen.com/)
* [Plugin page](http://carloroosen.com/campaign-monitor-synchronization/)

== Installation ==

1. Register on http://campaignmonitor.com and create a list. Don't use an existing list, the data will be lost !
1. In the list details click the link "change name/type", there you will find the list ID, it is a 32 character hexadecimal string. Don't use the list ID in the url!.
1. Go to your account settings. There you will find the API key, it is also a 32 character hexadecimal string.
1. On your wordpress website, upload `campaign-monitor-synchronization.zip` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. In the plugin options, enter the list ID and API key.
1. Select which fields you want to copy to Campaign Monitor. E-mail address will always be copied.

== Screenshots ==

1. Option page

== Changelog ==

= 1.0 =
* First commit
= 1.0.1 =
* Handle subscriber lists with size >1000
= 1.0.2 =
* Solve a conflict with other plugins using the CampaingMonitor API.
= 1.0.3 =
* Several fixes.
= 1.0.4 =
* Solve more conflicts with other plugins using the CampaingMonitor API.
= 1.0.5 =
* Send multiple batches when batch size >1000
= 1.0.6 =
* Fix some notices.
= 1.0.7 =
* Fix subscribers import bug.
= 1.0.8 =
* No fixes, just SVN troubles.
= 1.0.9 =
* More detailed error output.
