<?php
	use shanemcc\phpdb\DB;
	require_once(dirname(__FILE__) . '/../classes/BindTaskWorker.php');

	/**
	 * Task to update DNSSEC key records for all keys in a domain.
	 *
	 * Writes keys to disk then runs updateKeyRecords on each to refresh
	 * the DS/DNSKEY data from the public key files.
	 *
	 * Payload should be a json string with fields: 'domain', optional 'key_id'
	 */
	class bind_update_domain_keydata extends BindTaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['domain'])) {
				$domain = Domain::loadFromDomain(DB::get(), $payload['domain']);

				if ($domain !== FALSE) {
					// Ensure all keys are written to disk.
					$this->writeZoneKeys($domain, false);

					$keys = $domain->getZoneKeys();

					if (empty($keys)) {
						echo 'No keys found for ', $domain->getDomainRaw(), "\n";
						$job->setResult('OK');
						return;
					}

					$updated = 0;
					foreach ($keys as $key) {
						$flags = $key->getFlags();
						$keyType = ($flags == 257) ? 'KSK' : (($flags == 256) ? 'ZSK' : 'unknown');

						if (isset($payload['key_id']) && $key->getKeyID() != $payload['key_id']) {
							echo 'Skipping ', $keyType, ' key-id=', $key->getKeyID(), ' (not target key)', "\n";
							continue;
						}

						$public = $this->bindConfig['keydir'] . '/' . $key->getKeyFileName('key');

						echo 'Updating key records for ', $keyType, ' key-id=', $key->getKeyID(), ' from ', $public, "\n";

						$key->updateKeyRecords($public);
						$key->save();
						$updated++;
					}

					echo 'Updated ', $updated, ' of ', count($keys), ' key(s) for ', $domain->getDomainRaw(), "\n";

					$job->setResult('OK');
				} else {
					$job->setError('Unknown domain: ' . $payload['domain']);
				}
			} else {
				$job->setError('Missing fields in payload.');
			}
		}
	}
