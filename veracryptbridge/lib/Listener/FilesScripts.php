<?php

declare(strict_types=1);

namespace OCA\VeraCryptBridge\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\VeraCryptBridge\AppInfo\Application;
use OCA\VeraCryptBridge\Service\VolumeService;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Adds "Mount with VeraCrypt" and "Unmount" to the actions of the Files app,
 * with the user's volumes as they are when the page is loaded.
 *
 * @template-implements IEventListener<Event>
 */
class FilesScripts implements IEventListener {
	public function __construct(
		private VolumeService $volumes,
		private IUserSession $userSession,
		private IInitialState $initialState,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent || !$this->volumes->isConfigured()) {
			return;
		}
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return;
		}
		$this->initialState->provideInitialState('files', $this->volumes->clientState($uid));
		// Before the Files app starts, which reads the actions when it starts
		Util::addInitScript(Application::APP_ID, 'files');
		Util::addTranslations(Application::APP_ID);
		Util::addStyle(Application::APP_ID, 'files');
	}
}
