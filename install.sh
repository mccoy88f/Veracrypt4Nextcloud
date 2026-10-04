#!/usr/bin/env bash
# =============================================================================
#  VeraCrypt for Nextcloud - install, update and uninstall.
#
#  Run it on the SERVER (Docker host), not inside the Nextcloud container,
#  saying what to do (it never asks questions while running):
#
#    curl -fsSL https://raw.githubusercontent.com/mccoy88f/Veracrypt4Nextcloud/main/install.sh | sudo bash -s -- install
#
#  Actions:
#    install            install or update: the Nextcloud app, the vc4nc-bridge container
#                       (official VeraCrypt) and the "VeraCrypt" folder in External storage.
#                       Needs one line in the Nextcloud compose: the script explains which one
#    uninstall          remove app and container (mounted volumes are unmounted first),
#                       keep the users' lists and error logs
#    uninstall purge    remove everything this project created
#  The volume files in the users' Files are never touched.
#
#  Options:
#    en | it            language of the messages (default: system language, otherwise English)
#    CONTAINER_NAME     the Nextcloud container, if the server has more than one
#
#  Optional variables:
#   REF=main                 branch/tag of the repository to download from
#   REPO=mccoy88f/Veracrypt4Nextcloud
#   SRC_DIR=/path            install from a local copy of the repository instead of GitHub
#   BRIDGE_NAME=vc4nc-bridge name of the container that mounts the volumes
#   BASE_DIR=/data/veracrypt4nextcloud   host folder for the mount points
#   BUILD=0                  do not build the image, use the existing $IMAGE (e.g. built elsewhere)
#   VC_KERNEL_CRYPTO=auto    auto: dm-crypt of the kernel if usable, otherwise VeraCrypt
#                            decrypts in user space (FUSE); no: always in user space
# =============================================================================
set -euo pipefail

REPO="${REPO:-mccoy88f/Veracrypt4Nextcloud}"
REF="${REF:-main}"
BRIDGE_NAME="${BRIDGE_NAME:-vc4nc-bridge}"
IMAGE="${IMAGE:-vc4nc-bridge:latest}"
BASE_DIR="${BASE_DIR:-/data/veracrypt4nextcloud}"
MNT_DIR="${MNT_DIR:-$BASE_DIR/mnt}"   # mount points, seen by Nextcloud as /veracrypt
NC_MNT=/veracrypt
UNIT=vc4nc-mnt.service
APP=veracryptbridge
GROUP=veracrypt
ACTION=""
UI="${VC_LANG:-}"
PURGE=0
NC=""
for arg in "$@"; do
	case "$arg" in
		install|uninstall) ACTION="$arg" ;;
		purge) PURGE=1 ;;
		en|it) UI="$arg" ;;
		-h|--help|help) ACTION=help ;;
		*) NC="$arg" ;;
	esac
done

# Message language: en/it argument, then VC_LANG, then the system language
if [[ -z "$UI" ]]; then
	case "${LC_ALL:-${LC_MESSAGES:-${LANG:-}}}" in it*) UI=it ;; *) UI=en ;; esac
fi
L() { if [[ "$UI" == it ]]; then printf '%s' "$2"; else printf '%s' "$1"; fi; }

