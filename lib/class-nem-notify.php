<?php
// No direct access
if (!defined('NEM_PAYMENT_NOTIFY_PATH')) {
    header('HTTP/1.0 403 Forbidden');
    wp_die(__('Nothing to see here... :)'));
}

class Nem_Notify {

    /**
     * Construct the NEM notify object
     */
    public function __construct()
    {
        // Schedule plugin cron task
        add_action('nempn_cron_events', array($this, 'cron_run'));

        // Add plugin settings
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
    }

    /**
     * Runs on plugin activation (register_activation_hook)
     */
    public static function activate()
    {
        // Schedule plugin cron task
        if (!wp_next_scheduled('nempn_cron_event')) {
            wp_schedule_event(time(), 'hourly', 'nempn_cron_event');
        }
    }

    /**
     * Runs on plugin deactivation (register_deactivation_hook)
     */
    public static function deactivate()
    {
        // Unschedule plugin cron task
        $timestamp = wp_next_scheduled('nempn_cron_event');
        wp_unschedule_event($timestamp, 'nempn_cron_event');
    }

    /**
     * WP_CRON: Sends email if new payments found
     */
    public function cron_run()
    {
        // Get options
        $options = get_option('nem_notify_options');
        $txn_id  = $options['last_txn_id'];
        $address = $options['nem_address'];

        // Lookup transactions
        $net     = ('N' == $address[0]) ? 'mainnet' : 'testnet';
        $nem_api = new Nem_Api($net);
        $txns    = $nem_api->get_transactions_since($address, $txn_id);
        if (empty($txns)) {
            return;
        }

        // Prepare email
        $subject = sprintf(__('New NEM Transactions for: %s'), $address);
        $body    = __('The following new transactions have been received:') . "\n\n";
        foreach ($txns as $txn) {
            $body .= __('Date: ') . date("Y-m-d H:i:s", $nem_api->get_transaction_time($txn)) . ' ';
            $body .= __('Amount: ') . $nem_api->get_transaction_amount($txn) . "\n";
        }

        // Send email and update last txn id
        if (wp_mail(get_option('admin_email'), $subject, $body)) {
            $options['last_txn_id'] = $nem_api->get_transaction_id(reset($txns));
            update_option('nem_notify_options', $options);
            // error_log($body);
        }

    }

    /**
     * Registers plugin settings fields
     */
    function admin_init()
    {
        add_settings_section('main_section', '', null, 'nem_notify');
        register_setting('nem_notify_options', 'nem_notify_options',
            array($this, 'validate_settings')
        );
        add_settings_field('nem_address', 'NEM Address',
            array($this, 'nem_address_field'), 'nem_notify', 'main_section'
        );
    }

    /**
     * Adds settings page to admin menu
     */
    public function admin_menu()
    {
        add_options_page(
            'NEM Payment Notification', 'NEM Notify', 'manage_options',
            'nem_notify', array($this, 'display_settings_page')
        );
    }

    /**
     * Displays our settings page
     */
    public function display_settings_page()
    {
        ?>
        <div>
        <h2><?php _e('NEM Payment Notification Settings'); ?></h2>
        <?php _e("This plugin allows you to monitor a NEM address for new payments and
                send an email notification if any are detected. It's checked hourly."); ?>
        <form action="options.php" method="post">
        <?php settings_fields('nem_notify_options'); ?>
        <?php do_settings_sections('nem_notify'); ?>

        <input name="Submit" type="submit" value="<?php esc_attr_e('Save Changes'); ?>" />
        </form>
        </div>
        <?php
    }

    /**
     * Displays the NEM Address settings field
     */
    public function nem_address_field()
    {
        $options = get_option('nem_notify_options');
        echo "<input id='nem_address' name='nem_notify_options[nem_address]' size='40' type='text' value='{$options['nem_address']}' />";
    }

    /**
     * Validates settings fields
     */
    public function validate_settings($input)
    {
        $options = get_option('nem_notify_options');
        $options['nem_address'] = trim($input['nem_address']);
        $options['last_txn_id'] = false; // reset
        return $options;
    }
}