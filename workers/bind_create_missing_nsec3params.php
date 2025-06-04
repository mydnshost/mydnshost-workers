<?php
	use shanemcc\phpdb\DB;
	use shanemcc\phpdb\Search;
	require_once(dirname(__FILE__) . '/../classes/BindTaskWorker.php');

	/**
	 * Task to create missing nsec3params for a domain
	 */
	class bind_create_missing_nsec3params extends BindTaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['domain'])) {
				$domain = Domain::loadFromDomain(DB::get(), $payload['domain']);

				if ($domain !== FALSE) {
    				$generated = False;

					$nsec3 = $domain->getNSEC3Params();
					if (empty($nsec3)) {
						echo 'Generating NSEC3 Params.', "\n";
						$nsec3hash = substr(sha1(openssl_random_pseudo_bytes('512')), 0, 16);
						$domain->setNSEC3Params('1 0 10 ' . $nsec3hash);
						$domain->save();
                        $generated = True;
					}

					if ($generated) {
						echo 'NSEC3Params generated, scheduling zone refresh.', "\n";
						$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_zone_changed', ['domain' => $domain->getDomainRaw(), 'change' => 'change']));
					} else {
						echo 'No NSEC3Params generated.', "\n";
					}
				} else {
					$job->setError('Unknown domain: ' . $payload['domain']);
				}
			} else {
                $limit = isset($payload['limit']) ? $payload['limit'] : 10;

                // Find some domains that are missing nsec3 params.
                $s = new Search(DB::get()->getPDO(), 'domains', ['domain', 'disabled', 'nsec3params']);
                $s->where('disabled', 'true', '!=');
                $s->where('nsec3params', null, 'is');
                $s->order('domain');
                $s->limit($limit);
                $rows = $s->getRows();

                foreach ($rows as $row) {
                    echo 'Attempting to create missing nsec3 params for domain: ', $row['domain'], "\n";
                    $newjob = new JobInfo('', 'bind_create_missing_nsec3params', ['domain' => $row['domain']]);
                    $this->getTaskServer()->runBackgroundJob($newjob);
                }

			}

			$job->setResult('OK');
		}
	}
