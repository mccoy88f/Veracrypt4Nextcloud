# VeraCrypt for Nextcloud

*[Italiano](README.it.md)*

Each user mounts the **VeraCrypt volumes they keep in their own Files** (for example
`archive.hc`) and works with their content in the “VeraCrypt” folder, one subfolder per
volume. Mounting, unmounting and the error log are in Personal settings → VeraCrypt.

```
Personal settings → VeraCrypt ── "Mount": password, PIM, keyfiles ──▶ Nextcloud app (veracryptbridge)
                                                                          │ socket in the data folder
                                                                          ▼
              vc4nc-bridge container (official VeraCrypt) opens the .hc file of the user
                                                                          │ mount in /mnt/vc/<user>/<volume>
                                                                          ▼
                                  External storage "VeraCrypt" (Local, /veracrypt/$user)
                                                                          │
                                                                          ▼
                                                 Files → VeraCrypt → <volume>
```

## Install, update, uninstall

From the **server terminal** (in Coolify: *Servers → your server → Terminal*), not from the
Nextcloud container terminal:
```bash
curl -fsSL https://raw.githubusercontent.com/mccoy88f/Veracrypt4Nextcloud/main/install.sh | sudo bash -s -- install
```
The script asks no questions: what to do is given at the end of the command.

| End of the command | What it does |
|---|---|
| `install` | installs or updates the app, the VeraCrypt container and the “VeraCrypt” folder |
| `uninstall` | removes app and container (mounted volumes are unmounted first), keeps the users' lists and error logs |
| `uninstall purge` | removes everything this project created |

You can add `en` / `it` (language of the messages; by default the system language,
otherwise English) and the name of the Nextcloud container if the server has more than
one, e.g. `... | sudo bash -s -- install it`. **The volume files in the users' Files are
never touched**, not even by `purge`.

What the script does: finds the Nextcloud container (official or linuxserver image), builds
the `vc4nc-bridge` image with the **official VeraCrypt console package** (checked against its
SHA-256), installs and enables the app, starts the container and creates the “VeraCrypt”
folder with the official *External storage* app (type Local, only for the “VeraCrypt” group,
which users join when they mount their first volume). It can be run again at any time, for
example to update.

### One line in the Nextcloud compose
The mounted volumes reach Nextcloud through a folder of the server, which needs **one line in
the Nextcloud compose**, added once (it stays after updates). Run `install` first: it prepares
the folder on the host and, if the line is missing, shows it. Then in Coolify: Nextcloud
resource → *Edit Compose File* → in the **`nextcloud`** service (not the database), under
`volumes:`
```yaml
      - '/data/veracrypt4nextcloud/mnt:/veracrypt:rslave'
```
save, *Restart* the resource and run `install` again. (With docker compose or other
managers: same volume, then recreate the container.)

### Requirements
- A Linux server with Docker, **x86_64 or ARM64** (VeraCrypt packages exist for these).
- FUSE on the host (`/dev/fuse`, present on almost every server).
- The container runs **privileged** (`--privileged`): VeraCrypt needs loop devices,
  device-mapper and FUSE. It has **no network** (`--network none`): it only talks to
  Nextcloud through a socket in the data folder, which only Nextcloud's user can open.
- If the kernel offers dm-crypt, volumes are decrypted by the kernel (fast); otherwise VeraCrypt
  decrypts them in user space (FUSE). The container tests this by itself at startup
  (`docker logs vc4nc-bridge` says which one is used).
- **Not compatible with Nextcloud server-side encryption** of the home folders: the files on
  disk are encrypted by Nextcloud, VeraCrypt cannot open them (the script warns about it).

## Use (each user)
1. Upload the VeraCrypt volume to Files. Files ending in `.hc` or `.vc` are listed by
   themselves; a volume with another name can be added with its path.
2. Personal settings → **VeraCrypt** → *Mount…*: password, PIM (only if you set one),
   keyfiles (paths in your Files, optional), read-only if you wish. Opening takes from one
   second to half a minute: VeraCrypt tries every algorithm.
3. The content is in Files → **VeraCrypt** → *volume name*. Work with it as with any folder.
4. *Unmount* when you are done.

The page also shows:
- **Error log**: wrong password, filesystem to repair, volume in use, volumes unmounted
  because the service restarted…, explained, with repeated errors grouped;
- the state of every volume (mounted where, which filesystem, since when).

The app is in English with an Italian translation: everyone sees it in the language of their
Nextcloud profile.

