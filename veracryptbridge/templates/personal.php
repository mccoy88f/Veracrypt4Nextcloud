<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="veracryptbridge" class="section">
	<h2>VeraCrypt</h2>
	<p class="settings-hint">
		<?php p($l->t('Mount the VeraCrypt volumes you keep in your Files: their content appears in Files in the folder “%s”, one subfolder per volume.', [$_['mountName']])); ?>
		<?php p($l->t('Unmount them when you are done: until then the volume is open on the server.')); ?>
	</p>

	<?php if (!empty($_['flash'])): ?>
		<p class="vcb-msg <?php p(($_['flash']['type'] ?? '') === 'error' ? 'vcb-error' : 'vcb-ok'); ?>">
			<?php p($_['flash']['text'] ?? ''); ?>
		</p>
	<?php endif; ?>

	<?php if (!$_['configured']): ?>
		<p class="vcb-msg vcb-error"><?php p($l->t('The app is not configured: run install.sh on the server.')); ?></p>
	<?php elseif ($_['service'] === null): ?>
		<p class="vcb-msg vcb-error"><?php p($l->t('The VeraCrypt service is not reachable: is the vc4nc-bridge container running?')); ?>
			<?php p($l->t('Volumes cannot be mounted or unmounted until it is back.')); ?></p>
	<?php endif; ?>

	<h3><?php p($l->t('Your volumes')); ?></h3>
	<?php if (empty($_['volumes'])): ?>
		<p class="settings-hint">
			<?php p($l->t('No volumes found. Upload your VeraCrypt volume to Files (files ending in %s are listed here by themselves), or add a file with another name below.', [implode(', ', array_map(fn ($e) => '.' . $e, $_['extensions']))])); ?>
		</p>
	<?php else: ?>
		<table class="vcb-volumes">
			<thead><tr><th><?php p($l->t('File')); ?></th><th><?php p($l->t('State')); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ($_['volumes'] as $v): ?>
				<tr>
					<td>
						<strong><?php p($v['name']); ?></strong>
						<div class="vcb-hint"><?php p($v['path']); ?><?php if ($v['size'] > 0): ?> · <?php p(\OCP\Util::humanFileSize($v['size'])); ?><?php endif; ?></div>
					</td>
					<td>
						<?php if ($v['mounted'] !== null): ?>
							<span class="vcb-badge vcb-badge-on"><?php p($l->t('Mounted')); ?></span>
							<div class="vcb-hint">
								<a href="<?php p($_['filesUrl']); ?>"><?php p($_['mountName'] . '/' . $v['mounted']['dir']); ?></a>
								· <?php p($v['mounted']['fs'] ?? ''); ?><?php if (!empty($v['mounted']['readonly'])): ?> · <?php p($l->t('read-only')); ?><?php endif; ?>
								· <?php p($l->t('since %s', [$v['mounted']['when']])); ?>
							</div>
						<?php elseif ($v['problem'] !== null): ?>
							<span class="vcb-badge vcb-badge-err"><?php p($l->t('Cannot be mounted')); ?></span>
							<div class="vcb-hint"><?php p($v['problemText']); ?></div>
						<?php else: ?>
							<span class="vcb-badge"><?php p($l->t('Not mounted')); ?></span>
						<?php endif; ?>
					</td>
					<td class="vcb-actions">
						<?php if ($v['mounted'] !== null): ?>
							<form method="post" action="<?php p($_['unmountUrl']); ?>">
								<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
								<input type="hidden" name="file" value="<?php p($v['rel']); ?>">
								<button type="submit" class="button primary"><?php p($l->t('Unmount')); ?></button>
								<label class="vcb-check" title="<?php p($l->t('Unmount even if files are still open: use it only if the normal unmount says the volume is in use.')); ?>">
									<input type="checkbox" name="force" value="1"> <?php p($l->t('by force')); ?>
								</label>
							</form>
						<?php elseif ($v['problem'] === null): ?>
							<details class="vcb-mount">
								<summary class="button"><?php p($l->t('Mount…')); ?></summary>
								<form method="post" action="<?php p($_['mountUrl']); ?>" class="vcb-form">
									<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
									<input type="hidden" name="path" value="<?php p($v['path']); ?>">
									<label><?php p($l->t('Password')); ?>
										<input type="password" name="password" autocomplete="off"></label>
									<label><?php p($l->t('PIM (only if you set one)')); ?>
										<input type="text" name="pim" inputmode="numeric" pattern="[0-9]*" autocomplete="off"></label>
									<label><?php p($l->t('Keyfiles (optional): paths in your Files, one per line')); ?>
										<textarea name="keyfiles" rows="2" placeholder="/Documents/key.jpg"></textarea></label>
									<label class="vcb-check"><input type="checkbox" name="readonly" value="1"> <?php p($l->t('Read-only')); ?></label>
									<div><button type="submit" class="button primary"><?php p($l->t('Mount')); ?></button></div>
									<p class="vcb-hint"><?php p($l->t('Opening can take up to half a minute: VeraCrypt tries every algorithm. With a wrong password it takes longest.')); ?></p>
								</form>
							</details>
						<?php endif; ?>
						<?php if ($v['manual'] && $v['mounted'] === null): ?>
							<form method="post" action="<?php p($_['removeUrl']); ?>">
								<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
								<input type="hidden" name="path" value="<?php p($v['path']); ?>">
								<button type="submit" class="button"><?php p($l->t('Remove from list')); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<form method="post" action="<?php p($_['addUrl']); ?>" class="vcb-add">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
		<label for="vcb-add-path"><?php p($l->t('Volume with another name (path in your Files)')); ?></label>
		<div class="vcb-row">
			<input id="vcb-add-path" type="text" name="path" placeholder="/Documents/archive.bin" required>
			<button type="submit" class="button"><?php p($l->t('Add')); ?></button>
		</div>
	</form>

	<h3><?php p($l->t('Error log')); ?></h3>
	<?php if (empty($_['errors'])): ?>
		<p class="settings-hint"><?php p($l->t('No errors recorded.')); ?></p>
	<?php else: ?>
		<p class="settings-hint"><?php p($l->t('The latest problems with your volumes, most recent first. If an error repeats, the number in brackets says how many times.')); ?></p>
		<table class="vcb-errors">
			<thead><tr><th><?php p($l->t('When')); ?></th><th><?php p($l->t('Volume')); ?></th><th><?php p($l->t('Error')); ?></th></tr></thead>
			<tbody>
			<?php foreach ($_['errors'] as $e): ?>
				<tr>
					<td class="vcb-nowrap"><?php p($e['when']); ?><?php if ($e['count'] > 1): ?> (<?php p($e['count']); ?>×)<?php endif; ?></td>
					<td><?php p($e['path'] !== '' ? $e['path'] : '—'); ?></td>
					<td><?php p($e['message']); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php p($_['clearErrorsUrl']); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
			<button type="submit" class="button"><?php p($l->t('Clear log')); ?></button>
		</form>
	<?php endif; ?>

	<details class="vcb-guide">
		<summary><?php p($l->t('Good to know')); ?></summary>
		<ul>
			<li><?php p($l->t('The volume is decrypted on the server: while it is mounted, whoever administers the server can read its content. When it is unmounted only the encrypted file remains.')); ?></li>
			<li><?php p($l->t('While a volume is mounted its file cannot be changed, moved, copied or deleted, and the desktop client may download a copy that is not up to date: use the content of the volume from the “%s” folder.', [$_['mountName']])); ?></li>
			<li><?php p($l->t('Files deleted from a mounted volume are deleted for good (they do not go to the trash bin), and no versions or previews are kept for them outside the volume.')); ?></li>
			<li><?php p($l->t('Hidden volumes: enter the password of the hidden volume to open it instead of the outer one.')); ?></li>
			<li><?php p($l->t('Supported filesystems: FAT, exFAT, NTFS, ext2/3/4 and the other Linux filesystems known to the server. NTFS volumes used with Windows Fast Startup may have to be mounted read-only.')); ?></li>
			<li><?php p($l->t('After a restart of the server or of the VeraCrypt service the volumes are unmounted: the password is not stored, so you have to mount them again.')); ?></li>
			<?php if ($_['maxHours'] > 0): ?>
				<li><?php p($l->n('Volumes are unmounted automatically %n hour after being mounted.', 'Volumes are unmounted automatically %n hours after being mounted.', $_['maxHours'])); ?></li>
			<?php endif; ?>
			<?php if (!empty($_['service']['veracrypt'])): ?>
				<li><?php p($l->t('Service: %s.', [$_['service']['veracrypt']])); ?></li>
			<?php endif; ?>
		</ul>
	</details>
</div>
