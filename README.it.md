# VeraCrypt per Nextcloud

*[English](README.md)*

Ogni utente monta i **volumi VeraCrypt che tiene nei propri File** (per esempio
`archivio.hc`) e ne usa il contenuto nella cartella «VeraCrypt», una sottocartella per
volume. Montaggio, smontaggio e registro errori sono in Impostazioni personali → VeraCrypt.

```
Impostazioni personali → VeraCrypt ── «Monta»: password, PIM, keyfile ──▶ app Nextcloud (veracryptbridge)
                                                                          │ socket nella cartella dati
                                                                          ▼
              container vc4nc-bridge (VeraCrypt ufficiale) apre il file .hc dell'utente
                                                                          │ mount in /mnt/vc/<utente>/<volume>
                                                                          ▼
                                  Archiviazione esterna «VeraCrypt» (Locale, /veracrypt/$user)
                                                                          │
                                                                          ▼
                                                 File → VeraCrypt → <volume>
```

## Installare, aggiornare, disinstallare

Dal **terminale del server** (in Coolify: *Servers → il tuo server → Terminal*), non dal
terminale del container Nextcloud:
```bash
curl -fsSL https://raw.githubusercontent.com/mccoy88f/Veracrypt4Nextcloud/main/install.sh | sudo bash -s -- install it
```
Lo script non fa domande: cosa fare si indica alla fine del comando.

| Fine del comando | Cosa fa |
|---|---|
| `install` | installa o aggiorna app, container VeraCrypt e cartella «VeraCrypt» |
| `uninstall` | rimuove app e container (prima smonta i volumi montati), conserva elenchi e registri errori degli utenti |
| `uninstall purge` | rimuove tutto ciò che il progetto ha creato |

Si possono aggiungere: `it` / `en` (lingua dei messaggi; di default quella del sistema,
altrimenti inglese) e il nome del container Nextcloud se sul server ce n'è più d'uno, per
esempio `... | sudo bash -s -- install it`. **I file dei volumi nei File degli utenti non
vengono mai toccati**, nemmeno da `purge`.

Cosa fa lo script: trova il container Nextcloud (immagine ufficiale o linuxserver), prepara
l'immagine `vc4nc-bridge` con il **pacchetto console ufficiale di VeraCrypt** (controllato con
il suo SHA-256), installa e abilita l'app, avvia il container e crea la cartella «VeraCrypt»
con l'app ufficiale *Archiviazione esterna* (tipo Locale, solo per il gruppo «VeraCrypt», a cui
gli utenti si aggiungono al primo volume montato). Si può rilanciare in qualsiasi momento,
per esempio per aggiornare.

### Una riga nel compose di Nextcloud
I volumi montati arrivano a Nextcloud attraverso una cartella del server, che richiede **una
riga nel compose di Nextcloud**, da aggiungere una volta sola (resta anche dopo gli
aggiornamenti). Lancia prima `install`: prepara la cartella sull'host e, se la riga manca, te
la mostra. Poi in Coolify: risorsa Nextcloud → *Edit Compose File* → nel servizio
**`nextcloud`** (non nel database), sotto `volumes:`
```yaml
      - '/data/veracrypt4nextcloud/mnt:/veracrypt:rslave'
```
salva, fai *Restart* della risorsa e rilancia `install`. (Con docker compose o altri gestori:
stesso volume, poi ricrea il container.)

### Requisiti
- Un server Linux con Docker, **x86_64 o ARM64** (i pacchetti di VeraCrypt esistono per questi).
- FUSE sull'host (`/dev/fuse`, presente quasi ovunque).
- Il container gira **privilegiato** (`--privileged`): a VeraCrypt servono i loop device,
  device-mapper e FUSE. Non ha **nessuna rete** (`--network none`): parla con Nextcloud solo
  tramite un socket nella cartella dati, che può aprire solo l'utente di Nextcloud.
- Se il kernel offre dm-crypt i volumi sono decifrati dal kernel (veloce), altrimenti VeraCrypt
  li decifra in user space (FUSE). Il container lo verifica da solo all'avvio
  (`docker logs vc4nc-bridge` dice quale usa).
- **Non compatibile con la crittografia lato server di Nextcloud** sulle cartelle utente: i file
  sul disco sono cifrati da Nextcloud e VeraCrypt non può aprirli (lo script avvisa).

