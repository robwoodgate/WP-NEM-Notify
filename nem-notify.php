<?php
/*
Plugin Name: NEM Notify
Plugin URI: https://www.cogmentis.com
Description: Emails when a payment is received to a NEM address or harvesting stops
Author: Rob Woodgate
Version: 1.2
Author URI: https://www.cogmentis.com
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

//* No direct access
if ( !function_exists('add_action') ) {
    wp_die(__('Nothing to see here.... :)'));
}

//* Define Plugin Constants
define('NEM_PAYMENT_NOTIFY_PATH', plugin_dir_path(__FILE__));
define('NEM_PAYMENT_NOTIFY_URL',  plugin_dir_url(__FILE__));
define('NEM_PAYMENT_NOTIFY_FILE', __FILE__);

//* Register autoloader for our custom classes
spl_autoload_register(function ($class_name)
{
    $filename = str_replace('_', '-', strtolower($class_name));
    $filename = 'class-' . $filename . '.php';
    $file = NEM_PAYMENT_NOTIFY_PATH . '/lib/'. $filename;
    if (file_exists($file)) {
        require($file);
    }
});

//* Initialize and Instantiate Plugin
register_activation_hook(  __FILE__, array('Nem_Notify', 'activate'));
register_deactivation_hook(__FILE__, array('Nem_Notify', 'deactivate'));
$nem_notify = new Nem_Notify();
