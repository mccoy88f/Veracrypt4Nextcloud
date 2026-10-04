<?php

declare(strict_types=1);

namespace OCA\VeraCryptBridge\Listener;

use OC\Files\Filesystem;
use OCA\VeraCryptBridge\Service\VolumeService;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\L10N\IFactory;
use Psr\Container\ContainerInterface;

/**
 * Refuses writes to the file of a mounted volume (an upload over it, an editor
 * saving it…): VeraCrypt is writing into it, the volume would be destroyed.
 *
 * Writes cannot be cancelled through BeforeNodeWrittenEvent (Nextcloud logs the
 * exception and goes on writing), only through the "run" flag of the old
 * "write" hook, which every write through Nextcloud passes.
 */
class WriteGuard {
	public function __construct(
		private ContainerInterface $container,
	) {
	}

	/** @param array{path?: string, run?: bool} $params "run" is a reference */
	public function onWrite(array $params): void {
		$path = (string)($params['path'] ?? '');
		if ($path === '') {
			return;
		}
		$volumes = $this->container->get(VolumeService::class);
		if (!$volumes->anyMounted()) {
			return;
		}
		try {
			$absolute = Filesystem::getView()?->getAbsolutePath($path);
			if ($absolute === null) {
				return;
			}
			$node = $this->container->get(IRootFolder::class)->get($absolute);
		} catch (NotFoundException $e) {
			// A new file: nothing to protect
			return;
		} catch (\Throwable $e) {
			return;
		}
		$volume = $volumes->mountedVolumeOf($node);
		if ($volume === null) {
			return;
		}
		$params['run'] = false;
		$volumes->logError($volume['owner'], $this->container->get(IFactory::class)->get('veracryptbridge')->t(
			'“%s” is a mounted VeraCrypt volume: it cannot be changed, moved, copied or deleted until you unmount it.',
			[$volumes->displayPath($volume)]), $volumes->displayPath($volume));
	}
}
