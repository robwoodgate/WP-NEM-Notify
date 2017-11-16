<?php
// No direct access
if (!defined('NEM_PAYMENT_NOTIFY_PATH')) {
    header('HTTP/1.0 403 Forbidden');
    wp_die(__('Nothing to see here... :)'));
}

class Nem_Api {

    public  $debug = false;
    private $transactions = [];
    private $last_error;

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
            $this->servers = array_filter($servers); // Filter any empty values
        } else if ('mainnet' != $servers) {
            $this->servers = $this->testservers;
        }
    }

    /**
     * Getter for last_error (read-only)
     *
     * @return string Last error encountered
     */
    public function get_last_error()
    {
        return $this->last_error;
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
     * @param  string $txntype Transaction type: incoming|outgoing default: incoming
     * @return array of Transaction objects | false
     **/
    public function lookup_transactions($address, $hash=false, $txntype='incoming')
    {
        // Initialise
        $txntype = ('incoming' == $txntype) ? 'incoming' : 'outgoing';
        $address = str_replace('-','', $address);
        $path = '/account/transfers/'.$txntype.'?address='.$address;
        $path = add_query_arg('hash', $hash, $path); // adds only if set

        // Send request
        $transactions = $this->send_api_request($path);
        if(is_object($transactions) && !empty($transactions->data)) {
            return $transactions->data;
        }
        return false;
    }

    /**
     * Gets mosaics held by an address
     *
     * @param  string $address NEM Address to query
     * @return array of Mosaic objects | false
     **/
    public function lookup_mosaics_owned($address)
    {
        // Initialise
        $address = str_replace('-','', $address);
        $path = '/account/mosaic/owned?address='.$address;

        // Send request
        $mosaics = $this->send_api_request($path);
        if(is_object($mosaics) && !empty($mosaics->data)) {
            return $mosaics->data;
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
        $path = '/account/status?address='.$remote;

        // Query harvesting node for status
        $res = $this->send_api_request($path, $node);

        // Decode and return status
        if(is_object($res) && !empty($res->status)) {
            return 'UNLOCKED' == $res->status;
        }
        return false;
    }

    /**
     * Sends an api request, querying multiple servers if needed
     *
     * @param  string $api_path The api request path, with leading slash
     * @param  string $node Allows a specific node to be queried (e.g. for harvesting checks)
     * @return mixed  API object | array of objects | false
     **/
    public function send_api_request($api_path, $node='default')
    {
        // Check api_path has leading slash
        $api_path = ('/' != $api_path[0]) ? '/' . $api_path : $api_path;

        // Get server(s) to use - default or a specific node
        $servers = ('default' == $node) ? $this->servers : array($node);

        // Query servers until we get a response
        foreach ($servers as $server){
            $res = wp_remote_get('http://'.$server.':7890'.$api_path);
            $res = rest_ensure_response($res);
            if(isset($res->status) && $res->status === 200){
                break;
            }
        }

        // Log if WordPress Error
        $this->last_error = '';
        if (is_wp_error($res)) {
           $this->last_error = $res->get_error_message();
           error_log("NEM Notify: {$this->last_error}");
           return false;
        }

        // Debug log empty or bad response
        else if(empty($res) || empty($res->status) || $res->status !== 200){
            error_log('NEM Notify: Invalid response from API: '.print_r($res,1));
            $this->last_error = 'API failed to respond';
            return false;
        }

        // Decode and return response object
        $ret = json_decode($res->data['body']);
        if(!$ret) return false;

        if ($this->debug) error_log(print_r($ret,1));
        return $ret;
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