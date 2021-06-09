<?php

require_once '/lab2/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
$GLOBALS['pid'] = [];

$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
$channel = $connection->channel();

$channel->queue_declare('crypto-puzzle-inquiries', false, !false, false, false);

$channel->exchange_declare(
    'crypto-service-discovery', 
    'fanout', # type
    false,    # passive
    false,    # durable
    false     # auto_delete
);

list($queue_name_discovery, ,) = $channel->queue_declare(
    "",    # queue
    false, # passive
    false, # durable
    false,  # exclusive
    true  # auto delete
);
$channel->queue_bind($queue_name_discovery, 'crypto-service-discovery');


$channel->exchange_declare(
    'crypto-cancel-requests', 
    'fanout', # type
    false,    # passive
    false,    # durable
    false     # auto_delete
);

list($queue_name_cancel, ,) = $channel->queue_declare(
    "",    # queue
    false, # passive
    false, # durable
    false,  # exclusive
    true  # auto delete
);
$channel->queue_bind($queue_name_cancel, 'crypto-cancel-requests');

function send($resp){
	global $channel;
	
	
	$msg = new AMQPMessage(
            (string) $resp, []
        );
        $channel->basic_publish($msg, '', 'crypto-puzzle-responses');
	
}
echo " [x] Awaiting RPC requests\n";
function wrapper_solve($req){
	$obj = json_decode($req->body);
	//$resp = solve($obj->string, $obj->difficulty, $obj->from, $obj->to, $req);
	$message = $obj->string;
	$diff = $obj->difficulty;
	$from = $obj->from;
	$to = $obj->to;
	$descriptorspec = array(
);
	
	// $pid = proc_open("php ./rpc_slave_worker.php --message " . escapeshellarg($message) . " --diff $diff --to $to --from $from &> /dev/null 2> /dev/null &", [], $pipes);
	
	$pid = proc_open("php ". __DIR__ . "/rpc_slave_worker.php --message " . escapeshellarg($message) . " --diff $diff --to $to --from $from", [], $pipes);
	$s = proc_get_status($pid);

	$GLOBALS['pid'][] = $s;
	usleep(1000);	
    $req->ack();
	//var_dump($s);

}
$callback = function ($req) {
     echo ' [.] solve(', $req->body, ")\n";
	wrapper_solve($req);
};
$cancelCallback = function ($req) {
	$obj = json_decode($req->body);
    echo ' [.] cancel(', $req->body, ")\n";	
	
	if (!empty($GLOBALS['pid'])){
		foreach($GLOBALS['pid'] as $pid){
			// print_r($pid);
			$a = $pid['pid'];
			$r = $pid['pid'] + 1;
			$oo = [];
			$oo2 = [];
			exec("kill -9 {$a}", $oo);
			exec("kill -9 {$r}", $oo2);
			print_r(["after killing {$a}", implode(PHP_EOL, $oo)]);
			print_r(["after killing {$r}", implode(PHP_EOL, $oo2)]);
		}
		usleep(500);
		$req->ack();	
		$out = array();
		exec("ps", $out);
		// send(implode(PHP_EOL, $out));
		$GLOBALS['pid'] = [];
	}
	//send("CANCEL", $GLOBALS['req']);
};

$discoveryCallback = function ($req) {
    $req->ack();
    echo ' [.] discovery(', $req->body, ")\n";		
	
	send("discovered");
};

$channel->basic_qos(null, 1, null);
$channel->basic_consume('crypto-puzzle-inquiries', '', false, false, false, false, $callback);
$channel->basic_consume($queue_name_cancel, '', false, false, false, false, $cancelCallback);
$channel->basic_consume($queue_name_discovery, '', false, false, false, false, $discoveryCallback);

try
{
	
while ($channel->is_open()) {
    $channel->wait();
}
    // while (count($channel->callbacks))
    // {
        // // print "running non blocking wait." . PHP_EOL;
        // $channel->wait($allowed_methods=null, $nonBlocking=true, $timeout=1);
    // }
}
catch (Exception $e)
{
	print $e->getTraceAsString() . PHP_EOL;
    print "There are no more tasks in the queue." . PHP_EOL;
}

$channel->close();
$connection->close();
?>