## Uso (ogni utente)
1. Carica il volume VeraCrypt nei File. I file che finiscono con `.hc` o `.vc` compaiono da
   soli; un volume con un altro nome si aggiunge indicandone il percorso.
2. Impostazioni personali → **VeraCrypt** → *Monta…*: password, PIM (solo se ne hai impostato
   uno), keyfile (percorsi nei tuoi File, facoltativi), sola lettura se vuoi. L'apertura
   richiede da un secondo a mezzo minuto: VeraCrypt prova tutti gli algoritmi.
3. Il contenuto è in File → **VeraCrypt** → *nome del volume*. Si usa come qualsiasi cartella.
4. *Smonta* quando hai finito.

Lo stesso si può fare dall'**app File**, nel menu «…» di un file:
- **Monta con VeraCrypt** su un file di volume: una finestra chiede la password (PIM, keyfile e
  sola lettura sotto «PIM, keyfile, sola lettura»), mostra una rotellina mentre VeraCrypt lavora
  e poi apre il volume;
- **Smonta il volume VeraCrypt** sulla cartella di un volume montato dentro «VeraCrypt», o sul
  suo file; se il volume è in uso propone lo smontaggio forzato;
- **Apri il volume montato** sul file di un volume montato.

Nella stessa pagina:
- **Registro errori**: password sbagliata, filesystem da riparare, volume in uso, volumi
  smontati perché il servizio si è riavviato…, spiegati, con gli errori ripetuti raggruppati;
- lo stato di ogni volume (dove è montato, con che filesystem, da quando).

L'app è in inglese con traduzione italiana: ognuno la vede nella lingua del proprio profilo
Nextcloud.

### Volumi supportati
- Volumi e **volumi nascosti** creati con VeraCrypt (inserisci la password del volume nascosto
  per aprirlo), con password, PIM e keyfile, qualsiasi algoritmo supportato da VeraCrypt.
- Filesystem: **FAT, exFAT, NTFS, ext2/3/4** e gli altri filesystem Linux noti al kernel del
  server. I filesystem Linux sono mostrati a Nextcloud tramite bindfs, con l'utente di Nextcloud.
- Non supportati: vecchi volumi TrueCrypt (VeraCrypt 1.26 non li apre più), cifratura di
  sistema, volumi su dispositivi/partizioni (solo file nei File).
- I volumi NTFS lasciati «sporchi» da Windows (avvio rapido, ibernazione) potrebbero dover
  essere montati in sola lettura, o riparati da Windows.

## Sicurezza: cosa sapere
- **Il volume viene decifrato sul server.** Finché è montato, chi amministra il server (o ne
  prende il controllo) può leggerne il contenuto. Quando è smontato resta solo il file cifrato.
  È il prezzo per usare il volume dal browser.
- La password va dal browser a Nextcloud (usa HTTPS) e da Nextcloud a VeraCrypt tramite il
  socket, sullo standard input: **non viene mai scritta su disco** né su una riga di comando, e
  non viene salvata. I tentativi con password sbagliata sono rallentati dalla protezione
  anti-brute-force di Nextcloud.
- Mentre un volume è montato **il suo file non si può modificare, spostare, copiare o
  eliminare** (VeraCrypt ci sta scrivendo): Nextcloud rifiuta queste operazioni. Non modificare
  il file in altri modi (per esempio direttamente sul disco del server) mentre è montato.
- Il contenuto dei volumi non esce dai volumi: **niente cestino** (un file eliminato da un
  volume è eliminato definitivamente), **niente versioni**, **niente anteprime** e **niente
  condivisione** per la cartella «VeraCrypt». Le app Attività, notifiche e ricerca full-text,
  se installate, possono comunque registrare i nomi dei file.
- Dopo lo smontaggio il file del volume ha una nuova data, così Nextcloud e i client di
  sincronizzazione vedono che è cambiato. Il client desktop può sincronizzare il file del volume
  mentre è montato: la copia può essere incoerente finché non viene smontato e sincronizzato di
  nuovo. Se sincronizzi i File su un computer, valuta di escludere i volumi, o smontali prima di
  aprirli lì.
- Nella cartella «VeraCrypt» in sé non si può creare nulla, e la cartella di un volume montato
  non si può eliminare o rinominare: si può modificare solo il contenuto dei volumi. Quando un
  volume viene smontato, Nextcloud dimentica i suoi file (non compaiono più in Recenti né nella
  ricerca).