### Supported volumes
- Volumes and **hidden volumes** created with VeraCrypt (enter the password of the hidden
  volume to open it), with password, PIM and keyfiles, any algorithm supported by VeraCrypt.
- Filesystems: **FAT, exFAT, NTFS, ext2/3/4** and the other Linux filesystems known to the
  server kernel. Linux filesystems are shown to Nextcloud through bindfs, as Nextcloud's user.
- Not supported: old TrueCrypt volumes (no longer opened by VeraCrypt 1.26), system
  encryption, volumes on devices/partitions (only files in Files).
- NTFS volumes left “dirty” by Windows (Fast Startup, hibernation) may have to be mounted
  read-only, or repaired in Windows.

## Security: what to know
- **The volume is decrypted on the server.** While it is mounted, whoever administers the
  server (or gains control of it) can read its content. When it is unmounted only the
  encrypted file remains. This is the price of using the volume from the browser.
- The password goes from the browser to Nextcloud (use HTTPS) and from Nextcloud to VeraCrypt
  through the socket, on standard input: it is **never written to disk** or on a command line,
  and it is not stored. Wrong passwords are slowed down by Nextcloud's brute-force protection.
- While a volume is mounted **its file cannot be changed, moved, copied or deleted** (VeraCrypt
  is writing into it): Nextcloud refuses those operations. Do not modify the file by other
  means (e.g. directly on the server's disk) while it is mounted.
- The content of the volumes does not leak out of them: **no trash bin** (a file deleted from
  a volume is deleted for good), **no versions**, **no previews** and **no sharing** for the
  “VeraCrypt” folder. Activity, notifications and full-text search apps, if installed, may
  still record file names.
- After an unmount the volume file has a new date, so Nextcloud and the sync clients see that
  it changed. The desktop client may sync the volume file while it is mounted: the copy can be
  inconsistent until it is unmounted and synced again. If you sync your Files to a computer,
  consider excluding the volumes, or unmount them before opening them there.
- After a server reboot, an update or a restart of the container, all volumes are unmounted
  (the passwords are not kept): users mount them again. The error log says so.

## Options
To run on the server (with the linuxserver image use `-u 1000` or the right user and
`/app/www/public/occ`):
```bash
occ config:app:set veracryptbridge max_hours --value=8        # unmount volumes automatically after 8 hours (0: never, default)
occ config:app:set veracryptbridge extensions --value=hc,vc   # extensions of the files listed as volumes
occ config:app:set veracryptbridge mount_name --value=VeraCrypt   # name of the folder (run install.sh again after changing it)
```
Variables for `install.sh`: `VC_KERNEL_CRYPTO=no` (always decrypt in user space),
`BASE_DIR=/path` (host folder for the mount points), `REF=branch` (version to install),
`SRC_DIR=/path` (install from a local copy), `BUILD=0` (use an image already built).
To build the image on a server without internet access, put the VeraCrypt package in
`server/` as `veracrypt.deb` and use `SRC_DIR`.

## Common problems
- **The “VeraCrypt” folder is empty although the volume is mounted**: the line in the compose
  is missing or Nextcloud was not restarted after adding it. Run `install` again: it says so.
- **“The VeraCrypt service is not reachable”**: `docker ps` / `docker logs vc4nc-bridge`.
- **Wrong password, but it is right**: check the PIM (empty if you never set one) and the
  keyfiles; passwords with accented letters work as on Linux and macOS (UTF-8).
- **“The filesystem has errors”**: mount it read-only, or repair it on a computer
  (chkdsk on Windows, fsck on Linux).
- **“The volume is in use”** when unmounting: wait for uploads/downloads to finish, or tick
  *by force*.

## License and contributions
The project is released under the **[CC BY-NC 4.0](LICENSE)** license (Creative Commons
Attribution-NonCommercial 4.0):
- ✅ free use, sharing and modification for **non-commercial** purposes;
- ✅ forks are welcome, as long as they **credit the original project**
  (link to https://github.com/mccoy88f/Veracrypt4Nextcloud) and state what was changed;
- ❌ no commercial use without the author's permission.

The software it relies on keeps **its own licenses, which must be respected too**:
VeraCrypt (Apache 2.0 and TrueCrypt License 3.0), Nextcloud (AGPL v3) and the tools in the
container image. Details in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

Born from the same idea as [gdrive-rc-connector](https://github.com/mccoy88f/gdrive-rc-connector).
**Improvements are welcome!** Open an issue for bugs and ideas, or a pull request with your
changes.
