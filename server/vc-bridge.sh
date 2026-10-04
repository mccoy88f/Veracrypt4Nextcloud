#!/bin/bash
# =============================================================================
#  VeraCrypt for Nextcloud: runs in the vc4nc-bridge container and mounts the
#  VeraCrypt volumes that users keep in their Nextcloud Files.
#
#   /ncdata                       Nextcloud data folder (the volumes are files in it)
#   /ncdata/.vc-bridge/vc.sock    socket used by the Nextcloud app (only Nextcloud can open it)
#   /ncdata/.vc-bridge/state/     <uid>.json: the user's mounted volumes (no passwords)
#   /ncdata/.vc-bridge/errors/    <uid>.log: events shown in the user's error log
#   /mnt/vc/<uid>/<name>          mount points, seen by Nextcloud as /veracrypt/<uid>/<name>
#
#  Requests arrive on the socket as one JSON line and get one JSON line back:
#    {"action":"mount","uid":…,"file":…,"ncpath":…,"fileid":…,"name":…,"password":…,"pim":…,"keyfiles":[…],"readonly":…}
#    {"action":"unmount","uid":…,"file":…,"force":…}
#    {"action":"status","uid":…}   {"action":"ping"}
#  Passwords are passed to VeraCrypt on stdin: they never reach a disk or the
#  command line. Volumes are not remounted after a restart: they need the password.
#
#  Messages in Italian with VC_LANG=it.
# =============================================================================
set -u

NC_UID="${NC_UID:-33}"
NC_GID="${NC_GID:-33}"
DATA=/ncdata
BRIDGE="$DATA/.vc-bridge"
STATE="$BRIDGE/state"
ERRS="$BRIDGE/errors"
SOCK="$BRIDGE/vc.sock"
MNT=/mnt/vc
RUN=/run/vc4nc
MAX_SLOTS=64
PROTOCOL=1

L() { if [ "${VC_LANG:-en}" = it ]; then printf '%s' "$2"; else printf '%s' "$1"; fi; }
log() { echo "$(date '+%F %T') $*" >&2; }

# --- Helpers -------------------------------------------------------------------

# In /proc/mounts spaces in paths are written as \040
is_mounted() {
	P=$(printf '%s' "$1" | sed 's/\\/\\134/g; s/ /\\040/g; s/\t/\\011/g') \
		awk '$2 == ENVIRON["P"] { f = 1 } END { exit !f }' /proc/mounts
}

# Short key of a volume, for lock files
vkey() { printf '%s' "$1" | sha256sum | cut -c1-16; }

