<?php

declare(strict_types=1);

namespace OCA\VeraCryptBridge\BackgroundJob;

use OCA\VeraCryptBridge\Service\VolumeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Every 5 minutes: unmounts the volumes mounted for longer than max_hours, and
 * makes Nextcloud read again the files of the volumes unmounted meanwhile (for
 * example after a restart of the container), which changed while mounted.
 */
class Housekeeping extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private VolumeService $volumes,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void {
		if (!$this->volumes->isConfigured()) {
			return;
		}
		$users = array_unique(array_merge($this->volumes->getMountedUsers(), $this->volumes->getKnownUsers()));
		foreach ($users as $uid) {
			$this->volumes->autoUnmount($uid);
			$this->volumes->reconcile($uid);
		}
	}
}
