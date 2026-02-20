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
								$wantedNS[] = strtolower($r['content']);
							}
						}
					}

					$wantedNS = array_unique($wantedNS);

					echo 'Looking for records: ', implode(', ', $wantedNS), "\n";

					// Check which records in this domain are valid or not
					$validRecords = 0;
					$invalidRecords = 0;
					if (is_array($nsrecords)) {
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
					}

					$newState = 'invalid';
					if ($validRecords > 0 && $invalidRecords <= 0) {
						$newState = 'valid';
					}

					$domain->setVerificationState($newState);
					$domain->setVerificationStateTime(time());

					// DNSSEC verification
					$this->verifyDNSSEC($domain, $newState);

					$domain->save();
					echo 'New verification state for: ', $domain->getDomain(), ' - ', $domain->getVerificationState(), ' as of ', date('r', $domain->getVerificationStateTime()), "\n";
					echo 'DNSSEC state: ', $domain->getDnssecState(), "\n";
				} else {
					$job->setError('Unknown domain: ' . $payload['domain']);
				}
				$job->setResult('OK');
			} else {
				$job->setError('Missing fields in payload.');
			}
		}

		private function verifyDNSSEC($domain, $nsState) {
			echo 'Checking DNSSEC for: ', $domain->getDomain(), ' - Currently: ', $domain->getDnssecState(), "\n";

			// If domain is not pointing at us, we can't verify DNSSEC
			if ($nsState !== 'valid') {
				echo 'Domain NS not valid, setting DNSSEC state to not_verified', "\n";
				$domain->setDnssecState('not_verified');
				return;
			}

			$domainName = $domain->getDomainRaw();

			// Query live DNSKEY records from the zone
			$liveDNSKEYs = Dig::queryDNSKEY($domainName);
			$liveKeytags = [];
			foreach ($liveDNSKEYs as $dnskey) {
				$liveKeytags[] = $dnskey['keytag'];
				echo 'Live DNSKEY: flags=', $dnskey['flags'], ' keytag=', $dnskey['keytag'], "\n";
			}

			// Query DS records from the parent
			$parentDS = Dig::queryDS($domainName);
			$parentKeytags = [];
			foreach ($parentDS as $ds) {
				$parentKeytags[] = $ds['keytag'];
				echo 'Parent DS: keytag=', $ds['keytag'], ' algorithm=', $ds['algorithm'], ' digesttype=', $ds['digesttype'], "\n";
			}
			$parentKeytags = array_unique($parentKeytags);

			// Update per-key state for all zone keys
			$now = time();
			$allKeys = $domain->getZoneKeys();
			foreach ($allKeys as $key) {
				$keytag = $key->getKeyID();
				$key->setIsSigning(in_array($keytag, $liveKeytags));
				$key->setAtParent(in_array($keytag, $parentKeytags));
				$key->setSigningCheckTime($now);
				$key->save();
				echo 'ZoneKey ', $keytag, ' (flags=', $key->getFlags(), '): is_signing=', ($key->getIsSigning() ? 'true' : 'false'), ' at_parent=', ($key->getAtParent() ? 'true' : 'false'), "\n";
			}

			// Compute domain-level DNSSEC state
			if (empty($parentDS)) {
				echo 'No DS records at parent', "\n";
				$domain->setDnssecState('not_signed');
				return;
			}

			// Build set of keytags for our known, signing KSK keys
			$knownSigningKeytags = [];
			foreach ($allKeys as $key) {
				if ($key->getFlags() == 257 && $key->getIsSigning()) {
					$knownSigningKeytags[] = $key->getKeyID();
				}
			}

			// Check how many parent DS keytags match known signing keys
			$matchedKeytags = 0;
			$unmatchedKeytags = 0;
			foreach ($parentKeytags as $keytag) {
				if (in_array($keytag, $knownSigningKeytags)) {
					$matchedKeytags++;
				} else {
					$unmatchedKeytags++;
				}
			}

			if ($matchedKeytags > 0 && $unmatchedKeytags == 0) {
				$domain->setDnssecState('signed');
				echo 'All parent DS records match known signing keys', "\n";
			} else if ($matchedKeytags > 0 && $unmatchedKeytags > 0) {
				$domain->setDnssecState('signed_extra_keys');
				echo 'Some parent DS records match, but ', $unmatchedKeytags, ' extra keytag(s) at parent', "\n";
			} else {
				$domain->setDnssecState('broken_signature');
				echo 'No parent DS records match any known signing keys', "\n";
			}
		}
	}
