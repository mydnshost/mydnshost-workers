<?php

	abstract class TaskWorker {
		private $taskServer;
		private $currentJobId = null;

		public function __construct($taskServer) {
			$this->taskServer = $taskServer;
		}

		public function getTaskServer() {
			return $this->taskServer;
		}

		public function setCurrentJobId($id) {
			$this->currentJobId = $id;
		}

		public function getCurrentJobId() {
			return $this->currentJobId;
		}

		abstract public function run($job);
	}
