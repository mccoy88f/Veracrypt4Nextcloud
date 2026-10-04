<?php

declare(strict_types=1);

namespace OCA\VeraCryptBridge\AppInfo;

use OCA\VeraCryptBridge\Listener\PrivacyGuard;
use OCA\VeraCryptBridge\Listener\VolumeGuard;
use OCA\VeraCryptBridge\Listener\WriteGuard;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\BeforeNodeCopiedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\Util;

class Application extends App implements IBootstrap {
	public const APP_ID = 'veracryptbridge';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// The file of a mounted volume must not change, move or disappear
		$context->registerEventListener(BeforeNodeDeletedEvent::class, VolumeGuard::class);
		$context->registerEventListener(BeforeNodeRenamedEvent::class, VolumeGuard::class);
		$context->registerEventListener(BeforeNodeCopiedEvent::class, VolumeGuard::class);
		$context->registerEventListener(BeforeUserDeletedEvent::class, VolumeGuard::class);
	}

	public function boot(IBootContext $context): void {
		// Writes to the file of a mounted volume: only the old hook can cancel them
		$context->injectFn(function (WriteGuard $guard): void {
			Util::connectHook('OC_Filesystem', 'write', $guard, 'onWrite');
		});
		// Files of a mounted volume must not be copied, in clear, to the trash bin
		// or to the versions: these two events are only dispatched by name
		$context->injectFn(function (IEventDispatcher $dispatcher, PrivacyGuard $guard): void {
			$dispatcher->addListener('OCA\Files_Trashbin::moveToTrash', [$guard, 'onMoveToTrash']);
			$dispatcher->addListener('OCA\Files_Versions::createVersion', [$guard, 'onCreateVersion']);
		});
	}
}
