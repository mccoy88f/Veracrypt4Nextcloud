<?php

declare(strict_types=1);

namespace OCA\VeraCryptBridge\Listener;

use OCA\VeraCryptBridge\Service\VolumeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeCopiedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\User\Events\BeforeUserDeletedEvent;

/**
 * While a volume is mounted, VeraCrypt writes into its file: deleting, moving,
 * copying or replacing it (or a folder containing it) would lose or corrupt
 * data, so these operations are refused until the volume is unmounted.
 * Writes are refused by WriteGuard.
 *
 * @template-implements IEventListener<Event>
 */
class VolumeGuard implements IEventListener {
	public function __construct(
		private VolumeService $volumes,
		private IL10N $l,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof BeforeUserDeletedEvent) {
			$this->volumes->unmountAll($event->getUser()->getUID());
			return;
		}
		if ($event instanceof BeforeNodeDeletedEvent) {
			$this->check($event->getNode());
		} elseif ($event instanceof BeforeNodeRenamedEvent || $event instanceof BeforeNodeCopiedEvent) {
			// The target too: moving or copying another file over the volume replaces it
			$this->check($event->getSource());
			$this->check($event->getTarget());
		}
	}

	private function check(Node $node): void {
		$volume = $this->volumes->mountedVolumeOf($node);
		if ($volume === null) {
			return;
		}
		$message = $this->l->t('“%s” is a mounted VeraCrypt volume: it cannot be changed, moved, copied or deleted until you unmount it.',
			[$this->volumes->displayPath($volume)]);
		$this->volumes->logError($volume['owner'], $message, $this->volumes->displayPath($volume));
		// Nextcloud cancels the operation
		throw new AbortedEventException($message);
	}
}