log()  { echo -e "\e[1;34m==>\e[0m $*"; }
warn() { echo -e "\e[1;33m[$(L WARNING ATTENZIONE)]\e[0m $*" >&2; }
err()  { echo -e "\e[1;31m[$(L ERROR ERRORE)]\e[0m $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || err "$(L "Run as root (sudo)." "Esegui come root (sudo).")"
command -v docker >/dev/null || err "$(L "Docker not found: run the script on the server, not inside a container." "Docker non trovato: lancia lo script sul server, non dentro un container.")"

if [[ -z "$ACTION" || "$ACTION" == help ]]; then
	if [[ "$UI" == it ]]; then
	cat << 'USO'
Uso (sul server, non nel container Nextcloud):
  curl -fsSL https://raw.githubusercontent.com/mccoy88f/Veracrypt4Nextcloud/main/install.sh | sudo bash -s -- AZIONE [it|en]

AZIONE:
  install            installa o aggiorna app, container VeraCrypt e cartella «VeraCrypt»
  uninstall          rimuove app e container (smonta prima i volumi), conserva elenchi e registri
  uninstall purge    rimuove tutto ciò che il progetto ha creato

I file dei volumi nei File degli utenti non vengono mai toccati.
Aggiungi "it" o "en" per scegliere la lingua dei messaggi.
USO
	else
	cat << 'USAGE'
Usage (on the server, not inside the Nextcloud container):
  curl -fsSL https://raw.githubusercontent.com/mccoy88f/Veracrypt4Nextcloud/main/install.sh | sudo bash -s -- ACTION [en|it]

ACTION:
  install            install or update the app, the VeraCrypt container and the "VeraCrypt" folder
  uninstall          remove app and container (volumes are unmounted first), keep lists and logs
  uninstall purge    remove everything this project created

The volume files in the users' Files are never touched.
Add "en" or "it" to choose the language of the messages.
USAGE
	fi
	[[ "$ACTION" == help ]] && exit 0 || exit 1
fi
[[ "$PURGE" == 1 && "$ACTION" != uninstall ]] && err "$(L "'purge' only works with 'uninstall'." "'purge' vale solo con 'uninstall'.")"

# --- 1. Nextcloud container --------------------------------------------------
if [[ -z "$NC" ]]; then
	mapfile -t found < <(docker ps --format '{{.Names}} {{.Image}}' | awk 'tolower($2) ~ /nextcloud/ {print $1}')
	[[ ${#found[@]} -eq 1 ]] || err "$(L "Cannot pick the Nextcloud container, pass it as an argument:" "Non riesco a scegliere il container Nextcloud, passalo come argomento:")
  curl ... | sudo bash -s -- install $(L CONTAINER_NAME NOME_CONTAINER)
$(L "Running containers:" "Container attivi:")
$(docker ps --format '  {{.Names}}  ({{.Image}})')"
	NC="${found[0]}"
fi
docker inspect "$NC" >/dev/null 2>&1 || err "$(L "Container '$NC' not found." "Container '$NC' non trovato.")"
log "Container Nextcloud: $NC"

WEB=$(docker exec "$NC" sh -c 'for d in /var/www/html /config/www/nextcloud /app/www/public; do [ -f "$d/occ" ] && echo "$d" && exit 0; done; exit 1') \
	|| err "$(L "occ not found in $NC: is it really a Nextcloud container?" "Non trovo occ dentro $NC: è davvero un container Nextcloud?")"
NC_UID=$(docker exec "$NC" stat -c %u "$WEB/config/config.php")
NC_GID=$(docker exec "$NC" stat -c %g "$WEB/config/config.php")
log "Nextcloud in $WEB (UID $NC_UID)"

occ() { docker exec -u "$NC_UID" -w "$WEB" "$NC" php occ "$@"; }

DATADIR=$(occ config:system:get datadirectory | tr -d '\r')
[[ -n "$DATADIR" ]] || err "$(L "Cannot read datadirectory from config.php." "Impossibile leggere datadirectory da config.php.")"
BRIDGE_DIR="$DATADIR/.vc-bridge"

# Host path of a path of the Nextcloud container (through its volumes)
host_path() {
	local target="$1" best_dst="" best_src="" dst src
	while IFS='|' read -r dst src; do
		[[ -z "$dst" ]] && continue
		if [[ "$target" == "$dst" || "$target" == "$dst"/* ]] && (( ${#dst} > ${#best_dst} )); then
			best_dst="$dst"; best_src="$src"
		fi
	done < <(docker inspect -f '{{range .Mounts}}{{.Destination}}|{{.Source}}{{"\n"}}{{end}}' "$NC")
	[[ -n "$best_dst" ]] || return 1
	echo "${best_src}${target#"$best_dst"}"
}

# The Nextcloud /veracrypt volume, with mount propagation
nc_mount_ok() {
	docker inspect -f '{{range .Mounts}}{{.Destination}}|{{.Source}}|{{.Propagation}}{{"\n"}}{{end}}' "$NC" \
		| awk -F'|' -v d="$NC_MNT" -v s="$MNT_DIR" '$1 == d && $2 == s && $3 ~ /^r?(slave|shared)$/ { f = 1 } END { exit !f }'
}

# Id of the external storage of the "VeraCrypt" folder (empty if missing)
vc_storage_id() {
	occ files_external:list --output=json 2>/dev/null | docker exec -i -u "$NC_UID" "$NC" php -r '
		foreach (json_decode(stream_get_contents(STDIN), true) ?: [] as $m) {
			if (($m["configuration"]["datadir"] ?? "") === "/veracrypt/\$user") { echo $m["mount_id"]; break; }
		}' 2>/dev/null || true
}

# Mounted volumes, from the state files of the container
mounted_count() {
	local dir n=0 f
	dir=$(host_path "$BRIDGE_DIR/state" 2>/dev/null) || { echo 0; return; }
	for f in "$dir"/*.json; do
		[[ -f "$f" ]] || continue
		n=$((n + $(grep -o '"slot"' "$f" | wc -l)))
	done
	echo "$n"
}

# Stops the container: it unmounts every volume first (they need their password again)
remove_bridge() {
	docker inspect "$BRIDGE_NAME" >/dev/null 2>&1 || return 0
	local n; n=$(mounted_count)
	(( n > 0 )) && warn "$(L "$n mounted volume(s) are being unmounted: the users will have to mount them again." "$n volumi montati vengono smontati: gli utenti dovranno montarli di nuovo.")"
	docker stop -t 300 "$BRIDGE_NAME" >/dev/null 2>&1 || true
	docker rm -f "$BRIDGE_NAME" >/dev/null 2>&1 || true
	# Mounts left hanging on the host (e.g. container killed)
	if [[ -d "$MNT_DIR" ]]; then
		while IFS= read -r m; do
			umount -l "$(printf '%b' "$m")" 2>/dev/null || true
		done < <(findmnt -rn -o TARGET 2>/dev/null | awk -v d="$MNT_DIR/" 'index($0, d) == 1' | sort -r)
	fi
}

APPS=$(docker exec "$NC" sh -c "[ -d '$WEB/custom_apps' ] && echo '$WEB/custom_apps' || echo '$WEB/apps'")

# =============================================================================
#  Uninstall
# =============================================================================
if [[ "$ACTION" == uninstall ]]; then
	[[ "$PURGE" == 1 ]] && log "$(L "Full uninstall (purge)" "Disinstallazione completa (purge)")" || log "$(L "Uninstall (lists and error logs are kept)" "Disinstallazione (elenchi e registri errori restano)")"

	log "$(L "Stopping the VeraCrypt container" "Fermo il container VeraCrypt")"
	remove_bridge

	SID=$(vc_storage_id)
	if [[ -n "$SID" ]]; then
		log "$(L "Removing the folder from External storage" "Rimuovo la cartella dall'Archiviazione esterna")"
		occ files_external:delete -y "$SID" >/dev/null || warn "$(L "External storage $SID not removed." "Archiviazione esterna $SID non rimossa.")"
	fi

	log "$(L "Disabling and removing the app" "Disattivo e rimuovo l'app")"
	occ app:disable "$APP" >/dev/null 2>&1 || true
	docker exec "$NC" rm -rf "$APPS/$APP"

	if [[ "$PURGE" == 1 ]]; then
		docker exec -u "$NC_UID" "$NC" php -r '
			require $argv[1] . "/lib/base.php";
			\OCP\Server::get(\OCP\IConfig::class)->deleteAppFromAllUsers("veracryptbridge");
			\OCP\Server::get(\OCP\IAppConfig::class)->deleteApp("veracryptbridge");' "$WEB" \
			|| warn "$(L "App settings not removed." "Impostazioni dell'app non rimosse.")"
		occ group:delete "$GROUP" >/dev/null 2>&1 || true
		H=$(host_path "$BRIDGE_DIR" || true)
		[[ -n "$H" && -d "$H" ]] && rm -rf "$H"
		docker rmi "$IMAGE" >/dev/null 2>&1 || true
		if docker inspect -f '{{range .Mounts}}{{.Destination}} {{end}}' "$NC" | grep -qw "$NC_MNT"; then
			# Without the (shared) folder Nextcloud might not start again
			warn "$(L "Nextcloud still uses $MNT_DIR: remove the line '$MNT_DIR:$NC_MNT:rslave' from the Nextcloud compose, restart it and run 'uninstall purge' again to remove that folder too." "Nextcloud usa ancora $MNT_DIR: togli la riga '$MNT_DIR:$NC_MNT:rslave' dal compose di Nextcloud, riavvialo (Restart) e rilancia 'uninstall purge' per rimuovere anche quella cartella.")"
		else
			if [[ -f "/etc/systemd/system/$UNIT" ]]; then
				systemctl disable --now "$UNIT" >/dev/null 2>&1 || true
				rm -f "/etc/systemd/system/$UNIT"
				systemctl daemon-reload 2>/dev/null || true
			fi
			mountpoint -q "$MNT_DIR" 2>/dev/null && umount -l "$MNT_DIR" 2>/dev/null
			rm -rf "$MNT_DIR" 2>/dev/null || true
			rmdir "$BASE_DIR" 2>/dev/null || true
		fi
		log "$(L "Settings, lists, logs, image and folders removed" "Rimossi impostazioni, elenchi, registri, immagine e cartelle")"
	fi
	echo
	log "$(L "Uninstall complete. The volume files in the users' Files were not touched." "Disinstallazione completata. I file dei volumi nei File degli utenti non sono stati toccati.")"
	exit 0
fi

# =============================================================================
#  Install / update
# =============================================================================
[[ -e /dev/fuse ]] || err "$(L "This server has no FUSE (/dev/fuse), needed by VeraCrypt: install/enable fuse on the host." "Questo server non ha FUSE (/dev/fuse), che serve a VeraCrypt: installa/attiva fuse sull'host.")"
case "$(uname -m)" in
	x86_64|aarch64) ;;
	*) err "$(L "VeraCrypt packages exist only for x86_64 and aarch64 (this server: $(uname -m))." "I pacchetti di VeraCrypt esistono solo per x86_64 e aarch64 (questo server: $(uname -m)).")" ;;
esac

DATA_HOST=$(host_path "$DATADIR") \
	|| err "$(L "The data folder $DATADIR is not on a persistent volume: Nextcloud data would be lost at every update!" "La cartella dati $DATADIR non è su un volume persistente: i dati di Nextcloud andrebbero persi a ogni aggiornamento!")"
log "$(L "Nextcloud data folder: $DATADIR (host: $DATA_HOST)" "Cartella dati di Nextcloud: $DATADIR (host: $DATA_HOST)")"

if occ encryption:status --output=json 2>/dev/null | grep -q '"enabled":true'; then
	warn "$(L "Server-side encryption is enabled: the files in the users' Files are encrypted on disk and VeraCrypt cannot open them as volumes." "La crittografia lato server è attiva: i file nei File degli utenti sono cifrati sul disco e VeraCrypt non può aprirli come volumi.")"
fi

# --- 2. Host folder of the mount points, "shared" ---------------------------
# The mounts made by the container must reach Nextcloud. The folder must be
# prepared BEFORE adding the volume to Nextcloud: otherwise Docker might refuse
# to start it.
mkdir -p "$MNT_DIR"
if [[ "$(findmnt -no PROPAGATION --target "$MNT_DIR" 2>/dev/null)" != shared ]]; then
	mountpoint -q "$MNT_DIR" || mount --bind "$MNT_DIR" "$MNT_DIR"
	mount --make-rshared "$MNT_DIR"
	if [[ -d /run/systemd/system ]]; then
		cat > "/etc/systemd/system/$UNIT" << UNITFILE
[Unit]
Description=VeraCrypt for Nextcloud: mount folder shared with the containers
Before=docker.service
RequiresMountsFor=$(dirname "$MNT_DIR")

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh -c 'mkdir -p "$MNT_DIR"; mountpoint -q "$MNT_DIR" || mount --bind "$MNT_DIR" "$MNT_DIR"; mount --make-rshared "$MNT_DIR"'

[Install]
WantedBy=multi-user.target
UNITFILE
		{ systemctl daemon-reload && systemctl enable "$UNIT"; } >/dev/null 2>&1 \
			|| warn "$(L "Boot service not enabled: after a server reboot run this script again." "Servizio di avvio non attivato: dopo un riavvio del server rilancia questo script.")"
	else
		warn "$(L "Without systemd the shared folder does not survive a server reboot: after a reboot run this script again." "Senza systemd la cartella condivisa non sopravvive a un riavvio del server: dopo un riavvio rilancia questo script.")"
	fi
	log "$(L "Shared mount folder: $MNT_DIR" "Cartella dei mount condivisa: $MNT_DIR")"
fi

NEED_VOLUME=0
nc_mount_ok || NEED_VOLUME=1

# --- 3. Folder shared with the container (inside the data folder) -----------
BRIDGE_HOST="$DATA_HOST/.vc-bridge"
mkdir -p "$BRIDGE_HOST/state" "$BRIDGE_HOST/errors"
chown "$NC_UID:$NC_GID" "$BRIDGE_HOST" "$BRIDGE_HOST/state" "$BRIDGE_HOST/errors"
chmod 750 "$BRIDGE_HOST" "$BRIDGE_HOST/state" "$BRIDGE_HOST/errors"
log "$(L "Shared folder: $BRIDGE_DIR (host: $BRIDGE_HOST)" "Cartella condivisa: $BRIDGE_DIR (host: $BRIDGE_HOST)")"

# --- 4. Download ---------------------------------------------------------------
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
if [[ -n "${SRC_DIR:-}" ]]; then
	log "$(L "Using local files in $SRC_DIR" "Uso i file locali in $SRC_DIR")"
	cp -r "$SRC_DIR/$APP" "$SRC_DIR/server" "$TMP/"
else
	log "$(L "Downloading from github.com/$REPO ($REF)" "Scarico da github.com/$REPO ($REF)")"
	curl -fsSL "https://github.com/$REPO/archive/$REF.tar.gz" | tar xz -C "$TMP" --strip-components=1 \
		|| err "$(L "Download failed (is the repository private? use the right REPO/REF)." "Download fallito (il repository è privato? usa REPO/REF corretti).")"
fi
[[ -f "$TMP/$APP/appinfo/info.xml" && -f "$TMP/server/Dockerfile" ]] || err "$(L "$APP/ or server/ is missing from the archive." "Nell'archivio mancano $APP/ o server/.")"

# --- 5. Image with the official VeraCrypt ---------------------------------------
if [[ "${BUILD:-1}" == 0 ]]; then
	docker image inspect "$IMAGE" >/dev/null 2>&1 || err "$(L "BUILD=0 but the image $IMAGE does not exist." "BUILD=0 ma l'immagine $IMAGE non esiste.")"
	log "$(L "Using the existing image $IMAGE" "Uso l'immagine esistente $IMAGE")"
else
	log "$(L "Building the image $IMAGE (official VeraCrypt; the first time it takes a few minutes)" "Preparo l'immagine $IMAGE (VeraCrypt ufficiale; la prima volta ci vuole qualche minuto)")"
	docker build -q --pull -t "$IMAGE" "$TMP/server" >/dev/null 2>&1 \
		|| docker build -t "$IMAGE" "$TMP/server" \
		|| err "$(L "Image build failed (see the messages above)." "Creazione dell'immagine non riuscita (vedi i messaggi qui sopra).")"
fi

# --- 6. Nextcloud app ------------------------------------------------------------
host_path "$APPS" >/dev/null || warn "$(L "$APPS is not on a persistent volume: after an update of Nextcloud run this script again." "$APPS non è su un volume persistente: dopo un aggiornamento di Nextcloud rilancia questo script.")"
docker exec "$NC" rm -rf "$APPS/$APP"
docker cp "$TMP/$APP" "$NC:$APPS/$APP"
docker exec "$NC" chown -R "$NC_UID:$NC_GID" "$APPS/$APP"
if occ status --output=json | grep -q '"needsDbUpgrade":true'; then
	log "$(L "Nextcloud requires an upgrade: occ upgrade" "Aggiornamento richiesto da Nextcloud: occ upgrade")"
	occ upgrade -n
fi
occ app:enable "$APP"
occ config:app:set "$APP" bridge_dir --value="$BRIDGE_DIR" >/dev/null
log "$(L "App $APP enabled and configured" "App $APP abilitata e configurata")"

# --- 7. The container that mounts the volumes --------------------------------------
remove_bridge
MODULES=()
[[ -d /lib/modules ]] && MODULES=(-v /lib/modules:/lib/modules:ro)
# --privileged: VeraCrypt needs loop devices, device-mapper and FUSE.
# No network: it only talks to Nextcloud through the socket in the data folder.
docker run -d --name "$BRIDGE_NAME" --restart unless-stopped --privileged --network none \
	-e NC_UID="$NC_UID" -e NC_GID="$NC_GID" -e VC_LANG="$UI" -e VC_KERNEL_CRYPTO="${VC_KERNEL_CRYPTO:-auto}" \
	-v "$DATA_HOST:/ncdata" \
	-v "$MNT_DIR:/mnt/vc:rshared" \
	"${MODULES[@]}" \
	--stop-timeout 300 \
	"$IMAGE" >/dev/null
log "$(L "Container '$BRIDGE_NAME' started" "Container '$BRIDGE_NAME' avviato")"

# --- 8. "VeraCrypt" folder: "Local" external storage on /veracrypt/$user ---------
occ app:enable files_external >/dev/null
occ group:add "$GROUP" --display-name "VeraCrypt" >/dev/null 2>&1 || true
MOUNT_NAME=$(occ config:app:get "$APP" mount_name 2>/dev/null | tr -d '\r' || true)
MOUNT_NAME="${MOUNT_NAME:-VeraCrypt}"
SID=$(vc_storage_id)
if [[ -z "$SID" ]]; then
	SID=$(occ files_external:create "/$MOUNT_NAME" local null::null -c "datadir=$NC_MNT/\$user" | grep -o '[0-9]*$')
	occ files_external:applicable "$SID" --add-group "$GROUP" >/dev/null
	log "$(L "External storage created: '$MOUNT_NAME' for the VeraCrypt group (users join it when they mount their first volume)" "Archiviazione esterna creata: «$MOUNT_NAME» per il gruppo VeraCrypt (gli utenti ci entrano al primo volume montato)")"
fi
# No previews and no sharing: they would copy the content of the volumes outside them
occ files_external:option "$SID" previews false >/dev/null
occ files_external:option "$SID" enable_sharing false >/dev/null
occ files_external:option "$SID" filesystem_check_changes 1 >/dev/null
occ config:app:set "$APP" storage_id --value="$SID" >/dev/null

# --- 9. Checks -----------------------------------------------------------------------
PING=""
for _ in $(seq 1 20); do
	PING=$(docker exec -u "$NC_UID" "$NC" php -r '
		$s = @stream_socket_client("unix://" . $argv[1] . "/vc.sock", $e, $m, 3);
		if ($s) { fwrite($s, "{\"action\":\"ping\"}\n"); echo fgets($s); }' "$BRIDGE_DIR" 2>/dev/null || true)
	[[ "$PING" == *'"ok":true'* ]] && break
	sleep 1
done
if [[ "$PING" == *'"ok":true'* ]]; then
	log "$(L "Nextcloud reaches the VeraCrypt service:" "Nextcloud raggiunge il servizio VeraCrypt:") $(sed -n 's/.*"veracrypt":"\([^"]*\)".*/\1/p' <<< "$PING"), $(L "kernel crypto" "crittografia del kernel"): $(sed -n 's/.*"kernel":\([a-z]*\).*/\1/p' <<< "$PING")"
else
	warn "$(L "Nextcloud cannot reach the VeraCrypt service. Check: docker logs $BRIDGE_NAME" "Nextcloud non raggiunge il servizio VeraCrypt. Controlla: docker logs $BRIDGE_NAME")"
fi

echo
if (( NEED_VOLUME )); then
	warn "$(L "One last step: a volume is missing in Nextcloud. It has to be added once (it stays after updates):" "Ultimo passo: manca un volume in Nextcloud. Va aggiunto una volta sola (resta anche dopo gli aggiornamenti):")"
	if [[ "$UI" == it ]]; then
	cat << HELP

  In Coolify: risorsa Nextcloud → "Edit Compose File" → nel servizio "nextcloud"
  (non nel database), sotto "volumes:", aggiungi questa riga (stesso rientro delle altre):

        - '$MNT_DIR:$NC_MNT:rslave'

  Salva, riavvia la risorsa Nextcloud (Restart) e poi rilancia questo script con 'install'.
  (Con docker compose o altri gestori: stesso volume, poi ricrea il container.)
  Fino ad allora i volumi si montano, ma la cartella «$MOUNT_NAME» nei File resta vuota.

HELP
	else
	cat << HELP

  In Coolify: Nextcloud resource → "Edit Compose File" → in the "nextcloud" service
  (not the database), under "volumes:", add this line (same indentation as the others):

        - '$MNT_DIR:$NC_MNT:rslave'

  Save, restart the Nextcloud resource (Restart) and then run this script again with 'install'.
  (With docker compose or other managers: same volume, then recreate the container.)
  Until then volumes can be mounted, but the "$MOUNT_NAME" folder in Files stays empty.

HELP
	fi
else
	log "$(L "Done! Each user now goes to Personal settings → VeraCrypt." "Fatto! Ogni utente ora va in Impostazioni personali → VeraCrypt.")"
fi
