<?php
	use shanemcc\phpdb\DB;

	abstract class BindTaskWorker extends TaskWorker {
		protected $bindConfig;

		public function __construct($taskServer) {
			parent::__construct($taskServer);

			global $config;
			$this->bindConfig = $config['hooks']['bind'];

			// Default config settings
			$defaults['zonedir'] = '/etc/bind/zones';
			$defaults['keydir'] = '/etc/bind/keys';
			$defaults['catalogZoneFile'] = '/etc/bind/zones/catalog.db';
			$defaults['catalogZoneName'] = 'catalog.invalid';
			$defaults['slaveServers'] = [];

			foreach ($defaults as $setting => $value) {
				if (!isset($this->bindConfig[$setting])) {
					$this->bindConfig[$setting] = $value;
				}
			}

			@mkdir($this->bindConfig['zonedir'], 0777, true);
			@mkdir($this->bindConfig['keydir'], 0777, true);
		}

		public function writeZoneFile($domain) {
			echo 'Writing zone file for: ', $domain->getDomainRaw(), "\n";

			$filename = $this->bindConfig['zonedir'] . '/' . strtolower($domain->getDomainRaw()) . '.db';
			echo 'Using filename: ', $filename, "\n";

			$new = !file_exists($filename);
			if ($new) { echo 'File is new.', "\n"; }

			$recordsInfo = $domain->getRecordsInfo(true);
			if ($recordsInfo['records'] instanceof RecordsInfo) { $recordsInfo['records'] = $recordsInfo['records']->get(); }

			$hasNS = !$domain->isDisabled() && isset($recordsInfo['records']['NS']);
			$zoneData = ZoneFileHandler::get('bind')->generateZoneFile($domain->getDomain(), $recordsInfo);

			$jobArgs = ['domain' => $domain->getDomainRaw(), 'filename' => $filename];

			// Try and lock the zone to ensure that we are the only ones
			// writing to it.
			if (RedisLock::acquireLock('zone_' . $domain->getDomainRaw())) {

				// Bind requires an NS record to load the zone, don't bother
				// attempting to add/change unless there is one.
				//
				// This means that the zone won't be added until it is actually
				// valid.
				if ($hasNS) {
					echo 'Zone has NS.', "\n";
					// if filemtime is the same as now, we need to wait to ensure
					// bind does the right thing.
					$filetime = $new ? 0 : filemtime($filename);
					if ($filetime >= time()) {
						echo 'Sleeping for zone: ', $filename, "\n";
						@time_sleep_until($filetime + 2);
					}

					$res = Bind::file_put_contents_atomic($filename, $zoneData);

					if ($new) {
						$jobArgs['change'] = 'add';
					} else {
						$jobArgs['change'] = 'change';
					}
				} else if (file_exists($filename)) {
					echo 'Zone has no NS.', "\n";
					foreach ([$filename, $filename . '.jbk', $filename . '.signed', $filename . '.signed.jnl'] as $f) {
						if (file_exists($f)) { @unlink($f); }
					}
					$jobArgs['change'] = 'remove';
				}

				RedisLock::releaseLock('zone_' . $domain->getDomainRaw());
			}

			$this->writeZoneKeys($domain);

			if (isset($jobArgs['change'])) {
				$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_zone_changed', $jobArgs));
			}
		}

		public function writeZoneKeys($domain, $generateIfMissing = True) {
			// Lock the zone file while we are making changes.
			echo 'Writing zone keys for: ', $domain->getDomainRaw(), "\n";

			if (RedisLock::acquireLock('zone_' . $domain->getDomainRaw())) {
				// Output any missing keys.
				$keys = $domain->getZoneKeys();

				if (empty($keys)) {
					if ($generateIfMissing) {
						echo 'No keys found, generating new keys.', "\n";
						$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_create_keys', ['domain' => $domain->getDomainRaw(), 'ifmissing' => true]));
					} else {
						echo 'No keys found to write.', "\n";
					}
				} else {
					$validFiles = [];
					foreach ($keys as $key) {
						$private = $this->bindConfig['keydir'] . '/' . $key->getKeyFileName('private');
						$public = $this->bindConfig['keydir'] . '/' . $key->getKeyFileName('key');
						$state = $this->bindConfig['keydir'] . '/' . $key->getKeyFileName('state');

						if (!file_exists($private) || !file_exists($public)) {
							echo 'Writing missing keys: ', $key->getKeyFileName(), "\n";
							file_put_contents($private, $key->getKeyPrivateFileContent());
							file_put_contents($public, $key->getKeyPublicFileContent());
						}

						$validFiles[] = $private;
						$validFiles[] = $public;
						$validFiles[] = $state;
					}

					// Remove no-longer required keys.
					$keys = glob($this->bindConfig['keydir'] . '/K' . $domain->getDomainRaw() . '.+*');
					foreach ($keys as $key) {
						if (!in_array($key, $validFiles)) {
							echo 'Removing invalid keyfile: ', $key, "\n";
							unlink($key);
						}
					}
				}

				RedisLock::releaseLock('zone_' . $domain->getDomainRaw());
			}
		}

		public function buildCommand($command, $domain, $filename) {
			$ips = [];
			$domainRaw = ($domain instanceof Domain) ? $domain->getDomainRaw() : $domain;

			if ($domain !== FALSE) {
				$ips = $this->getAllowedIPs($domain, false);
				if (empty($ips)) {
					$ips[] = '"none"';
				}
				$ips[] = '';
			}

			return sprintf($command, escapeshellarg($domainRaw), escapeshellarg($filename), implode('; ', $ips));
		}

		public function runCommand($command, $domain, $filename) {
			$cmd = $this->buildCommand($command, $domain, $filename);
			exec($cmd);
		}

		private function dns_get_record($host, $type) {
			global $__BIND__DNSCACHE;
			// Remove records older than 5 minutes to ensure we don't cache
			// things for too long.
			if (isset($__BIND__DNSCACHE[$host]) && ($__BIND__DNSCACHE[$host]['time'] + (60*5)) < time()) {
				unset($__BIND__DNSCACHE[$host]);
			}

			if (!isset($__BIND__DNSCACHE[$host])) {
				$records = dns_get_record($host, DNS_A | DNS_AAAA);
				$time = time();

				$__BIND__DNSCACHE[$host] = ['records' => $records, 'time' => time()];
			}

			return $__BIND__DNSCACHE[$host]['records'];
		}

		public function getAllowedIPs($domain, $APL) {
			if (!($domain instanceof Domain)) {
				$domain = Domain::loadFromDomain(DB::get(), $domain);
				if ($domain === FALSE) {
					return [];
				}
			}

			// Get NS Records
			$NS = [];
			foreach ($domain->getRecords() as $record) {
				if ($record->isDisabled()) { continue; }
				if ($record->getType() == "NS" && $record->getName() == $domain->getDomain()) {
					$NS[] = $record->getContent();
				}
			}

			$ips = [];
			foreach ($NS as $host) {
				$records = $this->dns_get_record($host, DNS_A | DNS_AAAA);

				foreach ($records as $rr) {
					if ($rr['type'] == 'A') {
						$ips[] = ($APL) ? '1:' . $rr['ip'] . '/32' : $rr['ip'];
					} else if ($rr['type'] == 'AAAA') {
						$ips[] = ($APL) ? '2:' . $rr['ipv6'] . '/128' : $rr['ipv6'];
					}
				}
			}

			// Add slave IPs
			$slaveServers = is_array($this->bindConfig['slaveServers']) ? $this->bindConfig['slaveServers'] : explode(',', $this->bindConfig['slaveServers']);
			foreach ($slaveServers as $s) {
				$s = trim($s);

				if (filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
					$ips[] = ($APL) ? '1:' . $s . '/32' : $s;
				} else if (filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
					$ips[] = ($APL) ? '2:' . $s . '/128' : $s;
				}
			}

			return array_unique($ips);
		}

		protected function sleepForZoneFile($zonefile) {
			// Make sure there is at least 1 second between subsequent
			// writes to a file.
			$filetime = filemtime($zonefile);
			if ($filetime >= time()) {
				echo 'Sleeping for zone: ', $zonefile, "\n";
				@time_sleep_until($filetime + 2);
			}
		}
	}