valid_uid() {
	[[ -n "$1" && ${#1} -le 64 && "$1" != *"/"* && "$1" != .* && "$1" != *[[:cntrl:]]* ]]
}

# File in the user's Files: prints its real path, or fails if it is elsewhere
resolve_user_file() {
	local uid="$1" rel="$2" real
	[[ -n "$rel" && "$rel" != *[[:cntrl:]]* ]] || return 1
	real=$(realpath -e -- "$DATA/$rel" 2>/dev/null) || return 1
	[[ "$real" == "$DATA/$uid/files/"* && -f "$real" ]] || return 1
	printf '%s' "$real"
}

state_file() { printf '%s/%s.json' "$STATE" "$1"; }

state_get() {
	local f; f=$(state_file "$1")
	if [[ -s "$f" ]]; then cat "$f"; else echo '[]'; fi
}

# Applies a jq filter to the user's state (under lock), with optional jq arguments
state_update() {
	local uid="$1" filter="$2" f tmp; shift 2
	f=$(state_file "$uid")
	(
		flock 9
		tmp="$f.tmp"
		state_get "$uid" | jq -c "$@" "$filter" > "$tmp" || exit 1
		if [[ "$(cat "$tmp")" == "[]" ]]; then rm -f "$tmp" "$f"; exit 0; fi
		chown "$NC_UID:$NC_GID" "$tmp"; chmod 640 "$tmp"; mv "$tmp" "$f"
	) 9> "$RUN/state.lock"
}

state_find() { state_get "$1" | jq -c --arg f "$2" 'map(select(.file == $f)) | .[0] // empty'; }

# Event for the user's error log: time, code, file, detail (tab separated)
notify() {
	local uid="$1" code="$2" file="$3" detail="${4:-}" f
	valid_uid "$uid" || return 0
	f="$ERRS/$uid.log"
	printf '%s\t%s\t%s\t%s\n' "$(date +%s)" "$code" "$file" "$(printf '%s' "$detail" | tr '\t\n' '  ' | cut -c1-400)" >> "$f"
	tail -n 50 "$f" > "$f.tmp" && chown "$NC_UID:$NC_GID" "$f.tmp" && chmod 640 "$f.tmp" && mv "$f.tmp" "$f"
}

kernel_crypto() { [[ "$(cat "$RUN/kernel_crypto" 2>/dev/null)" == yes ]]; }

# Loop devices: with --privileged the container sees only those that existed when it started
ensure_loops() {
	local i
	[[ -e /dev/loop-control ]] || mknod -m 660 /dev/loop-control c 10 237 2>/dev/null
	for i in $(seq 0 $((MAX_SLOTS * 2 - 1))); do
		[[ -e /dev/loop$i ]] || mknod -m 660 "/dev/loop$i" b 7 "$i" 2>/dev/null
	done
}

# Virtual device of a mounted slot (e.g. /dev/mapper/veracrypt3 or /dev/loop2)
slot_device() {
	veracrypt --text --volume-properties --slot="$1" 2>/dev/null | sed -n 's/^Virtual Device: *//p' | head -n1
}

# First free slot, reserved until released (the mount can take a while)
reserve_slot() {
	(
		flock 9
		local used n
		used=$(veracrypt --text --list 2>/dev/null | sed -n 's/^\([0-9]*\):.*/\1/p')
		used="$used $(cat "$STATE"/*.json 2>/dev/null | jq -r '.[].slot' 2>/dev/null)"
		for n in $(seq 1 $MAX_SLOTS); do
			[[ " $(echo $used) " == *" $n "* || -e "$RUN/slot-$n" ]] && continue
			touch "$RUN/slot-$n"; echo "$n"; exit 0
		done
		exit 1
	) 9> "$RUN/slots.lock"
}
release_slot() { rm -f "$RUN/slot-$1"; }

# Loop devices of a volume: the one of VeraCrypt in user-space mode (its backing
# file is the "volume" file of the FUSE helper) and those on the volume file.
# Detached, or freed by the kernel as soon as they are no longer in use.
release_loops() {
	local dev="$1" file="$2" back l
	if [[ "$dev" == /dev/loop* && -b "$dev" ]]; then
		back=$(losetup -n -O BACK-FILE "$dev" 2>/dev/null | sed 's/ *$//')
		[[ "$back" == /volume || "$back" == */.veracrypt_aux_mnt*/volume ]] && losetup -d "$dev" 2>/dev/null
	fi
	[[ -n "$file" ]] || return 0
	for l in $(losetup -n -O NAME -j "$DATA/$file" 2>/dev/null); do
		losetup -d "$l" 2>/dev/null
	done
	return 0
}

# Dismounts a VeraCrypt slot; with "force" also when it is still busy, and also
# when VeraCrypt no longer knows it (left by a previous run of the container)
vc_dismount() {
	local slot="$1" force="${2:-}" dev="${3:-}" file="${4:-}" i
	[[ -n "$dev" ]] || dev=$(slot_device "$slot")
	for i in $(seq 1 5); do
		veracrypt --text --list --slot="$slot" >/dev/null 2>&1 || break
		if [[ "$force" == force ]]; then
			veracrypt --text --non-interactive --dismount --force --slot="$slot" >/dev/null 2>&1
		else
			veracrypt --text --non-interactive --dismount --slot="$slot" >/dev/null 2>&1
		fi
		veracrypt --text --list --slot="$slot" >/dev/null 2>&1 || break
		sleep 1
	done
	if veracrypt --text --list --slot="$slot" >/dev/null 2>&1; then
		[[ "$force" == force ]] || return 1
	fi
	[[ "$force" == force && "$dev" == /dev/mapper/veracrypt* && -e "$dev" ]] && dmsetup remove --force "${dev#/dev/mapper/}" >/dev/null 2>&1
	release_loops "$dev" "$file"
	return 0
}

# Unmounts a target, trying again for a few seconds; with "force" lazily as a last resort
unmount_path() {
	local p="$1" force="${2:-}" i
	is_mounted "$p" || return 0
	for i in 1 2 3; do
		umount "$p" 2>/dev/null || fusermount3 -u "$p" 2>/dev/null
		is_mounted "$p" || return 0
		sleep 1
	done
	[[ "$force" == force ]] || return 1
	umount -l "$p" 2>/dev/null || fusermount3 -uz "$p" 2>/dev/null
	! is_mounted "$p"
}

# Unmounts a volume described by its state entry
unmount_entry() {
	local entry="$1" force="${2:-}" target raw slot dev file
	target=$(jq -r '.target' <<< "$entry")
	raw=$(jq -r '.raw // ""' <<< "$entry")
	slot=$(jq -r '.slot' <<< "$entry")
	dev=$(jq -r '.device // ""' <<< "$entry")
	file=$(jq -r '.file // ""' <<< "$entry")
	sync
	unmount_path "$target" "$force" || return 1
	if [[ -n "$raw" ]]; then
		unmount_path "$raw" "$force" || return 1
		rmdir "$raw" 2>/dev/null
	fi
	vc_dismount "$slot" "$force" "$dev" "$file" || return 2
	rmdir "$target" 2>/dev/null
	return 0
}

# /mnt/vc/<uid>: Nextcloud can read it but not write it, only the mounted
# volumes inside are writable. Otherwise files uploaded while a volume is
# unmounted would land there in clear, and deleting or renaming the folder of a
# mounted volume in Files would empty the volume.
user_dir() {
	mkdir -p "$MNT/$1"
	chown "0:$NC_GID" "$MNT/$1"
	chmod 750 "$MNT/$1"
}

# Folder name for a volume in /mnt/vc/<uid>: the name asked, " (2)", " (3)"… if taken
pick_dir() {
	local uid="$1" name="$2" d n=2
	name=$(printf '%s' "$name" | tr -d '/\\' | tr '[:cntrl:]' ' ' | sed 's/^[. ]*//; s/[ ]*$//' | cut -c1-80)
	[[ -n "$name" ]] || name=Volume
	d="$name"
	while [[ -e "$MNT/$uid/$d" ]] && { is_mounted "$MNT/$uid/$d" || [[ -n "$(ls -A "$MNT/$uid/$d" 2>/dev/null)" ]] \
		|| state_get "$uid" | jq -e --arg d "$d" 'any(.dir == $d)' >/dev/null; }; do
		d="$name ($n)"; n=$((n + 1))
	done
	printf '%s' "$d"
}

# --- Mounting the filesystem of an opened volume ------------------------------
# Sets FS_RAW (folder of the real mount when it is shown through bindfs),
# FS_NOTE and, on failure, FS_CODE and FS_DETAIL.
mount_fs() {
	local dev="$1" fs="$2" target="$3" ro="$4" raw="$5" o=rw out rc
	local own="uid=$NC_UID,gid=$NC_GID,umask=007"
	[[ "$ro" == true ]] && o=ro
	FS_RAW="" FS_NOTE="" FS_CODE="" FS_DETAIL=""
	case "$fs" in
		vfat|msdos)
			out=$(mount -t vfat -o "$o,$own,utf8,shortname=mixed,flush" "$dev" "$target" 2>&1) && return 0
			# Without the vfat kernel module: FUSE, read-only (its write support is experimental)
			out="$out"$'\n'$(fusefat -o "ro,allow_other,$own" "$dev" "$target" 2>&1 >/dev/null) && is_mounted "$target" && {
				[[ "$o" == rw ]] && FS_NOTE=fat_readonly; return 0; }
			;;
		exfat)
			out=$(mount -t exfat -o "$o,$own" "$dev" "$target" 2>&1) && return 0
			out="$out"$'\n'$(mount.exfat-fuse -o "$o,allow_other,$own" "$dev" "$target" 2>&1) && return 0
			;;
		ntfs)
			out=$(mount -t ntfs3 -o "$o,$own,iocharset=utf8" "$dev" "$target" 2>&1) && return 0
			out="$out"$'\n'$(ntfs-3g -o "$o,allow_other,$own" "$dev" "$target" 2>&1) && return 0
			;;
		*)
			# Linux filesystems keep their own owners and permissions: mounted aside
			# and shown to Nextcloud through bindfs, as its own user
			if [[ "$fs" == ext[234] && "$o" == rw ]]; then
				out=$(e2fsck -p "$dev" 2>&1); rc=$?
				if (( rc >= 4 )); then FS_CODE=fs_dirty; FS_DETAIL="$out"; return 1; fi
			fi
			mkdir -p "$raw"
			if out=$(mount -t "$fs" -o "$o" "$dev" "$raw" 2>&1); then
				if out=$(bindfs -o "$o,allow_other" --force-user="$NC_UID" --force-group="$NC_GID" \
					--perms=u+rwX,g+rwX,o-rwx "$raw" "$target" 2>&1); then
					FS_RAW="$raw"; return 0
				fi
				umount "$raw" 2>/dev/null
			fi
			rmdir "$raw" 2>/dev/null
			;;
	esac
	FS_DETAIL="$out"
	case "$out" in
		*dirty*|*unclean*|*hibernat*|*"Fast Restart"*|*"needs repair"*|*"run fsck"*|*"structure needs cleaning"*) FS_CODE=fs_dirty ;;
		*"unknown filesystem type"*) FS_CODE=unsupported_fs ;;
		*) FS_CODE=mount_failed ;;
	esac
	return 1
}

