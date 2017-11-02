<?php
// No direct access
if (!defined('NEM_PAYMENT_NOTIFY_PATH')) {
    header('HTTP/1.0 403 Forbidden');
    wp_die(__('Nothing to see here... :)'));
}

class Nem_Api {

    public  $debug = false;
    private $transactions = [];

    private $servers = array(
        'bigalice3.nem.ninja',
        'alice2.nem.ninja',
        'go.nem.ninja'
    );

    private $testservers = array(
        'bob.nem.ninja',
        '104.128.226.60',
        '192.3.61.243'
    );

    /**
     * Constructor
     *
     * @param array|string $servers Array of NIS nodes|testnet|mainnet (default:mainnet)
     */
    public function __construct($servers='mainnet')
    {
        // Allow servers to be overriden
        if (is_array($servers) && !empty($servers)) {
            $this->servers = $servers;
        } else if ('mainnet' != $servers) {
            $this->servers = $this->testservers;
        }
    }

    /**
     * Gets all transactions *AFTER* the specified transaction hash
     *
     * NB: API doesn't look forwards, so we have to go from latest to earliest
     * until we find our starting hash or until earliest transaction record
     *
     * @param  string $address NEM Address to query
     * @param  string $hash Transaction hash to start from (default:false = all)
     * @return array of Transaction objects
     */
    public function get_transactions_since($address, $hash=false)
    {
        // Initialise
        $last_hash = false;
        $count     = 25;
        $this->transactions = [];

        // Look back over transactions until we stop getting max results (25)
        // from the API or until we reach our starting hash
        while (25 == $count) {

            // Get next batch of transactions
            $transactions = $this->lookup_transactions($address, $last_hash);
            if (!$transactions) break;

            // Inspect transactions
            $count = count($transactions);
            foreach ($transactions as $txn) {
                $last_hash = $this->get_transaction_hash($txn);
                if ($last_hash == $hash) {
                    // All done
                    return $this->transactions;
                }
                // Add to result
                $this->transactions[] = $txn;
            }
        }

        // Fallback - return all transactions so far
        return $this->transactions;
    }

    /**
     * Gets latest transactions (max 25) for an address
     *
     * @param  string $address NEM Address to query
     * @param  string $hash The transaction id *UP TO WHICH* transactions are returned
     * @return array of Transaction objects | false
     **/
    public function lookup_transactions($address, $hash=false)
    {

        // Initialise
        $address = str_replace('-','', $address);
        $path = ':7890/account/transfers/incoming?address='.$address;
        $path = add_query_arg('hash', $hash, $path); // adds only if set

        // Query servers until we get a response
        foreach ($this->servers as $server){
            $res = wp_remote_get('http://'.$server.$path);
            $res = rest_ensure_response($res);
            if($res->status === 200){
                break;
            }
        }

        // Log if WordPress Error
        if (is_wp_error($res)) {
           $error_message = $res->get_error_message();
           error_log("NEM Notify: $error_message");
        }

        // Debug log empty or bad response
        else if(empty($res) || empty($res->status) || $res->status !== 200){
            error_log('NEM Notify: Invalid response from API: '.print_r($res,1));
            return false;
        }

        // Decode and return array of transaction objects
        $transactions = json_decode($res->data['body']);
        if(is_object($transactions) && !empty($transactions->data)) {
            if ($this->debug) error_log(print_r($transactions->data,1));
            return $transactions->data;
        }
        return false;
    }

    /**
     * Checks delegated harvesting status
     *
     * @param  string $remote NEM Remote Address
     * @param  string $node   hostname or ip of node used for harvesting
     * @return bool true if harvesting is active, false otherwise
     **/
    public function check_harvesting_status($remote, $node)
    {
        // Initialise
        $remote = str_replace('-','', $remote);
        $path = ':7890/account/status?address='.$remote;

        // Query harvesting node for status
        $res = wp_remote_get('http://'.$node.$path);
        $res = rest_ensure_response($res);

        // Log if WordPress Error
        if (is_wp_error($res)) {
           $error_message = $res->get_error_message();
           error_log("NEM Notify: $error_message");
        }

        // Debug log empty or bad response
        else if(empty($res) || empty($res->status) || $res->status !== 200){
            error_log('NEM Notify: Invalid response from API: '.print_r($res,1));
            return false;
        }

        // Decode and return status
        $body = json_decode($res->data['body']);
        if(is_object($body) && !empty($body->status)) {
            if ($this->debug) error_log(print_r($body,1));
            return 'UNLOCKED' == $body->status;
        }
        return false;
    }

    /**
     * Get the transaction hash
     * @param  object $txn Transaction object
     * @return string transaction hash
     */
    public function get_transaction_hash($txn)
    {
        return $txn->meta->hash->data;
    }

    /**
     * Get the transaction data from MultiSig or Regular Transaction
     * @param  object $txn Transaction object
     * @return object Transaction data
     */
    public function get_transaction_data($txn)
    {
        // Multisig
        if(isset($txn->transaction->otherTrans)) {
            return $txn->transaction->otherTrans;
        }
        // Regular
        return $txn->transaction;
    }

    /**
     * Get the transaction type
     * @param  object $txn Transaction object
     * @return integer NEM Transaction type
     */
    public function get_transaction_type($txn)
    {
        $data = $this->get_transaction_data($txn);
        return $data->type;
    }

    /**
     * Get transaction type description
     * @param  integer $type NEM Transaction type
     * @return string Human friendly type description
     */
    public function get_transaction_type_description($type)
    {
        $types = array(
            '257'  => 'Transfer',
            '2049' => 'Importance',
            '4097' => 'MultiSig Modification',
            '4098' => 'MultiSig Signature',
            '4099' => 'MultiSig',
        );
        return isset($types[$type])
            ? $types[$type]
            : __('Unknown Transaction Type');
    }

    /**
     * Get the unix timestamp of a transaction
     * NB NEM timeStamps are relative to genesis block:
     * 29/03/2015 @ 12:06am (UTC) = 1427587585 unixtime
     * @param  object $txn Transaction object
     * @return integer Unix timestamp
     */
    public function get_transaction_time($txn)
    {
        $data = $this->get_transaction_data($txn);
        return $data->timeStamp + 1427587585;
    }

    /**
     * Get the transaction amount - including any XEM mosaics
     * @param  object $txn Transaction object
     * @return float Amount in XEM
     */
    public function get_transaction_amount($txn)
    {
        $data  = $this->get_transaction_data($txn);
        $total = 0.00;

        // Transfer amount
        if(!empty($data->amount)) {
            $total += ($data->amount / 1000000);
        }

        // XEM as mosaic
        if(empty($data->mosaics)) return $total;
        foreach ($data->mosaics as $mosaic) {
            if ('nem' == $mosaic->mosaicId->namespaceId && 'xem' == $mosaic->mosaicId->name) {
                $total += ($mosaic->quantity / 1000000);
            }
        }
        return $total;
    }

    /**
     * Get the transaction message
     * @param  object $txn Transaction object
     * @return string Decoded message
     */
    public function get_transaction_msg($txn)
    {
        $data = $this->get_transaction_data($txn);
        if(!empty($data->message->type)) {
            $type    = $data->message->type;
            $message = $data->message->payload;
        }
        // No message
        else {
            return false;
        }

        // Return message if not encrypted
        return (1 == $type)
            ? hex2bin($message)
            : __('Encrypted Message - Use NanoWallet to view');
    }
}