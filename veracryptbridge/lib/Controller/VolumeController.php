<?php

declare(strict_types=1);

namespace OCA\VeraCryptBridge\Controller;

use OCA\VeraCryptBridge\AppInfo\Application;
use OCA\VeraCryptBridge\Service\BridgeException;
use OCA\VeraCryptBridge\Service\VolumeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class VolumeController extends Controller {
	public function __construct(
		IRequest $request,
		private VolumeService $volumes,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	private function back(): RedirectResponse {
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => Application::APP_ID])
		);
	}

	private function error(string $uid, BridgeException $e, string $path): void {
		$message = $this->volumes->describe($e->reason, $e->detail);
		$this->volumes->logError($uid, $message, $path);
		$this->volumes->setFlash($uid, 'error', $message);
	}

	/**
	 * @NoAdminRequired
	 * @BruteForceProtection(action=veracryptbridge_mount)
	 */
	#[NoAdminRequired]
	#[BruteForceProtection(action: 'veracryptbridge_mount')]
	public function mount(string $path = '', #[\SensitiveParameter] string $password = '', string $pim = '',
		string $keyfiles = '', string $readonly = ''): RedirectResponse {
		$uid = $this->uid();
		$response = $this->back();
		$pim = trim($pim);
		if ($pim !== '' && !ctype_digit($pim)) {
			$this->volumes->setFlash($uid, 'error', $this->l->t('The PIM must be a number (leave it empty if you did not set one).'));
			return $response;
		}
		// VeraCrypt can take a while: finish the mount even if the browser gives up waiting
		ignore_user_abort(true);
		try {
			$result = $this->volumes->mount($uid, $path, $password, (int)$pim, $keyfiles, $readonly !== '');
			$text = $this->l->t('Volume mounted: you find it in Files, in the folder “%1$s/%2$s”.',
				[$this->volumes->getMountName(), $result['dir']]);
			if ($result['note'] === 'fat_readonly') {
				$text .= ' ' . $this->l->t('It is read-only: this server cannot write FAT volumes.');
			} elseif ($result['readonly']) {
				$text .= ' ' . $this->l->t('It is read-only.');
			}
			$this->volumes->setFlash($uid, 'ok', $text);
		} catch (BridgeException $e) {
			$this->error($uid, $e, $path);
			if ($e->reason === 'wrong_password') {
				$response->throttle(['path' => $path]);
			}
		}
		return $response;
	}

	/** @NoAdminRequired */
	#[NoAdminRequired]
	public function unmount(string $file = '', string $force = ''): RedirectResponse {
		$uid = $this->uid();
		$volume = $this->volumes->findMounted($uid, $file);
		$path = $volume !== null ? $this->volumes->displayPath($volume) : '';
		ignore_user_abort(true);
		try {
			$this->volumes->unmount($uid, $file, $force !== '');
			$this->volumes->setFlash($uid, 'ok', $this->l->t('Volume unmounted.'));
		} catch (BridgeException $e) {
			$this->error($uid, $e, $path);
		}
		return $this->back();
	}

	/**
	 * Mount from the menu of the Files app.
	 *
	 * @NoAdminRequired
	 * @BruteForceProtection(action=veracryptbridge_mount)
	 */
	#[NoAdminRequired]
	#[BruteForceProtection(action: 'veracryptbridge_mount')]
	public function apiMount(string $path = '', #[\SensitiveParameter] string $password = '', string $pim = '',
		string $keyfiles = '', bool $readonly = false): JSONResponse {
		$uid = $this->uid();
		$pim = trim($pim);
		if ($pim !== '' && !ctype_digit($pim)) {
			return $this->json(false, $this->l->t('The PIM must be a number (leave it empty if you did not set one).'), 'bad_request');
		}
		ignore_user_abort(true);
		try {
			$result = $this->volumes->mount($uid, $path, $password, (int)$pim, $keyfiles, $readonly);
		} catch (BridgeException $e) {
			$message = $this->volumes->describe($e->reason, $e->detail);
			$this->volumes->logError($uid, $message, $path);
			$response = $this->json(false, $message, $e->reason);
			if ($e->reason === 'wrong_password') {
				$response->throttle(['path' => $path]);
			}
			return $response;
		}
		$text = $this->l->t('Volume mounted: you find it in Files, in the folder “%1$s/%2$s”.', [$this->volumes->getMountName(), $result['dir']]);
		if ($result['note'] === 'fat_readonly') {
			$text .= ' ' . $this->l->t('It is read-only: this server cannot write FAT volumes.');
		} elseif ($result['readonly']) {
			$text .= ' ' . $this->l->t('It is read-only.');
		}
		return $this->json(true, $text, '', ['dir' => $result['dir']]);
	}

	/**
	 * Unmount from the menu of the Files app: $path is the file of the volume or
	 * its folder inside "VeraCrypt".
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function apiUnmount(string $path = '', bool $force = false): JSONResponse {
		$uid = $this->uid();
		$volume = $this->volumes->findMountedByPath($uid, $path);
		if ($volume === null) {
			return $this->json(false, $this->volumes->describe('not_mounted'), 'not_mounted');
		}
		ignore_user_abort(true);
		try {
			$this->volumes->unmount($uid, $volume['file'], $force);
		} catch (BridgeException $e) {
			$message = $this->volumes->describe($e->reason, $e->detail);
			$this->volumes->logError($uid, $message, $this->volumes->displayPath($volume));
			return $this->json(false, $message, $e->reason);
		}
		return $this->json(true, $this->l->t('Volume unmounted.'));
	}

	private function json(bool $ok, string $message, string $code = '', array $extra = []): JSONResponse {
		return new JSONResponse(['ok' => $ok, 'message' => $message, 'code' => $code,
			'state' => $this->volumes->clientState($this->uid())] + $extra);
	}

	/** @NoAdminRequired */
	#[NoAdminRequired]
	public function add(string $path = ''): RedirectResponse {
		$uid = $this->uid();
		try {
			$path = $this->volumes->addExtra($uid, $path);
			$this->volumes->setFlash($uid, 'ok', $this->l->t('“%s” added to your volumes.', [$path]));
		} catch (BridgeException $e) {
			$this->volumes->setFlash($uid, 'error', $this->volumes->describe($e->reason, $e->detail));
		}
		return $this->back();
	}

	/** @NoAdminRequired */
	#[NoAdminRequired]
	public function remove(string $path = ''): RedirectResponse {
		$uid = $this->uid();
		$this->volumes->removeExtra($uid, $path);
		$this->volumes->setFlash($uid, 'ok', $this->l->t('“%s” removed from the list (the file is not deleted).', [$path]));
		return $this->back();
	}

	/** @NoAdminRequired */
	#[NoAdminRequired]
	public function clearErrors(): RedirectResponse {
		$uid = $this->uid();
		$this->volumes->clearErrors($uid);
		$this->volumes->setFlash($uid, 'ok', $this->l->t('Error log cleared.'));
		return $this->back();
	}
}