# --- Requests from Nextcloud ---------------------------------------------------

reply() { jq -cn "$@" '{ok: true} + $ARGS.named'; exit 0; }
fail() {
	jq -cn --arg code "$1" --arg detail "$(printf '%s' "${2:-}" | sed 's/^Error: //' | head -c 600)" \
		'{ok: false, code: $code, detail: $detail}'
	exit 0
}

do_mount() {
	local req="$1" uid file ncpath fileid name pw pim ro real rel kf k kr slot opts out rc dev fs dir target i entry mode
	uid=$(jq -r '.uid // ""' <<< "$req")
	file=$(jq -r '.file // ""' <<< "$req")
	ncpath=$(jq -r '.ncpath // ""' <<< "$req")
	fileid=$(jq -r '.fileid // 0 | tostring' <<< "$req")
	[[ "$fileid" =~ ^[0-9]{1,18}$ ]] || fileid=0
	name=$(jq -r '.name // ""' <<< "$req")
	pim=$(jq -r '.pim // 0 | tostring' <<< "$req")
	ro=$(jq -r 'if .readonly == true then "true" else "false" end' <<< "$req")
	valid_uid "$uid" || fail bad_request "uid"
	[[ "$pim" =~ ^[0-9]{1,7}$ ]] || fail bad_request "PIM"
	real=$(resolve_user_file "$uid" "$file") || fail not_found "$file"
	rel="${real#"$DATA/"}"

	exec 8> "$RUN/vol-$(vkey "$rel").lock"
	flock -n 8 || fail in_progress
	[[ -z "$(state_find "$uid" "$rel")" ]] || fail already_mounted
	veracrypt --text --list "$real" >/dev/null 2>&1 && fail already_mounted

	kf=""
	while IFS= read -r k; do
		[[ -n "$k" ]] || continue
		kr=$(resolve_user_file "$uid" "$k") || fail keyfile_not_found "$k"
		[[ "$kr" != *,* ]] || fail keyfile_comma "$k"
		kf="${kf:+$kf,}$kr"
	done < <(jq -r '.keyfiles // [] | .[]' <<< "$req")

	slot=$(reserve_slot) || fail no_slot
	ensure_loops
	pw=$(jq -r '.password // ""' <<< "$req")
	# ts: VeraCrypt must not restore the old date of the file when it is unmounted,
	# otherwise Nextcloud and the sync clients would not see that it changed
	opts=ts; [[ "$ro" == true ]] && opts=ro
	mode=kernel
	if ! kernel_crypto; then opts="nokernelcrypto${opts:+,$opts}"; mode=fuse; fi
	for i in 1 2; do
		out=$(printf '%s\n' "$pw" | timeout 600 veracrypt --text --non-interactive --stdin --pim="$pim" --keyfiles="$kf" \
			--protect-hidden=no --filesystem=none --slot="$slot" ${opts:+-m="$opts"} --mount "$real" 2>&1 8>&-); rc=$?
		(( rc == 0 )) && break
		# Device-mapper not usable after all: VeraCrypt can also decrypt in user space (FUSE)
		if [[ "$mode" == kernel && "$out" != *"Incorrect password"* ]]; then
			log "$(L "kernel crypto failed, retrying without it" "crittografia del kernel non riuscita, riprovo senza"): $out"
			opts="nokernelcrypto${opts:+,$opts}"; mode=fuse; continue
		fi
		break
	done
	pw=""; req=""
	if (( rc != 0 )); then
		release_slot "$slot"
		case "$out" in
			*"Incorrect password"*) fail wrong_password ;;
			*"No password or keyfile specified"*) fail no_password ;;
			*"already mounted"*) fail already_mounted "$out" ;;
			*) fail veracrypt_failed "$out" ;;
		esac
	fi

	dev=""
	for i in $(seq 1 50); do
		dev=$(slot_device "$slot")
		[[ -n "$dev" && -b "$dev" ]] && break
		[[ "$dev" == /dev/mapper/* ]] && dmsetup mknodes >/dev/null 2>&1
		sleep 0.2
	done
	if [[ -z "$dev" || ! -b "$dev" ]]; then
		vc_dismount "$slot" force; release_slot "$slot"; fail veracrypt_failed "no device for slot $slot"
	fi
	fs=$(blkid -p -o value -s TYPE "$dev" 2>/dev/null)
	if [[ -z "$fs" ]]; then
		vc_dismount "$slot" force; release_slot "$slot"; fail no_filesystem
	fi

	user_dir "$uid"
	dir=$(pick_dir "$uid" "$name")
	target="$MNT/$uid/$dir"
	mkdir -p "$target"; chown "$NC_UID:$NC_GID" "$target"; chmod 770 "$target"
	# FUSE daemons started here must not inherit the socket or the volume lock (fd 8)
	if ! mount_fs "$dev" "$fs" "$target" "$ro" "$RUN/raw/$(vkey "$rel")" < /dev/null 8>&-; then
		rmdir "$target" 2>/dev/null
		vc_dismount "$slot" force; release_slot "$slot"
		[[ "$FS_CODE" == unsupported_fs ]] && FS_DETAIL="$fs"
		fail "$FS_CODE" "$FS_DETAIL"
	fi
	[[ -n "$FS_NOTE" ]] && ro=true

	entry=$(jq -cn --arg uid "$uid" --arg file "$rel" --arg ncpath "$ncpath" --arg dir "$dir" --arg target "$target" \
		--arg raw "$FS_RAW" --arg fs "$fs" --arg dev "$dev" --arg mode "$mode" --argjson slot "$slot" --argjson fileid "$fileid" \
		--argjson readonly "$ro" --argjson since "$(date +%s)" \
		'{uid: $uid, file: $file, ncpath: $ncpath, fileid: $fileid, dir: $dir, target: $target, raw: $raw, fs: $fs,
		  device: $dev, mode: $mode, slot: $slot, readonly: $readonly, since: $since}')
	state_update "$uid" '. + [$e]' --argjson e "$entry"
	release_slot "$slot"
	log "$(L mounted montato): $uid $rel → $dir ($fs, $mode)"
	reply --arg dir "$dir" --arg fs "$fs" --arg note "$FS_NOTE" --argjson readonly "$ro"
}

do_unmount() {
	local req="$1" uid file force entry rc
	uid=$(jq -r '.uid // ""' <<< "$req")
	file=$(jq -r '.file // ""' <<< "$req")
	force=$(jq -r 'if .force == true then "force" else "" end' <<< "$req")
	valid_uid "$uid" || fail bad_request "uid"
	entry=$(state_find "$uid" "$file")
	[[ -n "$entry" ]] || fail not_mounted
	exec 8> "$RUN/vol-$(vkey "$file").lock"
	flock -w 30 8 || fail in_progress
	unmount_entry "$entry" "$force"; rc=$?
	(( rc == 1 )) && fail busy
	(( rc == 2 )) && fail busy "VeraCrypt"
	state_update "$uid" 'map(select(.file != $f))' --arg f "$file"
	log "$(L unmounted smontato): $uid $file"
	reply
}

handle() {
	local req action uid
	IFS= read -r -t 30 req || exit 0
	action=$(jq -r '.action // ""' <<< "$req" 2>/dev/null) || fail bad_request "JSON"
	case "$action" in
		ping)
			reply --arg veracrypt "$(veracrypt --text --version 2>/dev/null | head -n1)" \
				--argjson protocol "$PROTOCOL" --argjson kernel "$(kernel_crypto && echo true || echo false)" ;;
		status)
			uid=$(jq -r '.uid // ""' <<< "$req")
			valid_uid "$uid" || fail bad_request "uid"
			reply --argjson volumes "$(state_get "$uid")" ;;
		mount) do_mount "$req" ;;
		unmount) do_unmount "$req" ;;
		*) fail bad_request "action" ;;
	esac
}

# --- Service ------------------------------------------------------------------

# Volumes left by a previous run (container restarted, server rebooted): their
# passwords are gone, so they are closed and the users are told.
cleanup_previous() {
	local f uid entry m
	for f in "$STATE"/*.json; do
		[[ -f "$f" ]] || continue
		uid=$(basename "$f" .json)
		while IFS= read -r entry; do
			[[ -n "$entry" ]] || continue
			unmount_entry "$entry" force >/dev/null 2>&1
			notify "$uid" service_restarted "$(jq -r '.file' <<< "$entry")"
			log "$(L "closed after restart" "chiuso dopo il riavvio"): $uid $(jq -r '.file' <<< "$entry")"
		done < <(jq -c '.[]' "$f" 2>/dev/null)
		rm -f "$f"
	done
	# Mount points still hanging under /mnt/vc (e.g. FUSE of a killed container)
	awk '$2 ~ "^/mnt/vc/" { print $2 }' /proc/mounts | sort -r | while IFS= read -r m; do
		m=$(printf '%b' "$m")
		umount -l "$m" 2>/dev/null || fusermount3 -uz "$m" 2>/dev/null
		rmdir "$m" 2>/dev/null
	done
}

# Whether VeraCrypt can really use dm-crypt from this container: a small test
# volume is created and mounted. If not, volumes are decrypted in user space.
kernel_selftest() {
	local t="$RUN/selftest.hc" slot=$MAX_SLOTS dev out ok=no i
	rm -f "$t"
	if out=$(veracrypt --text --non-interactive --create "$t" --size=1M --password=selftest --pim=1 --keyfiles= \
		--random-source=/dev/urandom --volume-type=normal --encryption=AES --hash=SHA-512 --filesystem=none 2>&1) \
		&& out=$(printf 'selftest\n' | timeout 60 veracrypt --text --non-interactive --stdin --pim=1 --keyfiles= \
			--protect-hidden=no --filesystem=none --slot="$slot" --mount "$t" 2>&1); then
		for i in $(seq 1 25); do
			dev=$(slot_device "$slot")
			[[ "$dev" == /dev/mapper/* ]] && dmsetup mknodes >/dev/null 2>&1
			[[ "$dev" == /dev/mapper/* && -b "$dev" ]] && { ok=yes; break; }
			sleep 0.2
		done
		vc_dismount "$slot" force "$dev" ""
	fi
	[[ "$ok" == yes ]] || log "$(L "kernel crypto test failed, using user-space decryption" "test della crittografia del kernel non riuscito, uso la decifratura in user space"): $(printf '%s' "$out" | tr '\n' ' ' | cut -c1-300)"
	rm -f "$t"
	echo "$ok"
}

# Every few seconds: volumes unmounted from outside or not responding
watchdog() {
	local f uid entry target file key
	for f in "$STATE"/*.json; do
		[[ -f "$f" ]] || continue
		uid=$(basename "$f" .json)
		while IFS= read -r entry; do
			target=$(jq -r '.target' <<< "$entry"); file=$(jq -r '.file' <<< "$entry")
			key=$(vkey "$file")
			# Skip volumes being mounted or unmounted right now
			exec 7> "$RUN/vol-$key.lock"
			flock -n 7 || { exec 7>&-; continue; }
			if ! is_mounted "$target"; then
				unmount_entry "$entry" force >/dev/null 2>&1
				state_update "$uid" 'map(select(.file != $f))' --arg f "$file"
				notify "$uid" lost "$file"
				log "$(L "volume unmounted from outside" "volume smontato dall'esterno"): $uid $file"
			elif ! timeout 10 stat -t "$target" >/dev/null 2>&1; then
				[[ -e "$RUN/stuck-$key" ]] || { touch "$RUN/stuck-$key"; notify "$uid" not_responding "$file"; }
			else
				rm -f "$RUN/stuck-$key"
			fi
			exec 7>&-
		done < <(jq -c '.[]' "$f" 2>/dev/null)
	done
}

stop_all() {
	local f uid entry
	log "$(L "stopping: unmounting all volumes" "arresto: smonto tutti i volumi")"
	[[ -n "${SOCAT_PID:-}" ]] && kill "$SOCAT_PID" 2>/dev/null
	for f in "$STATE"/*.json; do
		[[ -f "$f" ]] || continue
		uid=$(basename "$f" .json)
		while IFS= read -r entry; do
			unmount_entry "$entry" force >/dev/null 2>&1
			notify "$uid" service_stopped "$(jq -r '.file' <<< "$entry")"
		done < <(jq -c '.[]' "$f" 2>/dev/null)
		rm -f "$f"
	done
	rm -f "$SOCK"
	exit 0
}

start_socket() {
	# -t: after the request the client may close its side, the answer can take minutes (VeraCrypt
	# tries every algorithm): socat must not stop the handler in the meantime
	socat -t 900 UNIX-LISTEN:"$SOCK",fork,unlink-early,max-children=16,mode=600,user="$NC_UID",group="$NC_GID" \
		EXEC:"/usr/local/bin/vc-bridge.sh handle" &
	SOCAT_PID=$!
}

daemon() {
	[[ -d "$DATA" ]] || { log "$(L "$DATA is missing: the Nextcloud data folder must be mounted there" "manca $DATA: va montata lì la cartella dati di Nextcloud")"; exit 1; }
	mkdir -p "$STATE" "$ERRS" "$MNT" "$RUN/raw"
	chown "$NC_UID:$NC_GID" "$BRIDGE" "$STATE" "$ERRS"
	chmod 750 "$BRIDGE" "$STATE" "$ERRS"
	rm -f "$RUN"/slot-* "$RUN"/stuck-*

	for m in loop fuse dm_crypt dm_mod; do modprobe "$m" 2>/dev/null; done
	ensure_loops
	if [[ "${VC_KERNEL_CRYPTO:-auto}" != no ]] && dmsetup version >/dev/null 2>&1; then
		kernel_selftest > "$RUN/kernel_crypto"
	else
		echo no > "$RUN/kernel_crypto"
	fi
	log "$(veracrypt --text --version 2>/dev/null | head -n1), $(L "kernel crypto (dm-crypt)" "crittografia del kernel (dm-crypt)"): $(cat "$RUN/kernel_crypto")"

	cleanup_previous
	for d in "$MNT"/*/; do
		[[ -d "$d" ]] && user_dir "$(basename "$d")"
	done
	trap stop_all TERM INT
	start_socket
	log "$(L "ready, socket" "pronto, socket"): $SOCK"
	while true; do
		kill -0 "$SOCAT_PID" 2>/dev/null || { log "$(L "socket listener stopped, restarting it" "socket fermo, lo riavvio")"; start_socket; }
		watchdog
		sleep 10 &
		wait $!
	done
}

case "${1:-daemon}" in
	handle) handle ;;
	daemon) daemon ;;
	*) echo "usage: $0 [daemon|handle]" >&2; exit 2 ;;
esac
