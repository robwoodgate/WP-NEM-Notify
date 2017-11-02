=== NEM Payment Notification ===
Contributors: robwoodgate
Tags: nem, blockchain
Requires at least: 4.0
Tested up to: 4.8.3
Stable tag: trunk

Sends an email whenever a new payment is received to the specified NEM account

== Description ==


== Installation ==
1. Upload the entire "nem-notify" folder to your "/wp-content/plugins/" directory.
1. Activate the plugin through the "Plugins" menu in WordPress.
1. Add the NEM address to monitor on the plugin settings page

== Frequently Asked Questions ==
= Does it work for both mainnet and testnet? =
Yes. The plugin accepts both MainNet and TestNet addresses

= I'm not getting notified? =
Check:
1. You have set your NEM address
1. You have a valid email set in WP Settings > General
1. You have port 7890 open on your server firewall
(you'll see connection refused messages in your error_log if this is the problem)

= How often will I get emailed? =
Roughly hourly, but only if there are new payments in your account.
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