<?php
	use shanemcc\phpdb\DB;
	require_once(dirname(__FILE__) . '/../classes/BindTaskWorker.php');

	/**
	 * Task to delete a specific DNSSEC key for a domain.
	 *
	 * Payload should be a json string with fields: 'domain', 'key_id'
	 */
	class bind_delete_key extends BindTaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['domain']) && isset($payload['key_id'])) {
				$domain = Domain::loadFromDomain(DB::get(), $payload['domain']);

				if ($domain !== FALSE) {
					$keyID = $payload['key_id'];
					$key = ZoneKey::loadFromDomainKey(DB::get(), $domain->getID(), $keyID);

					if ($key !== FALSE) {
						$flags = $key->getFlags();
						$keyType = ($flags == 257) ? 'KSK' : (($flags == 256) ? 'ZSK' : 'unknown');

						echo 'Deleting ', $keyType, ' key-id=', $keyID, ' for ', $domain->getDomainRaw(), "\n";

						$key->delete();

						echo 'Key deleted from database.', "\n";

						// writeZoneKeys will clean up orphaned key files on disk
						$this->writeZoneKeys($domain, false);

						echo 'Scheduling zone refresh.', "\n";
						$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_zone_changed', ['domain' => $domain->getDomainRaw(), 'change' => 'change']));
					} else {
						echo 'Key not found in database: key_id=', $keyID, ' for ', $domain->getDomainRaw(), "\n";
					}
				} else {
					$job->setError('Unknown domain: ' . $payload['domain']);
				}
			} else {
				$job->setError('Missing fields in payload.');
			}

			$job->setResult('OK');
		}
	}
