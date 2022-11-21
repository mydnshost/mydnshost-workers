<?php

	require_once(dirname(__FILE__) . '/../functions.php');
	require_once(dirname(__FILE__) . '/classes/TaskServer.php');

	if (!isset($argv[1])) {
		echo 'Usage: ', $argv[0], ' function ["payload"]', "\n";
		exit(1);
	}

	function getNextWorkerCommand() {
		global $__WorkerCommands;
		return array_shift($__WorkerCommands);
	}

	if (empty($config['redis']) || !class_exists('Redis')) {
		die('Redis is required for Task Running.' . "\n");
	}

	$__WorkerCommands = [];
	$__WorkerCommands[] = 'addFunction ' . $argv[1];
	$__WorkerCommands[] = 'setRedisHost ' . $config['redis'] . ' ' . (isset($config['redisPort']) ? $config['redisPort'] : '');
	$__WorkerCommands[] = 'run';

	$taskServer = new class extends TaskServer {
		private $workers = [];

		function __construct() { }
		function runBackgroundJob($jobinfo) { }
		function runJob($jobinfo) { }
		function addTaskWorker($function, $worker) { $this->workers[$function] = $worker; }
		function run() {
			global $argv;
			$function = $argv[1];
			$payload = @json_decode($argv[2], true);

			if (empty($payload)) {
				$payload = [];
			} else if (!is_array($payload)) {
				echo 'EXCEPTION Invalid Payload.', "\n";
			}

			if (is_array($payload)) {
				$jobinfo = new JobInfo(-1, $function, $payload);
				$worker = $this->workers[$function];
				try {
					$worker->run($jobinfo);
				} catch (Throwable $ex) {
					echo 'Uncaught Exception: ', $ex->getMessage(), "\n";
					echo $ex->getTraceAsString(), "\n";
				}

				if ($jobinfo->hasError()) {
					$resultMsg = 'ERROR';
					$resultState = 'error';
					echo 'EXCEPTION There was an error: ' . $jobinfo->getError(), "\n";
				} else {
					if ($jobinfo->hasResult()) {
						echo 'EXCEPTION RESULT ', $jobinfo->getResult(), "\n";
					} else {
						echo 'EXCEPTION There was no result.', "\n";
					}
				}
			}
		}
		function stop() { }
	};

	require_once(__DIR__ . '/runWorker.php');
