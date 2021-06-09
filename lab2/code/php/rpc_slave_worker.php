<?php

require_once '/lab2/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
$channel = $connection->channel();

$options = getopt("", ["message:", "diff:", "to:", "from:"]);
['message' => $message, 'diff' => $diff,
'to'=> $to, 'from'=> $from
] = $options;
//var_dump($options);

$diff = intval($diff);
$to = intval($to);
$from = intval($from);
solve($message, $diff, $to, $from);

function send($resp){
	global $channel;
	
	
	$msg = new AMQPMessage(
            (string) $resp, []
        );
        $channel->basic_publish($msg, '', 'crypto-puzzle-responses');
	
}
function solve($message, $diff, $from, $to)
{
	$name = gethostname();
	$start = microtime(true);
	$want = str_repeat("0", $diff);
	for($i = min($from, $to); $i < max($from, $to); $i++){
		$possible = hash('sha256', $message . (string)$i);
		if (stripos($possible, $want) === 0){
			$time_elapsed_secs = microtime(true) - $start;
			echo "[time = $time_elapsed_secs]". $message . ' - ' . (string)$i . ' - ' . $possible . PHP_EOL;
			send("[time = $time_elapsed_secs]". $message . ' - ' . (string)$i . ' - ' . $possible);
			return "[time = $time_elapsed_secs]". $message . ' - ' . (string)$i . ' - ' . $possible;
		}
	}
	send("not-found");
	echo "not-found" . PHP_EOL;
	return "not-found";
}
