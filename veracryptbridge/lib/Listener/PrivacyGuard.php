<?php

declare(strict_types=1);

namespace OCA\VeraCryptBridge\Listener;

use OCA\VeraCryptBridge\Service\VolumeService;
use Psr\Container\ContainerInterface;

/**
 * The content of a volume must stay inside the volume. Nextcloud would copy a
 * deleted file to the trash bin, and the previous content of a changed file to
 * its versions, both outside the volume and not encrypted by VeraCrypt: for the
 * files of the "VeraCrypt" folder both are turned off. A file deleted from a
 * volume is deleted for good, as in VeraCrypt on a computer.
 */
class PrivacyGuard {
	public function __construct(
		private ContainerInterface $container,
	) {
	}

	private function volumes(): VolumeService {
		return $this->container->get(VolumeService::class);
	}

	/** @param \OCA\Files_Trashbin\Events\MoveToTrashEvent $event */
	public function onMoveToTrash($event): void {
		if (is_object($event) && method_exists($event, 'getNode') && method_exists($event, 'disableTrashBin')
			&& $this->volumes()->isInsideVolume($event->getNode())) {
			$event->disableTrashBin();
		}
	}

	/** @param \OCA\Files_Versions\Events\CreateVersionEvent $event */
	public function onCreateVersion($event): void {
		if (is_object($event) && method_exists($event, 'getNode') && method_exists($event, 'disableVersions')
			&& $this->volumes()->isInsideVolume($event->getNode())) {
			$event->disableVersions();
		}
	}
}
