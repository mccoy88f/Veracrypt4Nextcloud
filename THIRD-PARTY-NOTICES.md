# Third-party software and terms

This project (licensed under [CC BY-NC 4.0](LICENSE)) works together with
third-party software that keeps **its own licenses and terms**. Using, forking or
redistributing this project means respecting those too.

## VeraCrypt — Apache License 2.0 and TrueCrypt License 3.0
- Copyright (c) IDRIX and the VeraCrypt contributors; parts derived from TrueCrypt.
- https://veracrypt.io — license: https://veracrypt.io/en/VeraCrypt%20License.html
- VeraCrypt is **not included** in this repository: when `install.sh` builds the
  container image on the server, the official VeraCrypt console package is downloaded
  from the VeraCrypt releases on GitHub and checked against the SHA-256 written in
  `server/Dockerfile`. If you redistribute that image, or VeraCrypt in another form,
  you must respect the VeraCrypt license (including the TrueCrypt License 3.0 for the
  parts derived from TrueCrypt).
- The non-commercial restriction of this project applies only to the code in this
  repository, not to VeraCrypt.

## Tools in the container image
Installed from the Ubuntu 24.04 LTS archive when the image is built, each under its own
license: socat (GPL-2.0), jq (MIT), tini (MIT), bindfs (GPL-2.0+), fusefat (GPL-2.0+),
exfatprogs and exfat-fuse (GPL-2.0+), ntfs-3g (GPL-2.0+), e2fsprogs and dosfstools
(GPL-2.0+/GPL-3.0+), util-linux, kmod, psmisc, procps, fuse3 and dmsetup (GPL/LGPL).
The base image is the official `ubuntu:24.04` Docker image.

## Nextcloud — GNU AGPL v3
- https://nextcloud.com — https://github.com/nextcloud/server
- The `veracryptbridge` app runs inside Nextcloud and uses its APIs. Nextcloud is
  licensed under the GNU Affero General Public License v3 (or later): its terms apply
  to Nextcloud and to its official apps (such as External storage) used by this project.

---

This project is not affiliated with, endorsed or sponsored by IDRIX, the VeraCrypt
project or Nextcloud GmbH. "VeraCrypt" is a trademark of IDRIX; "Nextcloud" is a
trademark of Nextcloud GmbH.
