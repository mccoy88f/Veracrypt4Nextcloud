<?php

declare(strict_types=1);

namespace OCA\VeraCryptBridge\Service;

/**
 * Error of the VeraCrypt service or of a volume, with a code that
 * VolumeService::describe() explains to the user (e.g. "wrong_password").
 */
class BridgeException extends \RuntimeException {
	public function __construct(
		public readonly string $reason,
		public readonly string $detail = '',
	) {
		parent::__construct($reason . ($detail !== '' ? ': ' . $detail : ''));
	}
}
