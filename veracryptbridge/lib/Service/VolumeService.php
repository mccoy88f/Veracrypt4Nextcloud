<?php

declare(strict_types=1);

namespace OCA\VeraCryptBridge\Service;

use OCA\VeraCryptBridge\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IHomeStorage;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Talks to the vc4nc-bridge container, which mounts the volumes, and keeps
 * what the settings page shows: the user's volumes, their state, the error log.
 *
 * The container reads one JSON request per connection on <bridge_dir>/vc.sock
 * and writes, readable by Nextcloud:
 *   <bridge_dir>/state/<uid>.json   the user's mounted volumes (no passwords)
 *   <bridge_dir>/errors/<uid>.log   events such as "unmounted after a restart"
 *
 * A volume is identified by its path relative to the Nextcloud data folder
 * ("rel", e.g. "alice/files/Docs/archive.hc"), the one the container sees.
 */
class VolumeService {
	private const APP = Application::APP_ID;
	private const MAX_ERRORS = 30;
	/** Group to which the "VeraCrypt" external storage applies */
	public const GROUP_ID = 'veracrypt';

	/** @var array<string, list<array>> mounted volumes per user, for this request */
	private array $stateCache = [];

	public function __construct(
		private IConfig $config,
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/* ---------- Instance settings (occ config:app:set) ---------- */

	public function getBridgeDir(): string {
		return rtrim($this->config->getAppValue(self::APP, 'bridge_dir', ''), '/');
	}

	public function isConfigured(): bool {
		$dir = $this->getBridgeDir();
		return $dir !== '' && is_dir($dir);
	}

	/** Name of the folder in Files where the mounted volumes appear */
	public function getMountName(): string {
		return $this->config->getAppValue(self::APP, 'mount_name', 'VeraCrypt');
	}

	/** @return list<string> extensions of the files listed as volumes */
	public function getExtensions(): array {
		$list = explode(',', strtolower($this->config->getAppValue(self::APP, 'extensions', 'hc,vc')));
		return array_values(array_filter(array_map(fn ($e) => trim($e, " .\t"), $list), fn ($e) => $e !== ''));
	}

	/** Hours after which a mounted volume is unmounted by itself (0: never) */
	public function getMaxHours(): int {
		return max(0, (int)$this->config->getAppValue(self::APP, 'max_hours', '0'));
	}

	/** Id of the external storage created by install.sh */
	public function getStorageId(): int {
		return (int)$this->config->getAppValue(self::APP, 'storage_id', '0');
	}

	/* ---------- Requests to the container ---------- */

	/**
	 * @throws BridgeException
	 */
	private function request(array $request, int $timeout = 900): array {
		if (!$this->isConfigured()) {
			throw new BridgeException('not_configured');
		}
		$socket = @stream_socket_client('unix://' . $this->getBridgeDir() . '/vc.sock', $errno, $errstr, 5);
		if ($socket === false) {
			throw new BridgeException('unreachable', $errstr);
		}
		try {
			stream_set_timeout($socket, $timeout);
			fwrite($socket, json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
			$line = fgets($socket);
			$timedOut = (bool)(stream_get_meta_data($socket)['timed_out'] ?? false);
		} finally {
			fclose($socket);
			// The container may have changed the state of the volumes
			$this->stateCache = [];
		}
		if ($line === false) {
			throw new BridgeException($timedOut ? 'timeout' : 'unreachable');
		}
		$data = json_decode($line, true);
		if (!is_array($data)) {
			throw new BridgeException('internal', mb_substr($line, 0, 200));
		}
		if (($data['ok'] ?? false) !== true) {
			throw new BridgeException((string)($data['code'] ?? 'internal'), (string)($data['detail'] ?? ''));
		}
		return $data;
	}

	/** @return array{veracrypt: string, kernel: bool}|null null if the container does not answer */
	public function ping(): ?array {
		try {
			$data = $this->request(['action' => 'ping'], 10);
		} catch (BridgeException $e) {
			$this->logger->info('VeraCrypt service not reachable: ' . $e->getMessage(), ['app' => self::APP]);
			return null;
		}
		return ['veracrypt' => (string)($data['veracrypt'] ?? ''), 'kernel' => (bool)($data['kernel'] ?? false)];
	}

	/* ---------- Mounted volumes ---------- */

	/**
	 * Volumes mounted by the user, as written by the container:
	 * file, ncpath, dir, fs, readonly, since…
	 *
	 * @return list<array>
	 */
	public function getMounted(string $uid): array {
		if (isset($this->stateCache[$uid])) {
			return $this->stateCache[$uid];
		}
		$volumes = [];
		$file = $this->getBridgeDir() . '/state/' . $uid . '.json';
		if ($uid !== '' && !str_contains($uid, '/') && $this->getBridgeDir() !== '' && is_file($file)) {
			$data = json_decode((string)@file_get_contents($file), true);
			foreach (is_array($data) ? $data : [] as $v) {
				if (is_array($v) && is_string($v['file'] ?? null)) {
					$volumes[] = $v;
				}
			}
		}
		return $this->stateCache[$uid] = $volumes;
	}

	/** Whether any user has a mounted volume (cheap: used on every write) */
	public function anyMounted(): bool {
		return $this->getBridgeDir() !== '' && (glob($this->getBridgeDir() . '/state/*.json') ?: []) !== [];
	}

	public function findMounted(string $uid, string $rel): ?array {
		foreach ($this->getMounted($uid) as $v) {
			if ($v['file'] === $rel) {
				return $v;
			}
		}
		return null;
	}

	/* ---------- Volumes in the user's Files ---------- */

	/**
	 * The user's volumes: files with the volume extensions, those added by hand
	 * and those mounted.
	 *
	 * @return list<array{path: string, name: string, size: int, rel: ?string, problem: ?string, manual: bool, mounted: ?array}>
	 */
	public function listVolumes(string $uid): array {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$rows = [];
		$add = function (Node $node, bool $manual) use (&$rows, $userFolder, $uid): void {
			$path = (string)$userFolder->getRelativePath($node->getPath());
			if ($path === '' || isset($rows[$path])) {
				if ($manual && isset($rows[$path])) {
					$rows[$path]['manual'] = true;
				}
				return;
			}
			$rel = null;
			$problem = null;
			try {
				$rel = $this->volumeInfo($uid, $node)['rel'];
			} catch (BridgeException $e) {
				$problem = $e->reason;
			}
			$rows[$path] = [
				'path' => $path,
				'name' => $node->getName(),
				'size' => (int)$node->getSize(),
				'rel' => $rel,
				'problem' => $problem,
				'manual' => $manual,
				'mounted' => $rel !== null ? $this->findMounted($uid, $rel) : null,
			];
		};

		foreach ($this->getExtensions() as $ext) {
			try {
				$found = $userFolder->search('.' . $ext);
			} catch (\Throwable $e) {
				$this->logger->warning('Search for volumes failed', ['app' => self::APP, 'exception' => $e]);
				continue;
			}
			foreach ($found as $node) {
				if (!$node instanceof File || strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION)) !== $ext
					|| $this->isInsideVolume($node)) {
					continue;
				}
				try {
					// Nodes from search() can have a wrong internal path: get them again by path
					$add($userFolder->get((string)$userFolder->getRelativePath($node->getPath())), false);
				} catch (\Throwable $e) {
					$this->logger->info('Volume ' . $node->getPath() . ' skipped', ['app' => self::APP, 'exception' => $e]);
				}
			}
		}
		foreach ($this->getExtra($uid) as $path) {
			try {
				$add($userFolder->get($path), true);
			} catch (NotFoundException $e) {
				$rows[$path] = ['path' => $path, 'name' => basename($path), 'size' => 0, 'rel' => null,
					'problem' => 'missing', 'manual' => true, 'mounted' => null];
			}
		}
		// Mounted volumes not found above (e.g. shown by a stale search index)
		$listed = array_filter(array_column($rows, 'rel'));
		foreach ($this->getMounted($uid) as $v) {
			if (!in_array($v['file'], $listed, true)) {
				$path = '/' . preg_replace('#^files/#', '', (string)($v['ncpath'] ?? $v['file']));
				$rows[$path] = ['path' => $path, 'name' => basename($path), 'size' => 0, 'rel' => $v['file'],
					'problem' => null, 'manual' => false, 'mounted' => $v];
			}
		}
		ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);
		return array_values($rows);
	}

	/**
	 * Where the container finds a file of the user: only files in the user's own
	 * Files on local storage, not encrypted by Nextcloud.
	 *
	 * @return array{rel: string, ncpath: string}
	 * @throws BridgeException
	 */
	public function volumeInfo(string $uid, Node $node): array {
		if (!$node instanceof File) {
			throw new BridgeException('not_a_file');
		}
		if ($node->isEncrypted()) {
			throw new BridgeException('encrypted');
		}
		$storage = $node->getStorage();
		if (!$storage->instanceOfStorage(IHomeStorage::class) || !$storage->isLocal()
			|| $node->getOwner()?->getUID() !== $uid) {
			throw new BridgeException('not_local');
		}
		$local = $storage->getLocalFile($node->getInternalPath());
		$dataDir = realpath((string)$this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data'));
		$real = is_string($local) ? realpath($local) : false;
		if ($dataDir === false || $real === false || !str_starts_with($real, $dataDir . '/')) {
			throw new BridgeException('not_local');
		}
		return ['rel' => substr($real, strlen($dataDir) + 1), 'ncpath' => $node->getInternalPath()];
	}

	/** @throws BridgeException */
	private function getUserFile(string $uid, string $path): File {
		try {
			$node = $this->rootFolder->getUserFolder($uid)->get($path);
		} catch (NotFoundException $e) {
			throw new BridgeException('missing', $path);
		}
		if (!$node instanceof File) {
			throw new BridgeException('not_a_file', $path);
		}
		return $node;
	}

	/* ---------- Mount and unmount ---------- */

	/**
	 * @param string $keyfiles paths in the user's Files, one per line
	 * @return array{dir: string, fs: string, note: string, readonly: bool}
	 * @throws BridgeException
	 */
	public function mount(string $uid, string $path, #[\SensitiveParameter] string $password, int $pim,
		string $keyfiles, bool $readonly): array {
		$node = $this->getUserFile($uid, $path);
		$info = $this->volumeInfo($uid, $node);
		$keys = [];
		foreach (preg_split('/\R/', $keyfiles) ?: [] as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			try {
				$keys[] = $this->volumeInfo($uid, $this->getUserFile($uid, $line))['rel'];
			} catch (BridgeException $e) {
				throw new BridgeException('keyfile_not_found', $line);
			}
		}
		$result = $this->request([
			'action' => 'mount',
			'uid' => $uid,
			'file' => $info['rel'],
			'ncpath' => $info['ncpath'],
			'fileid' => (int)$node->getId(),
			'name' => pathinfo($node->getName(), PATHINFO_FILENAME),
			'password' => $password,
			'pim' => $pim,
			'keyfiles' => $keys,
			'readonly' => $readonly,
		]);
		$this->addToGroup($uid);
		$this->reconcile($uid);
		return [
			'dir' => (string)($result['dir'] ?? ''),
			'fs' => (string)($result['fs'] ?? ''),
			'note' => (string)($result['note'] ?? ''),
			'readonly' => (bool)($result['readonly'] ?? $readonly),
		];
	}

	/** @throws BridgeException */
	public function unmount(string $uid, string $rel, bool $force = false): void {
		if ($this->findMounted($uid, $rel) === null) {
			throw new BridgeException('not_mounted');
		}
		$this->request(['action' => 'unmount', 'uid' => $uid, 'file' => $rel, 'force' => $force]);
		$this->reconcile($uid);
	}

	/** Unmounts everything of a user (e.g. before the user is deleted) */
	public function unmountAll(string $uid): void {
		foreach ($this->getMounted($uid) as $v) {
			try {
				$this->request(['action' => 'unmount', 'uid' => $uid, 'file' => $v['file'], 'force' => true]);
			} catch (BridgeException $e) {
				$this->logger->warning('Unmount of ' . $v['file'] . ' failed: ' . $e->getMessage(), ['app' => self::APP]);
			}
		}
		$this->reconcile($uid);
	}

	/**
	 * Volumes unmounted since the last look (by the user, after a restart of the
	 * container…): their file changed while it was mounted, so Nextcloud has to
	 * read its size and date again, and sync clients download it again.
	 */
	public function reconcile(string $uid): void {
		$known = json_decode($this->config->getUserValue($uid, self::APP, 'known', '[]'), true);
		$known = is_array($known) ? $known : [];
		$now = [];
		foreach ($this->getMounted($uid) as $v) {
			$now[$v['file']] = (string)($v['ncpath'] ?? '');
		}
		foreach ($known as $rel => $ncpath) {
			if (!isset($now[$rel])) {
				$this->refreshFile($uid, (string)$ncpath);
			}
		}
		if ($now != $known) {
			if ($now === []) {
				$this->config->deleteUserValue($uid, self::APP, 'known');
			} else {
				$this->config->setUserValue($uid, self::APP, 'known', json_encode($now, JSON_UNESCAPED_SLASHES));
			}
			$this->setKnownUser($uid, $now !== []);
		}
	}

	/** Users that had mounted volumes at the last look (for the background job) */
	public function getKnownUsers(): array {
		$users = json_decode($this->config->getAppValue(self::APP, 'known_users', '[]'), true);
		return is_array($users) ? array_values(array_filter($users, 'is_string')) : [];
	}

	private function setKnownUser(string $uid, bool $known): void {
		$users = array_diff($this->getKnownUsers(), [$uid]);
		if ($known) {
			$users[] = $uid;
		}
		$this->config->setAppValue(self::APP, 'known_users', json_encode(array_values($users)));
	}

	/** Users with mounted volumes according to the container */
	public function getMountedUsers(): array {
		$users = [];
		foreach (glob($this->getBridgeDir() . '/state/*.json') ?: [] as $file) {
			$users[] = basename($file, '.json');
		}
		return $users;
	}

	private function refreshFile(string $uid, string $ncpath): void {
		if (!str_starts_with($ncpath, 'files/')) {
			return;
		}
		try {
			$node = $this->rootFolder->getUserFolder($uid)->get(substr($ncpath, strlen('files/')));
			$node->touch($node->getStorage()->filemtime($node->getInternalPath()) ?: null);
		} catch (\Throwable $e) {
			$this->logger->info('Refresh of the volume file ' . $ncpath . ' failed', ['app' => self::APP, 'exception' => $e]);
		}
	}

	/** Unmounts the volumes mounted for longer than max_hours */
	public function autoUnmount(string $uid): void {
		$hours = $this->getMaxHours();
		if ($hours === 0) {
			return;
		}
		foreach ($this->getMounted($uid) as $v) {
			if ((int)($v['since'] ?? time()) > time() - $hours * 3600) {
				continue;
			}
			try {
				$this->request(['action' => 'unmount', 'uid' => $uid, 'file' => $v['file'], 'force' => false]);
				$this->logError($uid, $this->describe('auto_unmounted', (string)$hours), $this->displayPath($v));
			} catch (BridgeException $e) {
				$this->logError($uid, $this->l->t('Automatic unmount failed: %s', [$this->describe($e->reason, $e->detail)]), $this->displayPath($v));
			}
		}
		$this->reconcile($uid);
	}

	/** Path of a mounted volume as the user sees it in Files */
	public function displayPath(array $v): string {
		return '/' . preg_replace('#^files/#', '', (string)($v['ncpath'] ?? $v['file']));
	}

	/* ---------- Protection of mounted volumes ---------- */

	/**
	 * The mounted volume that is this node, or inside this folder: while mounted
	 * its file must not be changed, moved, copied or deleted.
	 */
	public function mountedVolumeOf(Node $node): ?array {
		try {
			// The owner of the file also when it is reached through a share
			$owner = $node->getStorage()->getOwner($node->getInternalPath());
			if (!is_string($owner) || $owner === '') {
				return null;
			}
			foreach ($this->getMounted($owner) as $v) {
				$id = (int)($v['fileid'] ?? 0);
				if ($id <= 0) {
					continue;
				}
				if ($node instanceof File ? $node->getId() === $id : $this->folderContains($node, $id)) {
					return $v + ['owner' => $owner];
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Mounted volume check failed', ['app' => self::APP, 'exception' => $e]);
		}
		return null;
	}

	private function folderContains(Node $folder, int $id): bool {
		if (!$folder instanceof Folder) {
			return false;
		}
		if (method_exists($folder, 'getFirstNodeById')) {
			return $folder->getFirstNodeById($id) !== null;
		}
		return $folder->getById($id) !== [];
	}

	/** Whether a node is inside the "VeraCrypt" folder, i.e. inside a mounted volume */
	public function isInsideVolume(Node $node): bool {
		try {
			$mount = $node->getMountPoint();
			$id = $this->getStorageId();
			if ($id > 0 && method_exists($mount, 'getStorageConfig')) {
				return $mount->getStorageConfig()->getId() === $id;
			}
			return (bool)preg_match('#^/[^/]+/files/' . preg_quote(trim($this->getMountName(), '/'), '#') . '/$#', $mount->getMountPoint());
		} catch (\Throwable $e) {
			return false;
		}
	}

	/* ---------- Files added by hand (any extension) ---------- */

	/** @return list<string> */
	public function getExtra(string $uid): array {
		$list = json_decode($this->config->getUserValue($uid, self::APP, 'extra', '[]'), true);
		return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
	}

	/** @throws BridgeException */
	public function addExtra(string $uid, string $path): string {
		$path = '/' . trim($path, " \t/");
		$node = $this->getUserFile($uid, $path);
		$this->volumeInfo($uid, $node);
		$path = (string)$this->rootFolder->getUserFolder($uid)->getRelativePath($node->getPath());
		$list = $this->getExtra($uid);
		if (!in_array($path, $list, true)) {
			$list[] = $path;
			$this->config->setUserValue($uid, self::APP, 'extra', json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}
		return $path;
	}

	public function removeExtra(string $uid, string $path): void {
		$list = array_values(array_diff($this->getExtra($uid), [$path]));
		if ($list === []) {
			$this->config->deleteUserValue($uid, self::APP, 'extra');
		} else {
			$this->config->setUserValue($uid, self::APP, 'extra', json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}
	}

	/* ---------- Group "VeraCrypt" ---------- */

	private function addToGroup(string $uid): void {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return;
		}
		$group = $this->groupManager->get(self::GROUP_ID) ?? $this->groupManager->createGroup(self::GROUP_ID);
		if ($group !== null && !$group->inGroup($user)) {
			$group->addUser($user);
		}
	}

	/* ---------- Error log (shown in the settings) ---------- */

	public function logError(string $uid, string $message, string $path = ''): void {
		if ($uid === '') {
			return;
		}
		$message = mb_substr(trim($message), 0, 600);
		$errors = $this->getOwnErrors($uid);
		$last = $errors[0] ?? null;
		if ($last !== null && $last['message'] === $message && $last['path'] === $path) {
			$errors[0]['time'] = time();
			$errors[0]['count']++;
		} else {
			array_unshift($errors, ['time' => time(), 'message' => $message, 'path' => $path, 'count' => 1]);
			$errors = array_slice($errors, 0, self::MAX_ERRORS);
		}
		$this->config->setUserValue($uid, self::APP, 'errors', json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	private function getOwnErrors(string $uid): array {
		$data = json_decode($this->config->getUserValue($uid, self::APP, 'errors', '[]'), true);
		return is_array($data) ? array_values($data) : [];
	}

	/** @return list<array{time: int, message: string, path: string, count: int}> */
	public function getErrors(string $uid): array {
		$errors = array_merge($this->getOwnErrors($uid), $this->getServiceErrors($uid));
		usort($errors, fn (array $a, array $b) => $b['time'] <=> $a['time']);
		return array_slice($errors, 0, self::MAX_ERRORS);
	}

	/** Events written by the container: "time<TAB>code<TAB>file<TAB>detail" */
	private function getServiceErrors(string $uid): array {
		$file = $this->getBridgeDir() . '/errors/' . $uid . '.log';
		if (str_contains($uid, '/') || !is_file($file)) {
			return [];
		}
		$errors = [];
		foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
			$parts = explode("\t", $line, 4);
			if (count($parts) < 3) {
				continue;
			}
			$errors[] = [
				'time' => (int)$parts[0],
				'message' => $this->describe($parts[1], $parts[3] ?? ''),
				'path' => '/' . preg_replace('#^[^/]+/files/#', '', $parts[2]),
				'count' => 1,
			];
		}
		return $errors;
	}

	public function clearErrors(string $uid): void {
		$this->config->deleteUserValue($uid, self::APP, 'errors');
		$file = $this->getBridgeDir() . '/errors/' . $uid . '.log';
		if (!str_contains($uid, '/') && is_file($file)) {
			@unlink($file);
		}
	}

	/** Explains an error code of the container or of the app, in the user's language */
	public function describe(string $code, string $detail = ''): string {
		$messages = [
			'not_configured' => $this->l->t('The app is not configured: run install.sh on the server.'),
			'unreachable' => $this->l->t('The VeraCrypt service is not reachable: is the vc4nc-bridge container running?'),
			'timeout' => $this->l->t('The VeraCrypt service did not answer in time.'),
			'bad_request' => $this->l->t('Invalid request.'),
			'internal' => $this->l->t('Internal error of the VeraCrypt service.'),
			'missing' => $this->l->t('The file does not exist (any more).'),
			'not_a_file' => $this->l->t('This is not a file.'),
			'not_found' => $this->l->t('The VeraCrypt service cannot find the file in your Files.'),
			'not_local' => $this->l->t('Only files in your own Files can be mounted, not files shared with you or on external storage.'),
			'encrypted' => $this->l->t('The file is encrypted by Nextcloud (server-side encryption): it cannot be opened as a volume.'),
			'keyfile_not_found' => $this->l->t('Keyfile not found or not usable: %s', [$detail]),
			'keyfile_comma' => $this->l->t('Keyfile paths cannot contain commas: %s', [$detail]),
			'already_mounted' => $this->l->t('This volume is already mounted.'),
			'not_mounted' => $this->l->t('This volume is not mounted.'),
			'in_progress' => $this->l->t('Another operation on this volume is in progress: wait a moment and try again.'),
			'no_slot' => $this->l->t('Too many volumes are mounted on the server.'),
			'wrong_password' => $this->l->t('Wrong password, PIM or keyfiles, or the file is not a VeraCrypt volume.'),
			'no_password' => $this->l->t('Enter the password of the volume, or its keyfiles.'),
			'veracrypt_failed' => $this->l->t('VeraCrypt could not open the volume.'),
			'no_filesystem' => $this->l->t('The volume has no filesystem (created with “None”?): format it with VeraCrypt on your computer.'),
			'unsupported_fs' => $this->l->t('The filesystem of the volume (%s) is not supported by the server.', [$detail]),
			'fs_dirty' => $this->l->t('The filesystem of the volume has errors or was not closed properly (Windows Fast Startup or hibernation?): repair it on your computer, or mount it read-only.'),
			'mount_failed' => $this->l->t('The filesystem of the volume could not be mounted.'),
			'busy' => $this->l->t('The volume is in use: wait for running uploads or downloads to finish and try again, or unmount it by force.'),
			'service_restarted' => $this->l->t('Unmounted because the VeraCrypt service restarted (server reboot or update): mount it again.'),
			'service_stopped' => $this->l->t('Unmounted because the VeraCrypt service was stopped.'),
			'lost' => $this->l->t('The volume was unmounted outside Nextcloud.'),
			'not_responding' => $this->l->t('The mounted volume is not responding: unmount it by force and mount it again.'),
			'auto_unmounted' => $this->l->n('Unmounted automatically after %n hour.', 'Unmounted automatically after %n hours.', (int)$detail),
		];
		$message = $messages[$code] ?? $this->l->t('Error: %s', [$code]);
		$withDetail = ['veracrypt_failed', 'mount_failed', 'fs_dirty', 'internal', 'unreachable'];
		if ($detail !== '' && (in_array($code, $withDetail, true) || !isset($messages[$code]))) {
			$message .= ' (' . mb_substr(preg_replace('/\s+/', ' ', $detail), 0, 300) . ')';
		}
		return $message;
	}

	/* ---------- Messages for the settings page ---------- */

	public function setFlash(string $uid, string $type, string $text): void {
		$this->config->setUserValue($uid, self::APP, 'flash', json_encode(['type' => $type, 'text' => $text]));
	}

	public function popFlash(string $uid): ?array {
		$raw = $this->config->getUserValue($uid, self::APP, 'flash', '');
		if ($raw === '') {
			return null;
		}
		$this->config->deleteUserValue($uid, self::APP, 'flash');
		$data = json_decode($raw, true);
		return is_array($data) ? $data : null;
	}
}
