<?php
	use shanemcc\phpdb\DB;
	require_once(dirname(__FILE__) . '/../classes/TaskWorker.php');

	/**
	 * Task to verify a domain.
	 *
	 * Payload should be a json string with fields: 'domain'
	 */
	class verify_domain extends TaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['domain'])) {
				$domain = Domain::loadFromDomain(DB::get(), $payload['domain']);
				if ($domain !== FALSE) {
					echo 'Checking verification for: ', $domain->getDomain(), ' - Currently: ', $domain->getVerificationState(), ' as of ', date('r', $domain->getVerificationStateTime()), "\n";

					$nsrecords = dns_get_record($domain->getDomainRaw(), DNS_NS);

					$wantedNS = [];

					$records = $domain->getRecords('@', 'NS');
					foreach ($records as $r) {
						$wantedNS[] = strtolower($r->getContent());
					}

					// Use our default NS Records if none found
					if (empty($wantedNS)) {
						foreach (getSystemDefaultRecords() as $r) {
							if (strtoupper($r['type']) == 'NS') {
								$wantedNS[] = strtolower($t['content']);
							}
						}
					}

					$wantedNS = array_unique($wantedNS);

					echo 'Looking for records: ', implode(', ', $wantedNS), "\n";

					// Check which records in this domain are valid or not
					$validRecords = 0;
					$invalidRecords = 0;
					foreach ($nsrecords as $record) {
						echo 'Found record: ', $record['target'];
						if (in_array(strtolower($record['target']), $wantedNS)) {
							$validRecords++;
							echo ' - valid', "\n";
						} else {
							$invalidRecords++;
							echo ' - invalid', "\n";
						}
					}

					$newState = 'invalid';
					if ($validRecords > 0 && $invalidRecords <= 0) {
						$newState = 'valid';
					}

					$domain->setVerificationState($newState);
					$domain->setVerificationStateTime(time());
					$domain->save();
					echo 'New verification state for: ', $domain->getDomain(), ' - ', $domain->getVerificationState(), ' as of ', date('r', $domain->getVerificationStateTime()), "\n";
				} else {
					$job->setError('Unknown domain: ' . $payload['domain']);
				}
				$job->setResult('OK');
			} else {
				$job->setError('Missing fields in payload.');
			}
		}
	}
