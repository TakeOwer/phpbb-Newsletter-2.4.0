# Newsletter for phpBB

![Version](https://img.shields.io/badge/version-2.4.0-blue)
![phpBB](https://img.shields.io/badge/phpBB-3.3-brightgreen)
![PHP](https://img.shields.io/badge/PHP-7.1%20–%208.3-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0-lightgrey)
![Languages](https://img.shields.io/badge/languages-EN%20·%20IT-orange)

Send newsletters from your phpBB board. Members choose which newsletters they want, messages go out in timed batches so your host doesn't cut you off, and every delivery is logged so you know exactly who received what.

---

## What it does

**Multiple newsletters.** A board can run several separate newsletters — a monthly digest, technical announcements, a security bulletin — and each member picks the ones they want. Every message belongs to one newsletter and reaches only its subscribers.
**Opt-in from the user control panel.** Members subscribe themselves, with a tickbox per newsletter, a description of what each one carries and how many people are already subscribed. Confirmation and goodbye emails are optional.
**One-click unsubscribe.** Every message carries a signed link that works without logging in. Leaving one newsletter does not silence the others, and the page says so, offering to remove the reader from everything if that is what they want. `List-Unsubscribe` headers are set so mail clients can offer their own button.
**Three formats.** Plain text, BBCode or HTML. BBCode is parsed by phpBB's own text formatter, so every tag your board defines works, smilies included, and nested lists and quotes come out right. HTML messages carry a plain-text alternative automatically, and CSS is inlined into each element because Gmail and most mail readers discard style blocks.
**Timed batches.** Choose how many messages go out at a time and how long to wait between batches — shared hosts usually cap hourly mail, and this is how you stay under the cap. Sending survives interruptions: each recipient is a queue row with its own state, so a killed process resumes where it stopped.
**Retries that make sense.** A failed recipient is retried in the next batch, because one attempt cannot tell a dead address from a mail server that was briefly down. Permanent failures — malformed addresses, non-existent domains — are not retried, so they don't occupy a slot that belongs to a reachable recipient.
**It stops itself when things go wrong.** Many failures in a row are not bad addresses: they are the mail server not answering. Past a configurable threshold the run pauses itself and records why, so a five-minute outage doesn't burn the retries of every remaining recipient. Resuming takes one click. The author gets an email either way — a summary when the run finishes, a warning when it stops.
**Public archive.** Finished issues can stay readable on the board, with a per-newsletter index and a link in each message reading *if this message does not display correctly, open it in your browser* — often the only way out for a reader whose mail client mangled the layout. Visibility is closed, members only, everyone, or specific groups. HTML issues are rendered inside a sandboxed iframe, so arbitrary markup cannot interfere with the board.
**Sending without admin access.** Chosen groups can write newsletters from their own control panel, using the newsletters you assign them, with an optional approval step that is on by default. They get the same BBCode editor, no raw HTML, and a configurable weekly limit. Every send is logged with name and IP.
**A log that tells you what happened.** Every campaign with delivered, pending and failed counts, drilling down to each recipient with attempt count and the exact error. Pause, resume, cancel, run the next batch now, requeue the failures. Campaigns that stop moving are flagged, which is how you notice the cron isn't running.
**SMTP diagnostics.** A step-by-step delivery test that reports what your mail server actually answers at each stage — DNS, connection, greeting, EHLO, authentication offered, STARTTLS, the send itself. It catches the most common cause of silent failure: port 587 or 465 configured without the `tls://` or `ssl://` prefix.
**Header image.** An optional banner at the top of HTML messages, uploaded or picked from the board's existing `images/` folder with a built-in browser. Chosen images are copied into the extension's own folder, so removing the banner never touches a file that belongs to the board.

---

## Requirements

| | |
|---|---|
| phpBB | 3.3.0 or later |
| PHP | 7.1 or later |
| Database | MySQL, PostgreSQL, SQLite or MSSQL |

Working outgoing mail on the board. If phpBB itself cannot send a password reset, this extension cannot send anything either — the settings page has a diagnostic that tells you where it breaks.

---

## Installation

1. Download the latest release and unzip it.
2. Upload the `salvocortesiano` folder to `ext/` in your board root.
3. Go to **ACP → Customise → Manage extensions** and enable **Newsletter**.

Updating works the same way, with one addition: **disable the extension, replace the files, enable it again.** Migrations only run when the extension is enabled, and several releases add database columns. Replacing files without that step leaves the code ahead of the schema.

If a release changes `config/services.yml`, phpBB's compiled service container can be left stale and lock you out of the ACP. The fix is to delete everything inside `cache/production/` except `.htaccess` and `index.htm`; phpBB rebuilds it on the next visit.

---

## Configuration

Everything lives under **ACP → Extensions → Newsletter**.

| Tab | What's there |
|---|---|
| **Compose** | Write and send: subject, format, body, banner, recipients, batch size and interval, scheduling, preview, test send |
| **Newsletters** | Create, edit, reorder and delete newsletters; see subscriber counts |
| **Log** | Every campaign, its progress, per-recipient results, and the controls to pause, resume, cancel, requeue or approve |
| **Subscribers** | Who is subscribed to what, with search, sorting and removal |
| **Settings** | Delivery, subscriptions, archive, BBCode, safety nets, and sending from the user panel |

Members manage their own subscriptions under **UCP → Newsletter**.

---

## Placeholders

Available in both subject and body:

| Placeholder | Becomes |
|---|---|
| `{USERNAME}` | the recipient's username |
| `{EMAIL}` | their email address |
| `{USER_ID}` | their user id |
| `{BOARD_NAME}` | the board name |
| `{BOARD_URL}` | the board address |
| `{DATE}` | the sending date |
| `{UNSUBSCRIBE_URL}` | the signed unsubscribe address |
| `{UNSUBSCRIBE_LINK}` | a ready-made link, HTML only |
| `{ARCHIVE_URL}` | the issue in the public archive |
| `{ARCHIVE_LINK}` | a ready-made link, HTML only |

---

## Cron
Batches are driven by phpBB's cron. On a board with steady traffic the built-in cron is enough. On a quiet board — or for long runs that need to continue overnight — use a system cron instead, otherwise sending stalls until someone visits.

```
*/5 * * * * php /path/to/board/bin/phpbbcli.php cron:run --quiet
```
The log flags campaigns that have stopped moving, so you will notice if this is not set up.

---

## A note on scale
The queue holds one row per recipient; the message body is stored once on the campaign, not copied per recipient. A run to several thousand addresses costs a few hundred kilobytes of queue, not tens of megabytes.
Recipients are counted with `COUNT(DISTINCT)` rather than by loading the whole address list into memory, which matters on boards with tens of thousands of members.

---

## Privacy
Subscriptions are voluntary and recorded with the address, the time and the IP used to subscribe — the evidence you need if a delivery is ever disputed. Unsubscribing removes the row entirely. Unsubscribe links are signed with HMAC-SHA256 and verified in constant time, so a link for one member cannot be edited into a link for another.

---

## Languages
English and Italian, complete and kept in step — 646 keys each. Translations are welcome: copy `language/en/`, translate, and open a pull request.

---

## License
[GPL-2.0-only](license.txt)

---

## Credits
Written by **salvocortesiano** for [Le Ombre della Rete 360°](https://netshadows.de).
Some of the design was informed by reading **spiderpiggy/newsletter**, an earlier extension with a similar purpose. Where the two differ — a queue that doesn't duplicate the message body, retries instead of a single fatal attempt, and BBCode handled by phpBB's own formatter rather than a hand-written converter — those were deliberate departures.



# Newsletter

Estensione per phpBB 3.3 che invia newsletter agli iscritti del forum, con iscrizione volontaria dal pannello utente, invio a lotti temporizzato e registro dettagliato dei recapiti.

- **Versione:** 2.4.0
- **phpBB:** 3.3.0 o successivo (non compatibile con la 4.x)
- **PHP:** 7.1 o successivo
- **Licenza:** GPL-2.0-only
- **Lingue:** italiano, inglese

---

## Che cosa fa

### Per l'utente

Nel pannello di controllo utente compare una voce **Newsletter** dove ciascuno decide se ricevere i messaggi. Chi si iscrive riceve subito una email di conferma che ricorda a quale forum si è iscritto e contiene un collegamento per cancellarsi.

Quel collegamento funziona **senza bisogno di accedere**: chi non vuole più ricevere i messaggi non ha voglia di ricordare la parola d'ordine, e ogni ostacolo in più si traduce in una segnalazione di posta indesiderata invece che in una cancellazione. Il collegamento è firmato con un HMAC, quindi nessuno può disiscrivere gli altri cambiando il numero nell'indirizzo.

La cancellazione toglie l'iscrizione e disattiva anche la casella «Ricevi email dall'amministratore» del profilo, così ha effetto anche per chi riceve la newsletter perché appartiene a un gruppo.

### Per l'amministratore

**Scrivi una newsletter** — oggetto e corpo in testo semplice o HTML, foglio di stile facoltativo, argomenti del forum da mettere in evidenza, segnaposto personalizzati, anteprima e invio di prova a un solo indirizzo.

**Destinatari** — selezione multipla dei gruppi, con l'aggiunta facoltativa di chi si è iscritto dal pannello utente. Gruppi e iscritti si sommano senza duplicati. Filtro per lingua del destinatario.

**Intestazioni** — priorità da 1 a 5, importanza, riservatezza, nome e indirizzo del mittente, indirizzo per le risposte.

**Consegna** — numero di email per lotto (da 10 a 100) e intervallo fra i lotti in hh:mm:ss, più la possibilità di programmare l'invio a una data e ora. Prima di partire viene mostrata una pagina di conferma con il numero esatto di destinatari, il numero di lotti, la durata stimata e l'ora prevista di fine. Oltre una soglia configurabile compare l'avviso sui limiti di invio degli host condivisi.

**Tre formati** — testo semplice, BBCode o HTML. Il BBCode è quello del forum: viene convertito dal `text_formatter` di phpBB, quindi riconosce tutti i tag definiti sul tuo forum, faccine comprese, e gestisce correttamente liste annidate e citazioni dentro citazioni. La conversione avviene al momento dell'invio, così una bozza scritta oggi tiene conto dei tag che ci saranno domani. I percorsi relativi delle faccine e delle immagini vengono resi assoluti, perché dentro un messaggio di posta un percorso relativo non ha nulla a cui riferirsi.

**Immagine di intestazione** — per le newsletter in HTML si può caricare un banner (JPG, PNG o GIF) dalla pagina di composizione. Viene mostrato in anteprima, si può sostituire o eliminare, e finisce in cima a ogni messaggio, centrato e collegato al forum. Le misure vengono controllate al caricamento: un banner è una striscia larga, e l'altezza ammessa (200-260 pixel come predefinito, modificabile) impedisce che una fotografia riempia l'intera prima schermata del messaggio spingendo il testo fuori dalla vista. Il file è conservato in `images/newsletter/`: una cartella dedicata e non `images/` direttamente, così la rimozione non può toccare risorse di phpBB o degli stili. Una casella permette di mandare un singolo messaggio senza intestazione conservando l'immagine per i prossimi.

**Iscritti** — l'elenco di chi si è iscritto dal pannello utente, con l'indirizzo usato, la data e l'ora dell'iscrizione per esteso e l'IP di provenienza. Si può cercare per nome o indirizzo, ordinare per le tre colonne principali e togliere un singolo utente dall'elenco. Chi si è iscritto ma ha poi rifiutato le email dell'amministratore nel proprio profilo viene segnalato, perché non riceverà i messaggi.

Quando togli qualcuno dall'elenco, la richiesta di conferma ti chiede se avvisarlo. Il messaggio è scritto apposta per quel caso — spiega che è stato un amministratore a rimuovere l'iscrizione e che ci si può iscrivere di nuovo da soli — e non riusa il testo di addio, che parla a chi ha appena premuto un pulsante. La casella parte spuntata o meno secondo l'impostazione, ma la scelta resta tua a ogni rimozione.

**Prova di invio** — nelle impostazioni, sezione Consegna della posta, un pulsante manda un messaggio di prova rifacendo la connessione al server SMTP un passo alla volta: risoluzione del nome, apertura della connessione con codice di errore del sistema, saluto del server, risposta a EHLO, metodi di autenticazione offerti, e infine l'invio vero. Ogni riga riporta la risposta testuale del server così com'è. Quando la porta è una di quelle cifrate e il nome del server non ha prefisso, la prova viene ripetuta con `ssl://` o `tls://` e, se quella riesce, il resoconto dice esattamente cosa scrivere nelle impostazioni email del forum.

**Archivio pubblico** — i numeri conclusi possono restare consultabili sul forum, con elenco paginato, pagina del singolo numero e voce nella barra di navigazione. In cima a ogni messaggio pubblico compare il collegamento «se non vedi correttamente questo messaggio, aprilo nel browser», che è la sola via d'uscita quando un lettore di posta rovina la formattazione. La visibilità è configurabile fra chiuso, solo registrati e chiunque; nell'archivio finiscono soltanto le campagne concluse e marcate come pubbliche, mai le bozze né gli invii in corso. I messaggi HTML vengono resi dentro un riquadro isolato con `sandbox`, così una marcatura arbitraria non può interferire con la pagina del forum.

La pubblicazione si decide prima dell'invio con una casella nel modulo di composizione, ma si può cambiare idea dopo: nel dettaglio della campagna conclusa ci sono i pulsanti «Pubblica nell'archivio» e «Ritira dall'archivio».

**Registro** — ogni newsletter con quante email sono state recapitate, quante sono in attesa e quante fallite. Aprendone una si vedono i destinatari uno per uno, il numero di tentativi e il motivo di ogni fallimento. Si può mettere in pausa, riprendere, annullare, forzare il lotto successivo, rimettere in coda i falliti, cancellare la singola voce o svuotare l'intero registro.

---

## Installazione

1. Copiare la cartella in `ext/salvocortesiano/newsletter/`
2. Pannello di amministrazione → Personalizza → Gestisci estensioni → **Abilita** su «Newsletter»
3. Newsletter → Impostazioni: controllare mittente, piè di pagina e valori predefiniti dei lotti

Il permesso `a_newsletter` viene assegnato automaticamente al gruppo Amministratori. Per darlo ad altri: Permessi → Permessi dei gruppi → categoria «Varie».

### Disinstallazione

Da Gestisci estensioni: **Disabilita** lascia tutto nel database, **Elimina i dati** rimuove tabelle, configurazione, permessi e voci di menu.

---

## Come funziona l'invio

Nessun messaggio parte fuori dalla coda, nemmeno il primo. Alla conferma la coda viene riempita con una riga per destinatario, poi ogni lotto ne preleva un blocco e aggiorna riga per riga l'esito. Se il processo viene interrotto — un timeout, un riavvio, un errore SMTP — l'invio riparte esattamente da dove si era fermato invece di ricominciare da capo e scrivere due volte agli stessi indirizzi.

I lotti successivi partono con l'**attività pianificata** di phpBB. Su un forum poco frequentato conviene impostare un cron di sistema:

```
*/5 * * * * php /percorso/del/forum/bin/phpbbcli.php cron:run
```

Senza, i lotti partono quando qualcuno visita il forum, e l'intervallo reale può risultare più lungo di quello impostato.

### Perché i lotti

Quasi tutti i fornitori di hosting condiviso rifiutano più di 40 o 50 messaggi all'ora. Superare quel limite non fa fallire solo la newsletter: fa bloccare la posta dell'intero forum, comprese le notifiche e le email di registrazione. Lotti piccoli con un intervallo generoso tengono la media sotto qualsiasi soglia.

### Segnaposto

| Segnaposto | Sostituito con |
|---|---|
| `{USERNAME}` | Nome del destinatario |
| `{EMAIL}` | Indirizzo del destinatario |
| `{USER_ID}` | Numero identificativo |
| `{BOARD_NAME}` | Nome del forum |
| `{BOARD_URL}` | Indirizzo del forum |
| `{DATE}` | Data dell'invio |
| `{UNSUBSCRIBE_URL}` | Indirizzo di cancellazione, valido per quel solo destinatario |
| `{UNSUBSCRIBE_LINK}` | Collegamento di cancellazione già pronto (solo in HTML) |

---

## Note tecniche

**Il corpo sta una volta sola.** La campagna conserva il messaggio; la coda tiene solo l'indirizzo e l'esito. Con quattromila iscritti e un messaggio da venti kilobyte, duplicare il corpo per ogni riga costerebbe ottanta megabyte di tabella per un solo invio.

**Il CSS viene reso inline.** Gmail e la maggior parte dei lettori di posta scartano il blocco `<style>`: senza questo passaggio un messaggio formattato con classi arriverebbe senza formattazione. Le regole vengono riscritte nell'attributo `style` di ogni elemento con DOMDocument e XPath.

**Il MIME è costruito a mano.** Il messenger di phpBB dichiara sempre `text/plain` e non c'è modo di inviare HTML senza due intestazioni `Content-Type` in conflitto. La consegna resta però affidata a `phpbb_mail` e `smtpmail`, così le impostazioni SMTP del pannello continuano a valere.

**Il corpo è codificato in base64.** `phpbb_mail` fa passare il messaggio da `wordwrap()` e `smtpmail` lo riscrive riga per riga: un corpo in chiaro con parole lunghe ne uscirebbe spezzato a metà di un tag. L'alfabeto base64 non contiene spazi né il punto, quindi nessuna delle due funzioni ha appigli.

**Ogni lotto è prenotato.** Se due processi arrivano insieme — il cron di sistema e una visita al forum — solo quello che riesce a spostare `campaign_last_run` prosegue. Senza, lo stesso lotto partirebbe due volte.

**L'HTML in ingresso viene ripulito.** Script, riquadri, gestori di evento e URL `javascript:` vengono tolti prima dell'anteprima e prima dell'invio.

### Tabelle

| Tabella | Contenuto |
|---|---|
| `phpbb_newsletter_campaigns` | Una riga per newsletter: corpo, destinatari, cadenza, stato, contatori |
| `phpbb_newsletter_queue` | Una riga per destinatario: stato, tentativi, errore |
| `phpbb_newsletter_subs` | Iscrizioni volontarie: utente, indirizzo, data, IP |

Le iscrizioni di profili cancellati vengono rimosse da sole con la pulizia periodica: phpBB non avvisa le estensioni quando un profilo sparisce, e senza quel passaggio il conteggio degli iscritti conterebbe persone che non esistono più.

---

## Risoluzione dei problemi

**Non parte niente.** Controllare che la posta sia attiva in Generali → Impostazioni email, che la newsletter sia attiva nelle sue impostazioni e che l'attività pianificata giri. La pagina Registro mostra a che punto è ogni campagna.

**Tutti i destinatari falliscono.** Provare l'invio di prova a un solo indirizzo: il motivo dell'errore compare per esteso. Di solito è la configurazione SMTP o un indirizzo mittente su un dominio diverso da quello del forum.

**I messaggi finiscono nella posta indesiderata.** Usare come mittente un indirizzo del dominio del forum e verificare i record SPF e DKIM del dominio.

**L'invio è lentissimo.** È il comportamento previsto: con lotti da 25 ogni dieci minuti servono più di ventisette ore per quattromila iscritti. La pagina di conferma lo dice prima di partire. Se l'host lo consente si possono alzare le email per lotto o accorciare l'intervallo.

**La campagna resta ferma su «in corso».** Il lotto successivo aspetta l'attività pianificata. Dal dettaglio del registro si può forzarne uno con «Invia subito il lotto successivo».

---

## Licenza

GNU General Public License v2.0 only. Vedere il file `license.txt`.
