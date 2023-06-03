<?php
	use shanemcc\phpdb\DB;
	use shanemcc\phpdb\Search;
	require_once(dirname(__FILE__) . '/../classes/BindTaskWorker.php');

	/**
	 * Task to create keys for a domain
	 */
	class bind_create_keys extends BindTaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['domain'])) {
				$domain = Domain::loadFromDomain(DB::get(), $payload['domain']);

				if ($domain !== FALSE) {
					if (isset($payload['ifmissing']) && parseBool($payload['ifmissing'])) {
						// Check if we already have keys, and abort if we do.
						$keys = $domain->getZoneKeys();
						if (!empty($keys)) {
							echo 'Existing keys found, not generating new keys.', "\n";
							$job->setResult('OK');
							return;
						}
					}

					$generated = False;

					echo 'Generating KSK.', "\n";
					$ksk = ZoneKey::generateKey(DB::get(), $domain, 257, 'RSASHA256', 2048);
					$ksk->validate();
					$ksk->save();
					if (!empty($ksk->getID())) {
						echo 'Generated KSK: ', $ksk->getKeyID(), ' (', $ksk->getID(), ')', "\n";
						$generated = True;
					} else {
						echo 'Failed to generate KSK.', "\n";
					}

					echo 'Generating ZSK.', "\n";
					$zsk = ZoneKey::generateKey(DB::get(), $domain, 256, 'RSASHA256', 1024);
					$zsk->validate();
					$zsk->save();
					if (!empty($zsk->getID())) {
						echo 'Generated ZSK: ', $zsk->getKeyID(), ' (', $zsk->getID(), ')', "\n";
						$generated = True;
					} else {
						echo 'Failed to generate KSK.', "\n";
					}

					$this->writeZoneKeys($domain);

					if ($generated) {
						echo 'Keys generated, scheduling zone refresh.', "\n";
						$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_zone_changed', ['domain' => $domain->getDomainRaw(), 'change' => 'change']));
					} else {
						echo 'No keys generated.', "\n";
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
