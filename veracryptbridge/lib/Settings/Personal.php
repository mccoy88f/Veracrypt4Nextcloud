<?php

declare(strict_types=1);

namespace OCA\VeraCryptBridge\Settings;

use OCA\VeraCryptBridge\AppInfo\Application;
use OCA\VeraCryptBridge\Service\VolumeService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IDateTimeFormatter;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
	public function __construct(
		private VolumeService $volumes,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IDateTimeFormatter $dateFormatter,
	) {
	}

	public function getForm(): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		Util::addStyle(Application::APP_ID, 'personal');
		Util::addScript(Application::APP_ID, 'personal');
		$configured = $this->volumes->isConfigured();
		if ($configured) {
			$this->volumes->reconcile($uid);
		}
		$service = $configured ? $this->volumes->ping() : null;

		$volumes = $configured ? $this->volumes->listVolumes($uid) : [];
		foreach ($volumes as &$v) {
			if ($v['problem'] !== null) {
				$v['problemText'] = $this->volumes->describe($v['problem']);
			}
			if ($v['mounted'] !== null) {
				$v['mounted']['when'] = $this->dateFormatter->formatDateTime((int)($v['mounted']['since'] ?? 0), 'short', 'short');
			}
		}
		unset($v);

		return new TemplateResponse(Application::APP_ID, 'personal', [
			'flash' => $this->volumes->popFlash($uid),
			'configured' => $configured,
			'service' => $service,
			'volumes' => $volumes,
			'extensions' => $this->volumes->getExtensions(),
			'mountName' => $this->volumes->getMountName(),
			'maxHours' => $this->volumes->getMaxHours(),
			'filesUrl' => $this->urlGenerator->linkToRoute('files.view.index', ['dir' => '/' . $this->volumes->getMountName()]),
			'errors' => array_map(fn (array $e) => $e + [
				'when' => $this->dateFormatter->formatDateTime($e['time'], 'short', 'medium'),
			], $this->volumes->getErrors($uid)),
			'mountUrl' => $this->urlGenerator->linkToRoute('veracryptbridge.volume.mount'),
			'unmountUrl' => $this->urlGenerator->linkToRoute('veracryptbridge.volume.unmount'),
			'addUrl' => $this->urlGenerator->linkToRoute('veracryptbridge.volume.add'),
			'removeUrl' => $this->urlGenerator->linkToRoute('veracryptbridge.volume.remove'),
			'clearErrorsUrl' => $this->urlGenerator->linkToRoute('veracryptbridge.volume.clearErrors'),
		], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
