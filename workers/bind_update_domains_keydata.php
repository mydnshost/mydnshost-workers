<?php
	use shanemcc\phpdb\DB;
	use shanemcc\phpdb\Search;

	require_once(dirname(__FILE__) . '/../classes/TaskWorker.php');

	/**
	 * Task to check all domains for incomplete DS records and schedule
	 * keydata updates where needed.
	 *
	 * For each domain with keys, checks that every key has DS records
	 * for all 3 digest types (1=SHA-1, 2=SHA-256, 4=SHA-384).
	 * Schedules bind_update_domain_keydata for any domain with missing entries.
	 *
	 * Payload fields: optional 'limit' (default: 10)
	 */
	class bind_update_domains_keydata extends TaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			$limit = isset($payload['limit']) ? $payload['limit'] : 10;

			$domains = Domain::getSearch(DB::get())->search(['disabled' => 'false']);

			if (!$domains) {
				echo 'No domains found.', "\n";
				$job->setResult('OK');
				return;
			}

			$scheduled = 0;

			foreach ($domains as $domain) {
				if ($scheduled >= $limit) {
					echo 'Reached limit of ', $limit, ', stopping.', "\n";
					break;
				}

				$keys = $domain->getZoneKeys();

				if (empty($keys)) { continue; }

				$needsUpdate = false;

				foreach ($keys as $key) {
					$digestTypes = [];

					foreach ($key->getKeyPublicRecords() as $record) {
						if ($record->getType() == 'DS') {
							$bits = explode(' ', $record->getContent());
							if (isset($bits[2])) {
								$digestTypes[] = intval($bits[2]);
							}
						}
					}

					$missing = array_diff([1, 2, 4], $digestTypes);

					if (!empty($missing)) {
						echo $domain->getDomainRaw(), ': key-id=', $key->getKeyID(), ' missing DS digest type(s): ', implode(', ', $missing), "\n";
						$needsUpdate = true;
					}
				}

				if ($needsUpdate) {
					$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_update_domain_keydata', ['domain' => $domain->getDomainRaw()]));
					$scheduled++;
				}
			}

			echo 'Checked ', count($domains), ' domain(s), scheduled ', $scheduled, ' for keydata update.', "\n";

			$job->setResult('OK');
		}
	}
