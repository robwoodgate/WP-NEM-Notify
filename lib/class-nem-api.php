<?php
// No direct access
if (!defined('NEM_PAYMENT_NOTIFY_PATH')) {
    header('HTTP/1.0 403 Forbidden');
    wp_die(__('Nothing to see here... :)'));
}

class Nem_Api {

	public $debug = false;
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

	private $txn_types = array(
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
     * Gets all transactions *AFTER* the specified transaction id
     *
     * NB: API doesn't look forwards, so we have to go from latest to earliest
     * until we find our starting id or earliest transaction record
     *
     * @param string $address NEM Address to query
     * @param int $id Transaction id to start from (default:0 = all)
     * @return array of Transaction objects
     */
    public function get_transactions_since($address, $id=0)
    {
    	// Initialise
     	$id     = absint($id);
     	$txn_id = false;
        $count  = 25;
     	$this->transactions = [];

     	// Look back over transactions until we stop getting max results (25)
     	// from the API or until we reach our starting id
     	while (25 == $count) {

     		// Get next batch of transactions
     		$transactions = $this->lookup_transactions($address, $txn_id);
     		if (!$transactions) break;

     		// Inspect transactions
     		$count = count($transactions);
     		foreach ($transactions as $txn) {
	     		$txn_id = $txn->meta->id;
	     		if ($txn_id <= $id) {
	     			// All done
	     			return $this->transactions;
	     		}
	     		// Add to result
	     		$this->transactions[] = $txn;
	     	}
     	}

     	// Fallback - return all transactions
		return $this->transactions;
    }

    /**
	 * Gets latest transactions (max 25) for an address
	 *
	 * @param string $address NEM Address to query
	 * @param string $id The transaction id *UP TO WHICH* transactions are returned
	 * @return array of Transaction objects
	 **/
	public function lookup_transactions($address, $id=false)
	{

		// Initialise
        $address = str_replace('-','', $address);
		$path = ':7890/account/transfers/incoming?address='.$address;
		$path = add_query_arg('id', $id, $path); // adds only if set

		// Query servers until we get a response
		foreach ($this->servers as $server){
			$res = wp_remote_get('http://'.$server.$path);
			$res = rest_ensure_response($res);
			if($res->status === 200){
				break;
			}
		}

		// Empty or bad response
		if(empty($res) || empty($res->status) || $res->status !== 200){
			if ($this->debug) error_log('Invalid response from API: '.print_r($res,1));
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
	 * Get the transaction id
	 * @param object Transaction object
	 */
	public function get_transaction_id($txn)
	{
		return $txn->meta->id;
	}

	/**
	 * Get the unix timestamp of a transaction
	 * @param object Transaction object
	 */
	public function get_transaction_time($txn)
	{
		// timeStamps are relative to genesis block: 29/03/2015 @ 12:06am (UTC)
		return $txn->transaction->timeStamp + 1427587585;
	}

	/**
	 * Get the transaction amount
	 * @param object Transaction object
	 */
	public function get_transaction_amount($txn)
	{
		return $txn->transaction->amount / 1000000;
	}
}