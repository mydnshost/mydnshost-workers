<?php
	use shanemcc\phpdb\DB;
	use shanemcc\phpdb\Search;

	require_once(dirname(__FILE__) . '/../classes/TaskWorker.php');

	/**
	 * Task to schedule verification of some domains
	 */
	class verify_domains extends TaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			// Find some domains that need verification.
			$s = new Search(DB::get()->getPDO(), 'domains', ['domain', 'disabled', 'verificationstate', 'verificationstatetime']);
			$s->where('disabled', 'true', '!=');
			$s->where('verificationstatetime', time() - (86400 * 7), '<=');
			$s->order('verificationstatetime');
			$s->order('domain');
			$s->limit(10);
			$rows = $s->getRows();

			foreach ($rows as $row) {
				echo 'Attempting to verify domain: ', $row['domain'], "\n";
				$newjob = new JobInfo('', 'verify_domain', ['domain' => $row['domain']]);
				$this->getTaskServer()->runBackgroundJob($newjob);
			}

			$job->setResult('OK');
		}
	}
