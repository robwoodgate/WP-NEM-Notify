=== NEM Payment Notification ===
Contributors: robwoodgate
Tags: nem, blockchain
Requires at least: 4.0
Tested up to: 4.8.1
Stable tag: trunk

Sends an email whenever a new payment is received to the specified NEM account

== Description ==


== Installation ==
1. Upload the entire "nem-payment-notification" folder to your "/wp-content/plugins/" directory.
1. Activate the plugin through the "Plugins" menu in WordPress.
1. Add the NEM address to monitor on the plugin settings page

== Frequently Asked Questions ==
= Does it work for mainnet and testnet? =
Yes. The plugin accepts both MainNet and TestNet addresses

= How often will it email? =
Roughly hourly, if there are new payments in your account.
The plugin uses WordPress cron, so if you don't get at least one website visitor
per hour, then there may be a delay in notifications. If you have a very low traffic
website, you might like to hook your WordPress cron into your system task scheduler:
https://developer.wordpress.org/plugins/cron/hooking-into-the-system-task-scheduler/

== Changelog ==
= 1.1 =
* Public release

= 1.0 =
* Initial release.

== Upgrade Notice ==
= 1.1 =
* First public release