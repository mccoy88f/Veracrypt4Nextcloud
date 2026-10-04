# =============================================================================
#  VeraCrypt for Nextcloud - the container that mounts the volumes.
#
#  Built by install.sh on the server, on Ubuntu 24.04 LTS. It contains the
#  official VeraCrypt console package for Ubuntu 24.04 (checked against its
#  SHA-256, the packages are signed by IDRIX) and the tools to mount the
#  filesystems found inside the volumes (FAT, exFAT, NTFS, ext2/3/4 and the
#  others supported by the kernel).
#
#  To use a package downloaded beforehand (offline servers), put it in this
#  folder as veracrypt.deb: it is still checked against the SHA-256 below.
# =============================================================================
FROM ubuntu:24.04

ARG VC_VERSION=1.26.24
ARG VC_SHA256_AMD64=0da064cde572ece5316368e7dca84c4c8cc8b7327da921c48187998b8725d4a2
ARG VC_SHA256_ARM64=582f6dbcba0171b41ea76b5cfdb71499bc4da8ef003fc13701f0bef027a83ff0

# veracrypt.de[b]: copied only if present
COPY Dockerfile veracrypt.de[b] /build/

RUN set -eu; \
	export DEBIAN_FRONTEND=noninteractive; \
	apt-get update; \
	apt-get install -y --no-install-recommends \
		ca-certificates curl tini socat jq kmod procps psmisc util-linux mount \
		fuse3 bindfs dmsetup e2fsprogs dosfstools fusefat exfatprogs exfat-fuse ntfs-3g; \
	arch=$(dpkg --print-architecture); \
	case "$arch" in \
		amd64) sum="$VC_SHA256_AMD64" ;; \
		arm64) sum="$VC_SHA256_ARM64" ;; \
		*) echo "Unsupported architecture: $arch (VeraCrypt packages exist for amd64 and arm64)" >&2; exit 1 ;; \
	esac; \
	deb=/build/veracrypt.deb; \
	if [ ! -f "$deb" ]; then \
		f="veracrypt-console-$VC_VERSION-Ubuntu-24.04-$arch.deb"; \
		curl -fsSL -o "$deb" "https://github.com/veracrypt/VeraCrypt/releases/download/VeraCrypt_$VC_VERSION/$f"; \
	fi; \
	echo "$sum  $deb" | sha256sum -c -; \
	apt-get install -y --no-install-recommends "$deb"; \
	rm -rf /build; \
	apt-get purge -y curl; \
	apt-get autoremove -y; \
	rm -rf /var/lib/apt/lists/*; \
	veracrypt --text --version

# UTF-8, as on desktop Linux: passwords with accented letters work the same way.
# DM_DISABLE_UDEV: there is no udev in the container, device-mapper creates the
# device nodes itself instead of waiting for udev.
ENV LC_ALL=C.UTF-8 LANG=C.UTF-8 DM_DISABLE_UDEV=1

COPY vc-bridge.sh /usr/local/bin/vc-bridge.sh
RUN chmod 755 /usr/local/bin/vc-bridge.sh

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/vc-bridge.sh"]
