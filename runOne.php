#!/usr/bin/env php
<?php

	require_once(dirname(__FILE__) . '/../functions.php');
	require_once(dirname(__FILE__) . '/classes/JobInfo.php');
	require_once(dirname(__FILE__) . '/classes/TaskServer.php');

	if (!isset($argv[1])) {
		echo 'Usage: ', $argv[0], ' <function> [payload]', "\n";
		exit(1);
	}

	function getNextWorkerCommand() {
		global $__WorkerCommands;
		return array_shift($__WorkerCommands);
	}

	if (empty($config['redis']) || !class_exists('Redis')) {
		die('Redis is required for Task Running.' . "\n");
	}

	$taskServer = new class extends TaskServer {
		private $workers = [];
		private $jobs = [];

		function __construct() { }

		function runBackgroundJob($jobinfo) {
			$this->jobs[] = $jobinfo;
		}

		function runJob($jobinfo) {
			$this->jobs[] = $jobinfo;
		}

		function addTaskWorker($function, $worker) { $this->workers[$function] = $worker; }
		function run() {
			while (true) {
				$jobinfo = !empty($this->jobs) ? array_shift($this->jobs) : FALSE;
				if ($jobinfo == FALSE) {
					break;
				}

				echo "========================================", "\n";
				echo "| ", $jobinfo->getFunction(), ' => ', json_encode($jobinfo->getPayload()), "\n";
				echo "========================================", "\n";
				$worker = $this->workers[$jobinfo->getFunction()];
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
						echo 'RESULT ', $jobinfo->getResult(), "\n";
					} else {
						echo 'EXCEPTION There was no result.', "\n";
					}
				}
				echo "========================================", "\n";

			}
		}
		function stop() { }
	};

	$newjob = new JobInfo('', $argv[1], @json_decode(isset($argv[2]) ? $argv[2] : '', true));
	$taskServer->runJob($newjob);

	$__WorkerCommands = [];
	foreach (getJobWorkers('*') as $func => $args) {
		$__WorkerCommands[] = 'addFunction ' . $func;
	}
	$__WorkerCommands[] = 'setRedisHost ' . $config['redis'] . ' ' . (isset($config['redisPort']) ? $config['redisPort'] : '');
	$__WorkerCommands[] = 'run';

	require_once(__DIR__ . '/runWorker.php');