- Dopo un riavvio del server, un aggiornamento o un riavvio del container, tutti i volumi
  risultano smontati (le password non vengono conservate): gli utenti li montano di nuovo. Il
  registro errori lo segnala.

## Opzioni
Da lanciare sul server (con l'immagine linuxserver usa `-u 1000` o l'utente giusto e
`/app/www/public/occ`):
```bash
occ config:app:set veracryptbridge max_hours --value=8        # smonta i volumi da soli dopo 8 ore (0: mai, predefinito)
occ config:app:set veracryptbridge extensions --value=hc,vc   # estensioni dei file mostrati come volumi
occ config:app:set veracryptbridge mount_name --value=VeraCrypt   # nome della cartella (dopo averlo cambiato rilancia install.sh)
```
Variabili per `install.sh`: `VC_KERNEL_CRYPTO=no` (decifra sempre in user space),
`BASE_DIR=/percorso` (cartella dell'host per i punti di mount), `REF=branch` (versione da
installare), `SRC_DIR=/percorso` (installa da una copia locale), `BUILD=0` (usa un'immagine già
pronta). Per preparare l'immagine su un server senza accesso a internet, metti il pacchetto
VeraCrypt in `server/` come `veracrypt.deb` e usa `SRC_DIR`.

## Problemi comuni
- **La cartella «VeraCrypt» è vuota anche se il volume è montato**: manca la riga nel compose o
  Nextcloud non è stato riavviato dopo averla aggiunta. Rilancia `install`: te lo dice.
- **Il client desktop mostra la cartella «VeraCrypt» ma non i volumi**: i volumi compaiono alla
  sincronizzazione successiva al montaggio. Il client desktop chiede conferma prima di
  sincronizzare le archiviazioni esterne (*Impostazioni → Chiedi conferma prima di sincronizzare
  archiviazioni esterne*): controlla che la cartella «VeraCrypt» non sia esclusa nella scelta
  delle cartelle dell'account.
- **«Il servizio VeraCrypt non è raggiungibile»**: `docker ps` / `docker logs vc4nc-bridge`.
- **Password sbagliata, ma è giusta**: controlla il PIM (vuoto se non ne hai mai impostato uno)
  e i keyfile; le password con lettere accentate funzionano come su Linux e macOS (UTF-8).
- **«Il filesystem ha errori»**: montalo in sola lettura, o riparalo su un computer
  (chkdsk su Windows, fsck su Linux).
- **«Il volume è in uso»** allo smontaggio: aspetta che finiscano upload/download, o spunta
  *forzatamente*.

## VeraCrypt
Questo progetto usa **[VeraCrypt](https://veracrypt.io)**, il software libero di cifratura
sviluppato da IDRIX a partire da TrueCrypt: il container esegue il **pacchetto console ufficiale
di VeraCrypt**, scaricato dalle [release di VeraCrypt](https://github.com/veracrypt/VeraCrypt/releases)
e controllato con il suo SHA-256. VeraCrypt non è modificato né incluso in questo repository e
mantiene la propria licenza (Apache 2.0 e TrueCrypt License 3.0). I volumi montati qui sono
normali volumi VeraCrypt: continuano a funzionare con VeraCrypt su Windows, macOS e Linux.

Questo progetto non è affiliato, approvato o sponsorizzato da IDRIX o dal progetto VeraCrypt.
«VeraCrypt» è un marchio di IDRIX.

## Licenza e contributi
Il progetto è rilasciato con licenza **[CC BY-NC 4.0](LICENSE)** (Creative Commons
Attribuzione-Non commerciale 4.0):
- ✅ uso, condivisione e modifica liberi e gratuiti per scopi **non commerciali**;
- ✅ i fork sono benvenuti, a patto di **citare il progetto originale**
  (link a https://github.com/mccoy88f/Veracrypt4Nextcloud) e indicare cosa hai cambiato;
- ❌ niente uso commerciale senza il permesso dell'autore.

Il software su cui si appoggia mantiene **le proprie licenze, da rispettare anch'esse**:
VeraCrypt (Apache 2.0 e TrueCrypt License 3.0), Nextcloud (AGPL v3) e gli strumenti
dell'immagine del container. Dettagli in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

Nato dalla stessa idea di [gdrive-rc-connector](https://github.com/mccoy88f/gdrive-rc-connector).
**I miglioramenti sono benvenuti!** Apri una issue per bug e idee, o una pull request con le
tue modifiche.
