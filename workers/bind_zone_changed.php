<?php
	use shanemcc\phpdb\DB;
	require_once(dirname(__FILE__) . '/../classes/BindTaskWorker.php');

	/**
	 * Task to action a change to a zone file.
	 *
	 * Payload should be a json string with fields: 'domain', 'change'
	 */
	class bind_zone_changed extends BindTaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['domain']) && isset($payload['change'])) {

				// Get filename unless it was specified for us.
				if (isset($payload['filename'])) {
					$filename = $payload['filename'];
				} else {
					$filename = $this->bindConfig['zonedir'] . '/' . strtolower($payload['domain']) . '.db';
				}

				$domain = $payload['domain'];

				$commands = [];

				// %1$s == Domain Name
				// %2$s == Zone Filename
				// %3$s == Allowed IPs

				// Remove a domain (or delete as part of readd)
				if ($payload['change'] == 'remove' || $payload['change'] == 'readd') {
  					$commands[] = '/usr/sbin/rndc sync -clean %1$s';
					$commands[] = '/usr/sbin/rndc delzone %1$s';
					$commands[] = 'rm -v %2$s.*j*';
				}

				// Add a domain (Standalone or as part of readd)
				if ($payload['change'] == 'add' || $payload['change'] == 'readd') {
					$domain = Domain::loadFromDomain(DB::get(), $payload['domain']);
					if ($domain === FALSE) { $domain = $payload['domain']; }

					$hasKeys = ($domain instanceof Domain && !empty($domain->getZoneKeys()));

					$commands[] = 'chmod -v a+rwx %2$s';
					if ($hasKeys) {
						$commands[] = '/usr/sbin/rndc addzone %1$s \'{type master; file "%2$s"; allow-transfer { %3$s }; dnssec-policy "mydnshost"; };\'';
					} else {
						$commands[] = '/usr/sbin/rndc addzone %1$s \'{type master; file "%2$s"; allow-transfer { %3$s }; };\'';
					}
				}

				// Reload a domain.
				if ($payload['change'] == 'change') {
					$domain = Domain::loadFromDomain(DB::get(), $payload['domain']);
					if ($domain === FALSE) { $domain = $payload['domain']; }

					$commands[] = '/usr/sbin/rndc sync -clean %1$s';
					$commands[] = 'chmod -v a+rwx %2$s';
					$commands[] = '/usr/sbin/rndc reload %1$s';
				}

				// Run the appropriate commands.
				foreach ($commands as $cmd) {
					$command = $this->buildCommand('/usr/bin/sudo -n ' . $cmd . ' 2>&1', $domain, $filename);

					echo '====================', "\n";
					echo '$ Running Command: ', $command, "\n";
					echo '====================', "\n";
					$output = [];
					$returnCode = 0;
					exec($command, $output, $returnCode);
					echo implode("\n", $output), "\n";
					echo '==================== [', $returnCode, ']', "\n";
				}

				// Update the catalog zone unless noCatalog is passed.
				// (This will be passed when we are being called because of the catalog zone being updated.)
				if (!isset($payload['noCatalog'])) {
					$newjob = new JobInfo('', 'bind_update_catalog', $job->getPayload());
					$this->getTaskServer()->runBackgroundJob($newjob);
				}

				$job->setResult('OK');
			} else {
				$job->setError('Missing fields in payload.');
			}
		}
	}
