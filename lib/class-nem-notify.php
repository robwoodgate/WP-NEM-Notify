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
        // Schedule plugin cron tasks
        add_action('nem_notify_cron_event', array($this, 'cron_payments_received'));
        add_action('nem_notify_cron_event', array($this, 'cron_harvesting_check'));

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
        if (!wp_next_scheduled('nem_notify_cron_event')) {
            wp_schedule_event(time(), 'hourly', 'nem_notify_cron_event');
        }
    }

    /**
     * Runs on plugin deactivation (register_deactivation_hook)
     */
    public static function deactivate()
    {
        // Unschedule plugin cron task
        $timestamp = wp_next_scheduled('nem_notify_cron_event');
        wp_unschedule_event($timestamp, 'nem_notify_cron_event');
    }

    /**
     * WP_CRON: Sends email if new payments found
     */
    public function cron_payments_received()
    {
        // Get options
        $hash    = get_option('nem_notify_last_hash');
        $options = get_option('nem_notify_options');
        $address = $options['nem_address'];

        // Check we are configured
        if (!$address) return;

        // Lookup transactions
        $net     = ('N' == $address[0]) ? 'mainnet' : 'testnet';
        $nem_api = new Nem_Api($net);
        $txns    = $nem_api->get_transactions_since($address, $hash);
        // error_log(print_r($txns,1));
        if (empty($txns)) {
            return;
        }

        // Prepare email
        $subject = sprintf(__('New NEM Transactions for: %s'), $address);
        $body    = __('The following new transactions have been received:') . "\n";
        foreach ($txns as $txn) {
            $body .= "\n";
            $body .= __('Date: ') . date("Y-m-d H:i:s", $nem_api->get_transaction_time($txn)) . ' - ';
            $type  = $nem_api->get_transaction_type($txn);
            $body .= $nem_api->get_transaction_type_description($type);
            $total = $nem_api->get_transaction_amount($txn);
            if ($total > 0) {
                $body .= ': ' . $nem_api->get_transaction_amount($txn) . ' XEM';
            }
            if ($msg = $nem_api->get_transaction_msg($txn)) {
                $body .=  "\n" . __('Message: ') . $msg;
            }
            $body .= "\n";
        }

        // Send email and update last txn hash
        if (wp_mail(get_option('admin_email'), $subject, $body)) {
            $nem_notify_last_hash = $nem_api->get_transaction_hash(reset($txns));
            update_option('nem_notify_last_hash', $nem_notify_last_hash);
            // error_log($body);
        }

    }

    /**
     * WP_CRON: Sends email if delegated harvesting stops
     */
    public function cron_harvesting_check()
    {
        // Get options
        $options = get_option('nem_notify_options');
        $node    = $options['harvest_node'];
        $remote  = $options['nem_remote'];

        // Check we are configured
        if (!$remote || !$node) return;

        // Lookup harvesting status
        $net     = ('N' == $remote[0]) ? 'mainnet' : 'testnet';
        $nem_api = new Nem_Api($net);
        $status  = $nem_api->check_harvesting_status($remote, $node);
        if ($status) {
            return; // all ok
        }

        // Prepare email
        $subject = __('Check Delegated Harvesting!');
        $body    = __('The Delegated Harvesting check failed for:') . "\n\n";
        $body   .= sprintf(__('Remote Account: %s'), $remote) . "\n";
        $body   .= sprintf(__('Node: %s'), $node) . "\n\n";
        $body   .= __('Please check your NanoWallet and reactivate harvesting again if needed.');

        // Send email
        if (wp_mail(get_option('admin_email'), $subject, $body)) {
            // error_log($body);
        }

    }

    /**
     * Registers plugin settings fields
     */
    function admin_init()
    {
        add_settings_section('main_section', '',
            array($this, 'main_section_desc'), 'nem_notify'
        );
        add_settings_section('harvesting_section', 'Delegated Harvesting',
            array($this, 'harvesting_section_desc'), 'nem_notify'
        );
        register_setting('nem_notify_options', 'nem_notify_options',
            array($this, 'validate_settings')
        );
        add_settings_field('nem_address', 'NEM Address',
            array($this, 'nem_address_field'), 'nem_notify', 'main_section'
        );
        add_settings_field('nem_remote', 'NEM Remote Account',
            array($this, 'nem_remote_field'), 'nem_notify', 'harvesting_section'
        );
        add_settings_field('harvest_node', 'Harvesting Node',
            array($this, 'harvest_node_field'), 'nem_notify', 'harvesting_section'
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
        <h2><?php _e('NEM Notify Settings'); ?></h2>
        <form action="options.php" method="post">
        <?php settings_fields('nem_notify_options'); ?>
        <?php do_settings_sections('nem_notify'); ?>
        <input name="Submit" type="submit" value="<?php esc_attr_e('Save Changes'); ?>" />
        </form>
        </div>
        <p>If you'd like to send me a tip in XEM, you can send it to:<br/>
        NBOVLA-3V7Z7H-7TT5VZ-4PYRGO-E6Y3DR-RQKACB-77KY</p>
        <?php
    }

    /**
     * Displays the Main Section Description
     */
    public function main_section_desc()
    {
        _e("Monitor a NEM address for new payments and get an email notification if any are detected. It's checked hourly.");
    }

    /**
     * Displays the Harvesting Section Description
     */
    public function harvesting_section_desc()
    {
        _e("Get an email notification if the node you are harvesting on is rebooted, and delegated harvesting stops. It's checked hourly.");
    }

    /**
     * Displays the NEM Address settings field
     */
    public function nem_address_field()
    {
        $options = get_option('nem_notify_options');
        echo "<input id='nem_address' name='nem_notify_options[nem_address]' size='50' type='text' value='{$options['nem_address']}' />";
    }

    /**
     * Displays the NEM Remote Address settings field for delegated harvesting
     */
    public function nem_remote_field()
    {
        $options = get_option('nem_notify_options');
        echo "<input id='nem_remote' name='nem_notify_options[nem_remote]' size='50' type='text' value='{$options['nem_remote']}' />";
    }

    /**
     * Displays the NEM Harvesting Node settings field for delegated harvesting
     */
    public function harvest_node_field()
    {
        $options = get_option('nem_notify_options');
        echo "<input id='harvest_node' name='nem_notify_options[harvest_node]' size='50' type='text' value='{$options['harvest_node']}'/>";
        echo '<p class="description">You can enter the node hostname or ip address</p>';
    }

    /**
     * Validates settings fields
     * @param  array $input Settings from form
     * @return array of validated options
     */
    public function validate_settings($input)
    {
        $options = get_option('nem_notify_options');
        $nem_address = trim($input['nem_address']);
        if ($options['nem_address'] != $nem_address) {
            $options['nem_address']  = $nem_address;
            delete_option('nem_notify_last_hash'); // reset
        }
        $options['nem_remote']   = trim($input['nem_remote']);
        $options['harvest_node'] = trim($input['harvest_node']);
        return $options;
    }
}