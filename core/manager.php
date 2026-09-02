<?php
/**
 *
 * Newsletter. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 salvocortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\newsletter\core;

/**
 * Campagne, coda di invio e iscrizioni.
 *
 * Nessun messaggio parte mai fuori dalla coda, nemmeno il primo lotto: cosi un
 * invio interrotto a meta - per un timeout, per un riavvio, per un errore SMTP
 * - riparte esattamente da dove si era fermato invece di ricominciare da capo e
 * scrivere due volte agli stessi indirizzi.
 */
class manager
{
	/** Stati della campagna */
	const STATUS_DRAFT		= 0;
	const STATUS_RUNNING	= 1;
	const STATUS_PAUSED		= 2;
	const STATUS_DONE		= 3;
	const STATUS_CANCELLED	= 4;
	/** Scritta dal pannello utente, in attesa che un amministratore la approvi */
	const STATUS_PENDING	= 5;

	/** Formati di composizione */
	const FORMAT_TEXT	= 0;
	const FORMAT_HTML	= 1;
	const FORMAT_BBCODE	= 2;

	/** Stati della singola riga di coda */
	const QUEUE_PENDING	= 0;
	const QUEUE_SENT	= 1;
	const QUEUE_FAILED	= 2;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\log\log_interface */
	protected $log;

	/** @var \salvocortesiano\newsletter\core\mailer */
	protected $mailer;

	/** @var \salvocortesiano\newsletter\core\html */
	protected $html;

	/** @var \salvocortesiano\newsletter\core\banner */
	protected $banner;

	/** @var \salvocortesiano\newsletter\core\bbcode */
	protected $bbcode;

	/** @var string */
	protected $campaigns_table;

	/** @var string */
	protected $queue_table;

	/** @var string */
	protected $subs_table;

	/** @var string */
	protected $lists_table;

	/** @var string */
	protected $list_subs_table;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/** @var array Testi gia letti dai file di lingua di altre lingue */
	protected $lang_cache = array();

	/**
	 * Constructor
	 */
	public function __construct(
		\phpbb\db\driver\driver_interface $db,
		\phpbb\config\config $config,
		\phpbb\language\language $language,
		\phpbb\user $user,
		\phpbb\log\log_interface $log,
		\salvocortesiano\newsletter\core\mailer $mailer,
		\salvocortesiano\newsletter\core\html $html,
		\salvocortesiano\newsletter\core\banner $banner,
		\salvocortesiano\newsletter\core\bbcode $bbcode,
		$campaigns_table,
		$queue_table,
		$subs_table,
		$lists_table,
		$list_subs_table,
		$root_path,
		$php_ext
	)
	{
		$this->db = $db;
		$this->config = $config;
		$this->language = $language;
		$this->user = $user;
		$this->log = $log;
		$this->mailer = $mailer;
		$this->html = $html;
		$this->banner = $banner;
		$this->bbcode = $bbcode;
		$this->campaigns_table = $campaigns_table;
		$this->queue_table = $queue_table;
		$this->subs_table = $subs_table;
		$this->lists_table = $lists_table;
		$this->list_subs_table = $list_subs_table;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/* =====================================================================
	 * Notiziari
	 * ================================================================== */

	/**
	 * L'impianto a piu notiziari e disponibile?
	 *
	 * Come per l'archivio, si controlla una voce di configurazione scritta
	 * dalla stessa migrazione che crea le tabelle. Finche quella non e passata
	 * l'estensione continua a comportarsi come prima, con un notiziario solo,
	 * invece di cadere su una tabella che non esiste.
	 *
	 * @return bool
	 */
	public function lists_available()
	{
		return isset($this->config['newsletter_default_list'])
			&& (int) $this->config['newsletter_default_list'] > 0;
	}

	/**
	 * @return int
	 */
	public function default_list_id()
	{
		return $this->lists_available() ? (int) $this->config['newsletter_default_list'] : 0;
	}

	/**
	 * Tabella delle iscrizioni in uso.
	 *
	 * Prima della migrazione e quella vecchia, dopo quella nuova. Tutte le
	 * interrogazioni sulle iscrizioni passano da qui, cosi il doppio percorso
	 * resta confinato in un metodo solo invece di essere ripetuto in undici
	 * punti diversi.
	 *
	 * @return string
	 */
	protected function subs_table()
	{
		return $this->lists_available() ? $this->list_subs_table : $this->subs_table;
	}

	/**
	 * Condizione sul notiziario, da aggiungere alle interrogazioni.
	 *
	 * @param int    $list_id 0 per il predefinito, -1 per tutti
	 * @param string $alias
	 * @return string
	 */
	protected function list_clause($list_id = 0, $alias = '')
	{
		if (!$this->lists_available() || (int) $list_id === -1)
		{
			return '';
		}

		$list_id = ((int) $list_id > 0) ? (int) $list_id : $this->default_list_id();
		$prefisso = ($alias !== '') ? $alias . '.' : '';

		return ' AND ' . $prefisso . 'list_id = ' . $list_id;
	}

	/**
	 * Elenco dei notiziari
	 *
	 * @param bool $solo_attivi
	 * @return array
	 */
	public function get_lists($solo_attivi = false)
	{
		if (!$this->lists_available())
		{
			return array();
		}

		$righe = array();

		$sql = 'SELECT * FROM ' . $this->lists_table
			. ($solo_attivi ? ' WHERE list_enabled = 1' : '')
			. ' ORDER BY list_order ASC, list_id ASC';
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$righe[] = $riga;
		}
		$this->db->sql_freeresult($result);

		return $righe;
	}

	/**
	 * @param int $list_id
	 * @return array|false
	 */
	public function get_list($list_id)
	{
		if (!$this->lists_available())
		{
			return false;
		}

		$sql = 'SELECT * FROM ' . $this->lists_table . ' WHERE list_id = ' . (int) $list_id;
		$result = $this->db->sql_query($sql);
		$riga = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $riga;
	}

	/**
	 * Nome di un notiziario, per registri e messaggi
	 *
	 * @param int $list_id
	 * @return string
	 */
	public function list_name($list_id)
	{
		$lista = $this->get_list($list_id);

		return $lista ? (string) $lista['list_name'] : '';
	}

	/**
	 * @param array $dati
	 * @return int
	 */
	public function create_list(array $dati)
	{
		$this->db->sql_query('INSERT INTO ' . $this->lists_table . ' ' . $this->db->sql_build_array('INSERT', $dati));

		return (int) $this->db->sql_nextid();
	}

	/**
	 * @param int   $list_id
	 * @param array $dati
	 */
	public function update_list($list_id, array $dati)
	{
		$this->db->sql_query('UPDATE ' . $this->lists_table . '
			SET ' . $this->db->sql_build_array('UPDATE', $dati) . '
			WHERE list_id = ' . (int) $list_id);
	}

	/**
	 * Elimina un notiziario e le sue iscrizioni.
	 *
	 * Le campagne restano: sono il registro di cio che e stato spedito, e
	 * cancellarle vorrebbe dire perdere traccia di invii realmente avvenuti.
	 * Vengono riassegnate al predefinito.
	 *
	 * @param int $list_id
	 * @return bool
	 */
	public function delete_list($list_id)
	{
		$list_id = (int) $list_id;
		$lista = $this->get_list($list_id);

		// Il predefinito non si elimina: e quello a cui ricadono le iscrizioni
		// e le campagne di tutti gli altri
		if (!$lista || !empty($lista['list_default']))
		{
			return false;
		}

		$this->db->sql_query('DELETE FROM ' . $this->list_subs_table . ' WHERE list_id = ' . $list_id);

		$this->db->sql_query('UPDATE ' . $this->campaigns_table . '
			SET campaign_list_id = ' . $this->default_list_id() . '
			WHERE campaign_list_id = ' . $list_id);

		$this->db->sql_query('DELETE FROM ' . $this->lists_table . ' WHERE list_id = ' . $list_id);

		return true;
	}

	/**
	 * Sposta un notiziario in alto o in basso nell'ordine
	 *
	 * @param int $list_id
	 * @param int $verso -1 su, 1 giu
	 * @return bool
	 */
	public function move_list($list_id, $verso)
	{
		$liste = $this->get_lists();
		$posizione = -1;

		foreach ($liste as $indice => $lista)
		{
			if ((int) $lista['list_id'] === (int) $list_id)
			{
				$posizione = $indice;
				break;
			}
		}

		$vicina = $posizione + (($verso < 0) ? -1 : 1);

		if ($posizione < 0 || !isset($liste[$vicina]))
		{
			return false;
		}

		// L'ordine viene riscritto per intero invece di scambiare due valori:
		// se in passato due notiziari hanno finito con lo stesso numero, uno
		// scambio non li separerebbe mai
		$scambio = $liste[$posizione];
		$liste[$posizione] = $liste[$vicina];
		$liste[$vicina] = $scambio;

		foreach ($liste as $indice => $lista)
		{
			$this->update_list((int) $lista['list_id'], array('list_order' => $indice));
		}

		return true;
	}

	/**
	 * Quanti iscritti ha ciascun notiziario
	 *
	 * @return array list_id => conteggio
	 */
	public function count_by_list()
	{
		if (!$this->lists_available())
		{
			return array();
		}

		$conteggi = array();

		$sql = 'SELECT list_id, COUNT(user_id) AS totale
			FROM ' . $this->list_subs_table . '
			GROUP BY list_id';
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$conteggi[(int) $riga['list_id']] = (int) $riga['totale'];
		}
		$this->db->sql_freeresult($result);

		return $conteggi;
	}

	/* =====================================================================
	 * Iscrizioni dal pannello utente
	 * ================================================================== */

	/**
	 * @param int $user_id
	 * @return bool
	 */
	public function is_subscribed($user_id, $list_id = 0)
	{
		$sql = 'SELECT user_id FROM ' . $this->subs_table() . '
			WHERE user_id = ' . (int) $user_id . $this->list_clause($list_id);
		$result = $this->db->sql_query($sql);
		$riga = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return !empty($riga);
	}

	/**
	 * Collegamento di disiscrizione per un utente, con firma.
	 *
	 * Serve al pannello utente per offrire una via d'uscita diretta accanto a
	 * ogni notiziario, oltre alla casella da togliere: chi vuole andarsene
	 * cerca un comando che dica "cancellami", non una spunta da togliere e un
	 * modulo da inviare.
	 *
	 * @param array $user_row
	 * @param int   $list_id 0 per tutti i notiziari
	 * @return string
	 */
	public function unsubscribe_link(array $user_row, $list_id = 0)
	{
		return $this->mailer->unsubscribe_url($user_row, $list_id);
	}

	/**
	 * Notiziari a cui un utente e iscritto
	 *
	 * @param int $user_id
	 * @return array list_id => momento dell'iscrizione
	 */
	public function get_user_lists($user_id)
	{
		if (!$this->lists_available())
		{
			// Senza notiziari multipli l'iscrizione e una sola: la si riporta
			// come se appartenesse al predefinito, cosi chi chiama non deve
			// distinguere i due impianti
			return $this->is_subscribed($user_id) ? array(0 => 0) : array();
		}

		$iscrizioni = array();

		$sql = 'SELECT list_id, sub_time
			FROM ' . $this->list_subs_table . '
			WHERE user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$iscrizioni[(int) $riga['list_id']] = (int) $riga['sub_time'];
		}
		$this->db->sql_freeresult($result);

		return $iscrizioni;
	}

	/**
	 * @param string $cerca Filtro su nome utente o indirizzo
	 * @return int
	 */
	public function count_subscribers($cerca = '', $list_id = -1)
	{
		// Si contano le iscrizioni, non le persone: senza filtro l'elenco
		// mostra una riga per ogni iscrizione, e chi e in due notiziari
		// compare due volte. Contare le persone farebbe dire "5 iscritti"
		// sopra una tabella di otto righe, e la paginazione salterebbe
		$sql = 'SELECT COUNT(s.user_id) AS totale
			FROM ' . $this->subs_table() . ' s, ' . USERS_TABLE . ' u
			WHERE u.user_id = s.user_id'
			. $this->list_clause($list_id, 's')
			. $this->search_clause($cerca);
		$result = $this->db->sql_query($sql);
		$totale = (int) $this->db->sql_fetchfield('totale');
		$this->db->sql_freeresult($result);

		return $totale;
	}

	/**
	 * Quante persone distinte sono iscritte ad almeno un notiziario.
	 *
	 * Diverso dal conteggio delle iscrizioni: chi e in tre notiziari e una
	 * persona sola. Serve dove si parla di quante persone si raggiungono, non
	 * di quante righe ci sono in tabella.
	 *
	 * @return int
	 */
	public function count_unique_subscribers()
	{
		$sql = 'SELECT COUNT(DISTINCT s.user_id) AS totale
			FROM ' . $this->subs_table() . ' s, ' . USERS_TABLE . ' u
			WHERE u.user_id = s.user_id';
		$result = $this->db->sql_query($sql);
		$totale = (int) $this->db->sql_fetchfield('totale');
		$this->db->sql_freeresult($result);

		return $totale;
	}

	/**
	 * Elenco degli iscritti.
	 *
	 * La giunzione con l'anagrafica e interna e non esterna: se un profilo e
	 * stato cancellato, la sua iscrizione non ha piu senso e non va mostrata
	 * come una riga senza nome. Le righe rimaste orfane vengono ripulite da
	 * remove_orphans().
	 *
	 * @param int    $start
	 * @param int    $limit
	 * @param string $ordine  username, sub_email oppure sub_time
	 * @param string $verso   ASC oppure DESC
	 * @param string $cerca
	 * @return array
	 */
	public function get_subscribers($start = 0, $limit = 25, $ordine = 'sub_time', $verso = 'DESC', $cerca = '', $list_id = -1)
	{
		// L'elenco delle colonne ammesse e una lista chiusa: il nome della
		// colonna finisce dentro la clausola ORDER BY, dove non si puo
		// applicare l'escape dei valori
		$colonne = array(
			'username'	=> 'u.username_clean',
			'sub_email'	=> 's.sub_email',
			'sub_time'	=> 's.sub_time',
		);

		$colonna = isset($colonne[$ordine]) ? $colonne[$ordine] : 's.sub_time';
		$verso = (strtoupper($verso) === 'ASC') ? 'ASC' : 'DESC';

		$notiziari = $this->lists_available();

		$sql = 'SELECT s.user_id, s.sub_email, s.sub_time, s.sub_ip,'
			. ($notiziari ? ' s.list_id, l.list_name,' : '') . '
				u.username, u.user_colour, u.user_email, u.user_allow_massemail, u.user_lang
			FROM ' . $this->subs_table() . ' s, ' . USERS_TABLE . ' u'
			. ($notiziari ? ', ' . $this->lists_table . ' l' : '') . '
			WHERE u.user_id = s.user_id'
			. ($notiziari ? ' AND l.list_id = s.list_id' : '')
			. $this->list_clause($list_id, 's')
			. $this->search_clause($cerca) . '
			ORDER BY ' . $colonna . ' ' . $verso . ', s.user_id ASC';

		$result = $this->db->sql_query_limit($sql, $limit, $start);
		$righe = array();

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$righe[] = $riga;
		}
		$this->db->sql_freeresult($result);

		return $righe;
	}

	/**
	 * Filtro di ricerca su nome utente e indirizzo di iscrizione
	 *
	 * @param string $cerca
	 * @return string
	 */
	protected function search_clause($cerca)
	{
		$cerca = trim((string) $cerca);

		if ($cerca === '')
		{
			return '';
		}

		$qualsiasi = $this->db->get_any_char();

		// username_clean e la forma normalizzata che phpBB usa proprio per le
		// ricerche: e gia in minuscolo e senza accenti, quindi cercare "rossi"
		// trova anche "Rossi"
		$nome = function_exists('utf8_clean_string') ? utf8_clean_string($cerca) : strtolower($cerca);
		$indirizzo = strtolower($cerca);

		return ' AND (u.username_clean ' . $this->db->sql_like_expression($qualsiasi . $nome . $qualsiasi) . '
			OR ' . $this->db->sql_lower_text('s.sub_email') . ' ' . $this->db->sql_like_expression($qualsiasi . $indirizzo . $qualsiasi) . ')';
	}

	/**
	 * Toglie le iscrizioni di profili non piu esistenti.
	 *
	 * phpBB non avvisa le estensioni quando un profilo viene cancellato, e la
	 * riga di iscrizione resterebbe li a gonfiare il conteggio degli iscritti
	 * con persone che non esistono piu.
	 *
	 * @return int
	 */
	public function remove_orphans()
	{
		$orfani = array();

		$sql = 'SELECT DISTINCT s.user_id
			FROM ' . $this->subs_table() . ' s
			LEFT JOIN ' . USERS_TABLE . ' u ON (u.user_id = s.user_id)
			WHERE u.user_id IS NULL';
		$result = $this->db->sql_query_limit($sql, 500);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$orfani[] = (int) $riga['user_id'];
		}
		$this->db->sql_freeresult($result);

		if (empty($orfani))
		{
			return 0;
		}

		$this->db->sql_query('DELETE FROM ' . $this->subs_table() . '
			WHERE ' . $this->db->sql_in_set('user_id', $orfani));

		return count($orfani);
	}

	/**
	 * Iscrive un utente e gli manda la conferma.
	 *
	 * @param array $user_row Dati dell'utente (user_id, username, user_email)
	 * @param string $ip
	 * @return bool Vero se l'iscrizione e nuova
	 */
	public function subscribe(array $user_row, $ip = '', $list_id = 0)
	{
		$user_id = (int) $user_row['user_id'];

		if ($user_id <= 0 || $this->is_subscribed($user_id, $list_id))
		{
			return false;
		}

		$riga = array(
			'user_id'	=> $user_id,
			'sub_email'	=> (string) $user_row['user_email'],
			'sub_time'	=> time(),
			'sub_ip'	=> substr((string) $ip, 0, 40),
		);

		if ($this->lists_available())
		{
			$riga['list_id'] = ((int) $list_id > 0) ? (int) $list_id : $this->default_list_id();
		}

		$this->db->sql_query('INSERT INTO ' . $this->subs_table() . ' ' . $this->db->sql_build_array('INSERT', $riga));

		if (!empty($this->config['newsletter_welcome_email']))
		{
			$this->send_service_email($user_row, 'NL_MAIL_WELCOME_SUBJECT', 'NL_MAIL_WELCOME_BODY', isset($riga['list_id']) ? (int) $riga['list_id'] : 0);
		}

		$this->log->add('user', $user_id, (string) $ip, 'LOG_NEWSLETTER_SUBSCRIBED', false, array(
			'reportee_id' => $user_id,
		));

		return true;
	}

	/**
	 * Disiscrive un utente.
	 *
	 * Toglie l'iscrizione volontaria e, se richiesto, disattiva anche le email
	 * di massa nel profilo. Il secondo passaggio serve per chi riceve la
	 * newsletter perche appartiene a un gruppo e non perche si e iscritto:
	 * senza, il collegamento nel messaggio non avrebbe alcun effetto per lui,
	 * che e il modo migliore per farsi segnalare come mittente indesiderato.
	 *
	 * @param int  $user_id
	 * @param bool $block_massemail
	 * @param string $ip
	 * @param int|null $actor_id Chi compie l'azione, se non e l'utente stesso
	 * @return bool Vero se qualcosa e cambiato
	 */
	public function unsubscribe($user_id, $block_massemail = true, $ip = '', $actor_id = null, $list_id = -1)
	{
		$user_id = (int) $user_id;
		$cambiato = false;

		$this->db->sql_query('DELETE FROM ' . $this->subs_table() . '
			WHERE user_id = ' . $user_id . $this->list_clause($list_id));

		if ($this->db->sql_affectedrows())
		{
			$cambiato = true;
		}

		if ($block_massemail)
		{
			$this->db->sql_query('UPDATE ' . USERS_TABLE . '
				SET user_allow_massemail = 0
				WHERE user_id = ' . $user_id . '
					AND user_allow_massemail = 1');

			if ($this->db->sql_affectedrows())
			{
				$cambiato = true;
			}
		}

		if ($cambiato)
		{
			// Quando a disiscrivere e un amministratore, la voce nel registro
			// deve riportare lui come autore e l'utente come interessato: il
			// contrario farebbe sembrare che se ne sia andato da solo
			$this->log->add('user', ($actor_id === null) ? $user_id : (int) $actor_id, (string) $ip, 'LOG_NEWSLETTER_UNSUBSCRIBED', false, array(
				'reportee_id' => $user_id,
			));
		}

		return $cambiato;
	}

	/**
	 * Riattiva la ricezione delle email di massa.
	 *
	 * Chi si iscrive dal pannello utente dopo essersi disiscritto in
	 * precedenza si aspetta di ricevere di nuovo i messaggi: lasciare la
	 * casella del profilo disattivata renderebbe l'iscrizione inefficace, e
	 * l'utente non avrebbe modo di capire perche.
	 *
	 * @param int $user_id
	 */
	public function allow_massemail($user_id)
	{
		$this->db->sql_query('UPDATE ' . USERS_TABLE . '
			SET user_allow_massemail = 1
			WHERE user_id = ' . (int) $user_id);
	}

	/**
	 * Messaggio di servizio: conferma di iscrizione o di cancellazione.
	 *
	 * Viene inviato subito e non passa dalla coda: e un solo messaggio, atteso
	 * dall'utente proprio in quel momento, e farlo aspettare il lotto
	 * successivo sarebbe incomprensibile.
	 *
	 * @param array  $user_row
	 * @param string $subject_key
	 * @param string $body_key
	 * @return bool
	 */
	public function send_service_email(array $user_row, $subject_key, $body_key, $list_id = 0)
	{
		$destinatario = array(
			'user_id'		=> (int) $user_row['user_id'],
			'username'		=> (string) $user_row['username'],
			'user_email'	=> (string) $user_row['user_email'],
			'user_lang'		=> isset($user_row['user_lang']) ? (string) $user_row['user_lang'] : '',
		);

		// Il nome del notiziario entra nel testo: "ti sei iscritto alla
		// newsletter" non dice a quale, e con piu notiziari e proprio
		// l'informazione che serve
		$nome = $this->lists_available() ? $this->list_name($list_id) : '';
		$nome = ($nome !== '') ? $nome : html_entity_decode((string) $this->config['sitename'], ENT_COMPAT, 'UTF-8');

		$campagna = array(
			'campaign_id'			=> 0,
			'campaign_subject'		=> str_replace('{LIST_NAME}', $nome, $this->lang_for($destinatario['user_lang'], $subject_key)),
			'campaign_body'			=> str_replace('{LIST_NAME}', $nome, $this->lang_for($destinatario['user_lang'], $body_key)),
			'campaign_format'		=> 0,
			'campaign_priority'		=> 3,
			'campaign_importance'	=> 'normal',
			'campaign_sensitivity'	=> '',
		);

		$errore = '';

		// Il messaggio di conferma e un contorno: l'iscrizione e gia registrata
		// quando si arriva qui. Se la posta fallisce - configurazione assente,
		// funzione del forum che cambia firma, server irraggiungibile - l'utente
		// deve vedere l'iscrizione riuscita, non una pagina di errore fatale su
		// una operazione che invece e andata a buon fine
		try
		{
			return $this->mailer->send($destinatario, $campagna, $errore);
		}
		catch (\Exception $e)
		{
			return false;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}

	/**
	 * Testo tradotto nella lingua del destinatario.
	 *
	 * Quando a disiscrivere e un amministratore, la lingua attiva e la sua, non
	 * quella di chi ricevera il messaggio: senza questo passaggio un utente che
	 * legge il forum in inglese si vedrebbe arrivare un'email in italiano. Il
	 * file dell'estensione viene letto direttamente perche cambiare la lingua
	 * attiva a meta richiesta rovinerebbe tutte le altre stringhe della pagina
	 * di amministrazione.
	 *
	 * @param string $iso
	 * @param string $chiave
	 * @return string
	 */
	protected function lang_for($iso, $chiave)
	{
		$iso = trim((string) $iso);

		// Il codice finisce in un percorso: solo lettere, cifre e trattini,
		// e comunque passato da basename()
		if ($iso === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $iso) || $iso === $this->language->get_used_language())
		{
			return $this->language->lang($chiave);
		}

		if (!isset($this->lang_cache[$iso]))
		{
			$this->lang_cache[$iso] = array();

			$file = $this->root_path . 'ext/salvocortesiano/newsletter/language/' . basename($iso) . '/newsletter.php';

			if (file_exists($file))
			{
				$lang = array();
				include $file;

				if (is_array($lang))
				{
					$this->lang_cache[$iso] = $lang;
				}
			}
		}

		return isset($this->lang_cache[$iso][$chiave])
			? $this->lang_cache[$iso][$chiave]
			: $this->language->lang($chiave);
	}

	/* =====================================================================
	 * Gruppi e destinatari
	 * ================================================================== */

	/**
	 * Elenco dei gruppi con il numero di membri effettivamente raggiungibili.
	 *
	 * Il conteggio non e quello dei membri del gruppo ma quello di chi
	 * ricevera davvero il messaggio: mostrare "Registrati: 4.200" quando gli
	 * indirizzi utilizzabili sono 3.100 fa pianificare all'amministratore un
	 * invio che dura un terzo in piu del necessario.
	 *
	 * @return array
	 */
	public function get_groups()
	{
		$conteggi = array();

		$sql = 'SELECT ug.group_id, COUNT(DISTINCT u.user_id) AS membri
			FROM ' . USER_GROUP_TABLE . ' ug, ' . USERS_TABLE . ' u
			WHERE u.user_id = ug.user_id
				AND ug.user_pending = 0
				AND ' . $this->db->sql_in_set('u.user_type', array(USER_NORMAL, USER_FOUNDER)) . "
				AND u.user_email <> ''"
				. $this->optout_clause('u') . '
			GROUP BY ug.group_id';
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$conteggi[(int) $riga['group_id']] = (int) $riga['membri'];
		}
		$this->db->sql_freeresult($result);

		$gruppi = array();

		$sql = 'SELECT group_id, group_name, group_type
			FROM ' . GROUPS_TABLE . "
			WHERE group_name <> 'BOTS'
				AND group_name <> 'GUESTS'
			ORDER BY group_type DESC, group_name ASC";
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$id = (int) $riga['group_id'];
			$speciale = ((int) $riga['group_type'] === GROUP_SPECIAL);

			$gruppi[$id] = array(
				'group_id'		=> $id,
				'group_name'	=> $speciale ? $this->language->lang('G_' . $riga['group_name']) : $riga['group_name'],
				'special'		=> $speciale,
				'members'		=> isset($conteggi[$id]) ? $conteggi[$id] : 0,
			);
		}
		$this->db->sql_freeresult($result);

		return $gruppi;
	}

	/**
	 * Nomi dei gruppi indicati, per la visualizzazione nel registro
	 *
	 * @param array $group_ids
	 * @return array
	 */
	public function get_group_names(array $group_ids)
	{
		$group_ids = array_filter(array_map('intval', $group_ids));

		if (empty($group_ids))
		{
			return array();
		}

		$nomi = array();

		$sql = 'SELECT group_id, group_name, group_type
			FROM ' . GROUPS_TABLE . '
			WHERE ' . $this->db->sql_in_set('group_id', $group_ids);
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$nomi[(int) $riga['group_id']] = ((int) $riga['group_type'] === GROUP_SPECIAL)
				? $this->language->lang('G_' . $riga['group_name'])
				: $riga['group_name'];
		}
		$this->db->sql_freeresult($result);

		return $nomi;
	}

	/**
	 * Lingue installate sul forum
	 *
	 * @return array
	 */
	public function get_languages()
	{
		$lingue = array();

		$sql = 'SELECT lang_iso, lang_local_name FROM ' . LANG_TABLE . ' ORDER BY lang_local_name ASC';
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$lingue[(string) $riga['lang_iso']] = (string) $riga['lang_local_name'];
		}
		$this->db->sql_freeresult($result);

		return $lingue;
	}

	/**
	 * Quanti destinatari raggiungerebbe la selezione indicata
	 *
	 * @param array  $group_ids
	 * @param bool   $include_subs
	 * @param string $lang
	 * @return int
	 */
	public function count_recipients(array $group_ids, $include_subs = false, $lang = '', $list_id = 0)
	{
		$sql = $this->recipients_sql('COUNT(DISTINCT u.user_id) AS totale', $group_ids, $include_subs, $lang, $list_id);

		if ($sql === false)
		{
			return 0;
		}

		$result = $this->db->sql_query($sql);
		$totale = (int) $this->db->sql_fetchfield('totale');
		$this->db->sql_freeresult($result);

		return $totale;
	}

	/**
	 * Riempie la coda con i destinatari della campagna.
	 *
	 * L'inserimento avviene a blocchi mentre si legge il risultato, senza
	 * caricare in memoria l'intera anagrafica: su un forum con decine di
	 * migliaia di iscritti la differenza fra le due strategie e fra un invio
	 * che parte e un errore di memoria esaurita.
	 *
	 * @param int   $campaign_id
	 * @param array $campaign Riga della campagna
	 * @return int Destinatari accodati
	 */
	public function fill_queue($campaign_id, array $campaign)
	{
		$campaign_id = (int) $campaign_id;

		$gruppi = $this->split_ids((string) $campaign['campaign_groups']);
		$sql = $this->recipients_sql(
			'DISTINCT u.user_id, u.username, u.user_email, u.user_lang',
			$gruppi,
			!empty($campaign['campaign_subs']),
			(string) $campaign['campaign_lang'],
			isset($campaign['campaign_list_id']) ? (int) $campaign['campaign_list_id'] : 0
		);

		if ($sql === false)
		{
			return 0;
		}

		// Ripartendo si azzera quanto restava: la coda e sempre il riflesso di
		// una sola preparazione
		$this->db->sql_query('DELETE FROM ' . $this->queue_table . ' WHERE campaign_id = ' . $campaign_id);

		$result = $this->db->sql_query($sql . ' ORDER BY u.user_id ASC');

		$blocco = array();
		$totale = 0;
		$gia_visti = array();

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$blocco[] = $this->queue_row($campaign_id, $riga);
			$gia_visti[(int) $riga['user_id']] = true;
			$totale++;

			if (count($blocco) >= 200)
			{
				$this->db->sql_multi_insert($this->queue_table, $blocco);
				$blocco = array();
			}
		}
		$this->db->sql_freeresult($result);

		// Copia al mittente: permette di vedere il messaggio come lo vedono
		// gli altri, cosa che nessuna anteprima riproduce fedelmente
		if (!empty($this->config['newsletter_copy_sender']))
		{
			$autore = (int) $campaign['campaign_author'];

			if ($autore > 0 && !isset($gia_visti[$autore]))
			{
				$riga = $this->get_user_row($autore);

				if ($riga && trim((string) $riga['user_email']) !== '')
				{
					$blocco[] = $this->queue_row($campaign_id, $riga);
					$totale++;
				}
			}
		}

		if (!empty($blocco))
		{
			$this->db->sql_multi_insert($this->queue_table, $blocco);
		}

		$this->db->sql_query('UPDATE ' . $this->campaigns_table . '
			SET campaign_total = ' . (int) $totale . ', campaign_sent = 0, campaign_failed = 0
			WHERE campaign_id = ' . $campaign_id);

		return $totale;
	}

	/**
	 * @param int   $campaign_id
	 * @param array $riga
	 * @return array
	 */
	protected function queue_row($campaign_id, array $riga)
	{
		return array(
			'campaign_id'	=> (int) $campaign_id,
			'user_id'		=> (int) $riga['user_id'],
			'username'		=> (string) $riga['username'],
			'user_email'	=> (string) $riga['user_email'],
			'user_lang'		=> (string) $riga['user_lang'],
			'queue_status'	=> self::QUEUE_PENDING,
			'queue_attempts'=> 0,
			'queue_time'	=> 0,
			'queue_error'	=> '',
		);
	}

	/**
	 * @param int $user_id
	 * @return array|false
	 */
	public function get_user_row($user_id)
	{
		$sql = 'SELECT user_id, username, user_email, user_lang
			FROM ' . USERS_TABLE . '
			WHERE user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$riga = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $riga;
	}

	/**
	 * Interrogazione dei destinatari.
	 *
	 * Gruppi e iscritti si sommano: un utente che appartiene a un gruppo
	 * selezionato e si e anche iscritto compare una volta sola, ed e il motivo
	 * per cui servono le giunzioni esterne e non due interrogazioni separate.
	 *
	 * @param string $select
	 * @param array  $group_ids
	 * @param bool   $include_subs
	 * @param string $lang
	 * @return string|false false se la selezione e vuota
	 */
	protected function recipients_sql($select, array $group_ids, $include_subs, $lang = '', $list_id = 0)
	{
		$group_ids = array_values(array_unique(array_filter(array_map('intval', $group_ids))));
		$include_subs = (bool) $include_subs;

		if (empty($group_ids) && !$include_subs)
		{
			return false;
		}

		$sql = 'SELECT ' . $select . ' FROM ' . USERS_TABLE . ' u';
		$condizioni = array();

		if (!empty($group_ids))
		{
			$sql .= ' LEFT JOIN ' . USER_GROUP_TABLE . ' ug ON (ug.user_id = u.user_id
				AND ug.user_pending = 0
				AND ' . $this->db->sql_in_set('ug.group_id', $group_ids) . ')';

			$condizioni[] = 'ug.user_id IS NOT NULL';
		}

		if ($include_subs)
		{
			$sql .= ' LEFT JOIN ' . $this->subs_table() . ' ns ON (ns.user_id = u.user_id'
				. ($this->lists_available() ? ' AND ns.list_id = ' . (((int) $list_id > 0) ? (int) $list_id : $this->default_list_id()) : '') . ')';

			$condizioni[] = 'ns.user_id IS NOT NULL';
		}

		$where = '(' . implode(' OR ', $condizioni) . ')
			AND ' . $this->db->sql_in_set('u.user_type', array(USER_NORMAL, USER_FOUNDER)) . "
			AND u.user_email <> ''"
			. $this->optout_clause('u');

		if (trim($lang) !== '')
		{
			$where .= " AND u.user_lang = '" . $this->db->sql_escape(trim($lang)) . "'";
		}

		if (!empty($this->config['newsletter_skip_banned']))
		{
			$banditi = $this->get_banned_users();

			if (!empty($banditi))
			{
				$where .= ' AND ' . $this->db->sql_in_set('u.user_id', $banditi, true);
			}
		}

		return $sql . ' WHERE ' . $where;
	}

	/**
	 * Frammento che esclude chi ha rifiutato le email di massa.
	 *
	 * La casella "Ricevi email dall'amministratore" del pannello utente non e
	 * un dettaglio estetico: ignorarla espone il gestore del forum alle norme
	 * sul consenso, e alle segnalazioni di spam che ne conseguono. Resta
	 * disattivabile perche in certi casi - un annuncio di chiusura, una
	 * violazione di dati - la comunicazione e dovuta a prescindere.
	 *
	 * @param string $alias
	 * @return string
	 */
	protected function optout_clause($alias)
	{
		return !empty($this->config['newsletter_respect_optout']) ? ' AND ' . $alias . '.user_allow_massemail = 1' : '';
	}

	/**
	 * Utenti con un bando attivo
	 *
	 * @return array
	 */
	protected function get_banned_users()
	{
		$banditi = array();

		$sql = 'SELECT ban_userid
			FROM ' . BANLIST_TABLE . '
			WHERE ban_userid <> 0
				AND ban_exclude = 0
				AND (ban_end = 0 OR ban_end > ' . time() . ')';
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$banditi[] = (int) $riga['ban_userid'];
		}
		$this->db->sql_freeresult($result);

		return $banditi;
	}

	/* =====================================================================
	 * Argomenti in evidenza
	 * ================================================================== */

	/**
	 * Ripulisce un elenco di identificativi di argomento
	 *
	 * @param string $valore
	 * @return string
	 */
	public function normalize_topic_ids($valore)
	{
		$puliti = array();

		foreach (preg_split('/[^0-9]+/', (string) $valore) as $id)
		{
			$id = (int) $id;

			if ($id > 0 && !in_array($id, $puliti, true))
			{
				$puliti[] = $id;
			}
		}

		return implode(',', array_slice($puliti, 0, 20));
	}

	/**
	 * Argomenti indicati, nell'ordine in cui sono stati scritti.
	 *
	 * Vengono presi solo quelli approvati e non cancellati: un collegamento a
	 * un argomento in coda di moderazione porterebbe la meta dei lettori su una
	 * pagina di errore.
	 *
	 * @param string $topic_ids
	 * @return array
	 */
	public function get_topics($topic_ids)
	{
		$topic_ids = $this->normalize_topic_ids($topic_ids);

		if ($topic_ids === '')
		{
			return array();
		}

		$ids = array_map('intval', explode(',', $topic_ids));

		$sql = 'SELECT topic_id, topic_title
			FROM ' . TOPICS_TABLE . '
			WHERE ' . $this->db->sql_in_set('topic_id', $ids);

		if (defined('ITEM_APPROVED'))
		{
			$sql .= ' AND topic_visibility = ' . (int) ITEM_APPROVED;
		}

		$result = $this->db->sql_query($sql);
		$trovati = array();

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$trovati[(int) $riga['topic_id']] = array(
				'topic_id'		=> (int) $riga['topic_id'],
				'topic_title'	=> html_entity_decode((string) $riga['topic_title'], ENT_QUOTES, 'UTF-8'),
				'topic_url'		=> $this->mailer->board_url() . '/viewtopic.' . $this->php_ext . '?t=' . (int) $riga['topic_id'],
			);
		}
		$this->db->sql_freeresult($result);

		$ordinati = array();

		foreach ($ids as $id)
		{
			if (isset($trovati[$id]))
			{
				$ordinati[] = $trovati[$id];
			}
		}

		return $ordinati;
	}

	/**
	 * Porta la campagna nella forma che il componente di invio si aspetta.
	 *
	 * Il BBCode viene convertito qui e non al salvataggio: cosi una bozza
	 * scritta oggi tiene conto dei tag e delle faccine che ci saranno domani,
	 * invece di restare congelata all'HTML prodotto nel momento in cui e stata
	 * scritta. Dopo la conversione il componente di invio vede un normale
	 * messaggio in HTML e non ha bisogno di sapere che esiste un terzo formato.
	 *
	 * @param array $campagna
	 * @return array
	 */
	public function prepare_campaign(array $campagna)
	{
		$formato = isset($campagna['campaign_format']) ? (int) $campagna['campaign_format'] : self::FORMAT_TEXT;

		if ($formato === self::FORMAT_BBCODE)
		{
			$html = $this->bbcode->to_html((string) $campagna['campaign_body']);

			// I percorsi relativi delle faccine e delle immagini caricate non
			// significano nulla dentro un messaggio di posta
			$campagna['campaign_body'] = $this->html->absolutise($html, $this->mailer->board_url());
			$campagna['campaign_format'] = self::FORMAT_HTML;
		}

		$campagna['campaign_topics_block'] = $this->build_topics_block(
			isset($campagna['campaign_topics']) ? (string) $campagna['campaign_topics'] : '',
			!empty($campagna['campaign_format'])
		);

		$campagna['campaign_banner_block'] = $this->build_banner_block($campagna);
		$campagna['campaign_archive_block'] = $this->build_archive_block($campagna);

		return $campagna;
	}

	/**
	 * Intestazione grafica, quando il messaggio e in HTML e la campagna la vuole.
	 *
	 * In un messaggio di testo semplice non ha senso: non c'e modo di mostrare
	 * una immagine, e il solo indirizzo del file in cima al testo sarebbe
	 * rumore.
	 *
	 * @param array $campaign
	 * @return string
	 */
	public function build_banner_block(array $campaign)
	{
		$html = !empty($campaign['campaign_format']);
		$voluto = !isset($campaign['campaign_banner']) || !empty($campaign['campaign_banner']);

		return ($html && $voluto) ? $this->banner->html() : '';
	}

	/**
	 * Blocco degli argomenti in evidenza, pronto da accodare al corpo
	 *
	 * @param string $topic_ids
	 * @param bool   $is_html
	 * @return string
	 */
	public function build_topics_block($topic_ids, $is_html)
	{
		$argomenti = $this->get_topics($topic_ids);

		if (empty($argomenti))
		{
			return '';
		}

		$titolo = $this->language->lang('NL_HOT_TOPICS');

		if (!$is_html)
		{
			$blocco = $titolo . "\n\n";

			foreach ($argomenti as $argomento)
			{
				$blocco .= '- ' . $argomento['topic_title'] . "\n  " . $argomento['topic_url'] . "\n";
			}

			return rtrim($blocco);
		}

		$blocco = '<div class="nl-topics"><h3>' . htmlspecialchars($titolo, ENT_COMPAT, 'UTF-8') . '</h3><ul>';

		foreach ($argomenti as $argomento)
		{
			$blocco .= '<li><a href="' . htmlspecialchars($argomento['topic_url'], ENT_COMPAT, 'UTF-8') . '">'
				. htmlspecialchars($argomento['topic_title'], ENT_COMPAT, 'UTF-8') . '</a></li>';
		}

		return $blocco . '</ul></div>';
	}

	/* =====================================================================
	 * Archivio pubblico
	 * ================================================================== */

	/**
	 * Le colonne dell'archivio esistono davvero?
	 *
	 * L'estensione viene spesso aggiornata sostituendo i file senza
	 * disabilitarla e riabilitarla, e in quel caso le migrazioni non passano:
	 * il codice nuovo si trova davanti un database vecchio. Interrogare una
	 * colonna che non c'e fa cadere l'intera pagina con un errore SQL, il che
	 * e un modo pessimo di dire "manca un passaggio".
	 *
	 * Si controlla una voce di configurazione aggiunta dalla stessa migrazione
	 * che crea le colonne: se c'e quella ci sono anche loro, e non serve
	 * interrogare lo schema a ogni chiamata.
	 *
	 * @return bool
	 */
	public function archive_available()
	{
		return isset($this->config['newsletter_archive_visibility']);
	}

	/**
	 * Un numero e visibile a chi sta guardando?
	 *
	 * Sono pubblicabili solo le campagne concluse: una bozza non e ancora un
	 * numero, e una in corso non lo e per tutti - meta dei destinatari non l'ha
	 * ancora ricevuta, e trovarla gia pubblicata sul forum svuoterebbe di senso
	 * il fatto di essere iscritti.
	 *
	 * @param array $campagna
	 * @return bool
	 */
	public function is_public(array $campagna)
	{
		if (!$this->archive_available()
			|| empty($campagna['campaign_public'])
			|| (int) $campagna['campaign_status'] !== self::STATUS_DONE)
		{
			return false;
		}

		// Servono entrambe le spunte: quella del messaggio e quella del
		// notiziario. Chiudere un notiziario deve togliere dall'archivio tutti
		// i suoi numeri in un colpo solo, senza doverli riaprire uno per uno
		if ($this->lists_available() && !empty($campagna['campaign_list_id']))
		{
			$lista = $this->get_list((int) $campagna['campaign_list_id']);

			if ($lista && empty($lista['list_public']))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Faccine disponibili sul forum.
	 *
	 * Raggruppate per immagine: la stessa faccina ha spesso piu codici - :D e
	 * :grin: mostrano lo stesso disegno - e nella scatola servono una volta
	 * sola. Vengono prese solo quelle che il forum mostra in scrittura, cioe
	 * quelle che l'amministratore ha deciso di offrire a chi scrive.
	 *
	 * @return array
	 */
	public function get_smilies()
	{
		$faccine = array();

		$sql = 'SELECT MIN(code) AS code, MIN(emotion) AS emotion, smiley_url, MIN(smiley_order) AS ordine
			FROM ' . SMILIES_TABLE . '
			WHERE display_on_posting = 1
			GROUP BY smiley_url
			ORDER BY ordine ASC';
		$result = $this->db->sql_query($sql, 3600);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$faccine[] = array(
				'code'		=> (string) $riga['code'],
				'emotion'	=> (string) $riga['emotion'],
				'url'		=> (string) $riga['smiley_url'],
			);
		}
		$this->db->sql_freeresult($result);

		return $faccine;
	}

	/**
	 * Pubblica o ritira un numero dall'archivio
	 *
	 * @param int  $campaign_id
	 * @param bool $pubblico
	 */
	public function set_public($campaign_id, $pubblico)
	{
		if (!$this->archive_available())
		{
			return;
		}

		$this->db->sql_query('UPDATE ' . $this->campaigns_table . '
			SET campaign_public = ' . ($pubblico ? 1 : 0) . '
			WHERE campaign_id = ' . (int) $campaign_id);
	}

	/**
	 * Numeri pubblicati, dal piu recente
	 *
	 * @param int $start
	 * @param int $limit
	 * @return array
	 */
	public function get_archive($start = 0, $limit = 20, $list_id = 0)
	{
		$righe = array();

		if (!$this->archive_available())
		{
			return $righe;
		}

		$sql = 'SELECT c.campaign_id, c.campaign_subject, c.campaign_format, c.campaign_finished,
				c.campaign_author_name, c.campaign_views, c.campaign_sent, c.campaign_list_id
			FROM ' . $this->campaigns_table . ' c'
			. $this->archive_join($list_id) . '
			ORDER BY c.campaign_finished DESC, c.campaign_id DESC';
		$result = $this->db->sql_query_limit($sql, $limit, $start);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$righe[] = $riga;
		}
		$this->db->sql_freeresult($result);

		return $righe;
	}

	/**
	 * @return int
	 */
	public function count_archive($list_id = 0)
	{
		if (!$this->archive_available())
		{
			return 0;
		}

		$sql = 'SELECT COUNT(c.campaign_id) AS totale
			FROM ' . $this->campaigns_table . ' c'
			. $this->archive_join($list_id);
		$result = $this->db->sql_query($sql);
		$totale = (int) $this->db->sql_fetchfield('totale');
		$this->db->sql_freeresult($result);

		return $totale;
	}

	/**
	 * Parte comune delle interrogazioni sull'archivio.
	 *
	 * La giunzione con i notiziari serve a escludere quelli non pubblici: un
	 * numero puo essere marcato pubblico, ma se il suo notiziario non lo e non
	 * deve comparire.
	 *
	 * @param int $list_id 0 per tutti i notiziari pubblici
	 * @return string
	 */
	protected function archive_join($list_id = 0)
	{
		$sql = '';

		if ($this->lists_available())
		{
			$sql .= ' INNER JOIN ' . $this->lists_table . ' l ON (l.list_id = c.campaign_list_id AND l.list_public = 1)';
		}

		$sql .= ' WHERE c.campaign_public = 1 AND c.campaign_status = ' . self::STATUS_DONE;

		if ($this->lists_available() && (int) $list_id > 0)
		{
			$sql .= ' AND c.campaign_list_id = ' . (int) $list_id;
		}

		return $sql;
	}

	/**
	 * Notiziari che hanno almeno un numero nell'archivio
	 *
	 * @return array
	 */
	public function get_archive_lists()
	{
		if (!$this->lists_available() || !$this->archive_available())
		{
			return array();
		}

		$righe = array();

		$sql = 'SELECT l.list_id, l.list_name, l.list_desc, COUNT(c.campaign_id) AS numeri
			FROM ' . $this->lists_table . ' l
			INNER JOIN ' . $this->campaigns_table . ' c
				ON (c.campaign_list_id = l.list_id AND c.campaign_public = 1 AND c.campaign_status = ' . self::STATUS_DONE . ')
			WHERE l.list_public = 1
			GROUP BY l.list_id, l.list_name, l.list_desc, l.list_order
			ORDER BY l.list_order ASC, l.list_id ASC';
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$righe[] = $riga;
		}
		$this->db->sql_freeresult($result);

		return $righe;
	}

	/**
	 * Segna una visita al numero.
	 *
	 * Con un aggiornamento diretto invece di leggere, sommare e riscrivere: due
	 * visite contemporanee altrimenti si sovrascriverebbero a vicenda e il
	 * conteggio resterebbe indietro.
	 *
	 * @param int $campaign_id
	 */
	public function add_view($campaign_id)
	{
		if (!$this->archive_available())
		{
			return;
		}

		$this->db->sql_query('UPDATE ' . $this->campaigns_table . '
			SET campaign_views = campaign_views + 1
			WHERE campaign_id = ' . (int) $campaign_id);
	}

	/**
	 * Corpo del numero cosi come va mostrato nell'archivio.
	 *
	 * I segnaposto vengono risolti su un destinatario fittizio: nell'archivio
	 * non c'e nessun lettore a cui riferirsi, e lasciare {USERNAME} scritto per
	 * esteso sarebbe brutto tanto quanto sostituirlo con il nome di chi sta
	 * leggendo, che non e quello a cui il messaggio era stato indirizzato.
	 *
	 * @param array $campagna
	 * @return string
	 */
	public function archive_body(array $campagna)
	{
		$generico = array(
			'user_id'		=> 0,
			'username'		=> $this->language->lang('NL_ARCHIVE_GENERIC_NAME'),
			'user_email'	=> '',
			'user_lang'		=> '',
		);

		$campagna = $this->prepare_campaign($campagna);

		// Nell'archivio il richiamo "aprilo nel browser" non ha senso: si e
		// gia nel browser, e il collegamento porterebbe alla pagina stessa
		$campagna['campaign_archive_block'] = '';

		return $this->mailer->build_body($campagna, $generico, !empty($campagna['campaign_format']));
	}

	/**
	 * Richiamo alla versione consultabile, da mettere in cima al messaggio.
	 *
	 * E la convenzione che tutti conoscono: quando un lettore di posta rovina
	 * la formattazione, quel collegamento e l'unica via d'uscita. Compare solo
	 * se il numero sara davvero pubblicato, altrimenti porterebbe a una pagina
	 * che risponde "non disponibile".
	 *
	 * @param array $campaign
	 * @return string
	 */
	public function build_archive_block(array $campaign)
	{
		if (!$this->archive_available()
			|| empty($this->config['newsletter_archive_link_top'])
			|| empty($campaign['campaign_public'])
			|| empty($campaign['campaign_id'])
			|| (int) $this->config['newsletter_archive_visibility'] < 1)
		{
			return '';
		}

		$indirizzo = $this->mailer->archive_url((int) $campaign['campaign_id']);

		if ($indirizzo === '')
		{
			return '';
		}

		if (empty($campaign['campaign_format']))
		{
			return $this->language->lang('NL_ARCHIVE_TOP_TEXT') . "\n" . $indirizzo . "\n";
		}

		return '<div class="nl-viewonline" style="margin: 0 0 14px 0; text-align: center; font-size: 12px; color: #888888;">'
			. '<a href="' . htmlspecialchars($indirizzo, ENT_COMPAT, 'UTF-8') . '" style="color: #888888;">'
			. htmlspecialchars($this->language->lang('NL_ARCHIVE_TOP_LINK'), ENT_COMPAT, 'UTF-8')
			. '</a></div>';
	}

	/* =====================================================================
	 * Campagne
	 * ================================================================== */

	/**
	 * @param array $data
	 * @return int Identificativo della campagna creata
	 */
	public function create_campaign(array $data)
	{
		$data = $this->clean_text_fields($data);

		$this->db->sql_query('INSERT INTO ' . $this->campaigns_table . ' ' . $this->db->sql_build_array('INSERT', $data));

		return (int) $this->db->sql_nextid();
	}

	/**
	 * @param int   $campaign_id
	 * @param array $data
	 */
	public function update_campaign($campaign_id, array $data)
	{
		$data = $this->clean_text_fields($data);

		$this->db->sql_query('UPDATE ' . $this->campaigns_table . '
			SET ' . $this->db->sql_build_array('UPDATE', $data) . '
			WHERE campaign_id = ' . (int) $campaign_id);
	}

	/**
	 * Adatta oggetto e corpo alla codifica del database.
	 *
	 * Su un MySQL ancora configurato con il vecchio utf8 a tre byte una emoji
	 * fa fallire l'inserimento, e la campagna non parte affatto. L'opzione
	 * resta spenta perche sui database moderni non serve, e toglierebbe
	 * caratteri legittimi.
	 *
	 * @param array $data
	 * @return array
	 */
	protected function clean_text_fields(array $data)
	{
		if (empty($this->config['newsletter_strip_emoji']))
		{
			return $data;
		}

		if (isset($data['campaign_subject']))
		{
			$data['campaign_subject'] = $this->html->strip_supplementary($data['campaign_subject'], false);
		}

		if (isset($data['campaign_body']))
		{
			$html = !empty($data['campaign_format']);
			$data['campaign_body'] = $this->html->strip_supplementary($data['campaign_body'], $html);
		}

		return $data;
	}

	/**
	 * @param int $campaign_id
	 * @return array|false
	 */
	public function get_campaign($campaign_id)
	{
		$sql = 'SELECT * FROM ' . $this->campaigns_table . ' WHERE campaign_id = ' . (int) $campaign_id;
		$result = $this->db->sql_query($sql);
		$riga = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $riga;
	}

	/**
	 * @param int $start
	 * @param int $limit
	 * @return array
	 */
	public function get_campaigns($start = 0, $limit = 25)
	{
		$righe = array();

		$sql = 'SELECT * FROM ' . $this->campaigns_table . ' ORDER BY campaign_created DESC';
		$result = $this->db->sql_query_limit($sql, $limit, $start);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$righe[] = $riga;
		}
		$this->db->sql_freeresult($result);

		return $righe;
	}

	/**
	 * @return int
	 */
	public function count_campaigns()
	{
		$sql = 'SELECT COUNT(campaign_id) AS totale FROM ' . $this->campaigns_table;
		$result = $this->db->sql_query($sql);
		$totale = (int) $this->db->sql_fetchfield('totale');
		$this->db->sql_freeresult($result);

		return $totale;
	}

	/**
	 * Conteggi in tempo reale di una campagna.
	 *
	 * Vengono ricalcolati dalla coda invece di leggere i contatori della
	 * campagna: i contatori servono agli elenchi, ma sono un riepilogo, e un
	 * riepilogo puo restare indietro se un processo viene interrotto nel mezzo
	 * di un lotto. La coda invece registra riga per riga cio che e successo.
	 *
	 * @param int $campaign_id
	 * @return array
	 */
	public function get_stats($campaign_id)
	{
		$stats = array('total' => 0, 'sent' => 0, 'pending' => 0, 'failed' => 0);

		$sql = 'SELECT queue_status, COUNT(queue_id) AS quanti
			FROM ' . $this->queue_table . '
			WHERE campaign_id = ' . (int) $campaign_id . '
			GROUP BY queue_status';
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$quanti = (int) $riga['quanti'];
			$stats['total'] += $quanti;

			switch ((int) $riga['queue_status'])
			{
				case self::QUEUE_SENT:
					$stats['sent'] = $quanti;
				break;

				case self::QUEUE_FAILED:
					$stats['failed'] = $quanti;
				break;

				default:
					$stats['pending'] = $quanti;
				break;
			}
		}
		$this->db->sql_freeresult($result);

		return $stats;
	}

	/**
	 * Quante newsletter ha messo in coda un utente negli ultimi giorni.
	 *
	 * Il limite si conta sulle campagne create, non su quelle approvate: se
	 * guardasse solo gli invii partiti, chi scrive potrebbe riempire la coda di
	 * richieste in attesa e scaricare il lavoro sull'amministratore.
	 *
	 * @param int $user_id
	 * @param int $giorni
	 * @return int
	 */
	public function count_recent_sends($user_id, $giorni = 7)
	{
		$sql = 'SELECT COUNT(campaign_id) AS totale
			FROM ' . $this->campaigns_table . '
			WHERE campaign_author = ' . (int) $user_id . '
				AND campaign_created > ' . (time() - ((int) $giorni * 86400)) . '
				AND campaign_status <> ' . self::STATUS_CANCELLED;
		$result = $this->db->sql_query($sql);
		$totale = (int) $this->db->sql_fetchfield('totale');
		$this->db->sql_freeresult($result);

		return $totale;
	}

	/**
	 * Approva una richiesta e ne avvia l'invio
	 *
	 * @param int $campaign_id
	 * @return int Destinatari accodati, 0 se non ce ne sono
	 */
	public function approve_campaign($campaign_id)
	{
		$campagna = $this->get_campaign($campaign_id);

		if (!$campagna || (int) $campagna['campaign_status'] !== self::STATUS_PENDING)
		{
			return 0;
		}

		$totale = $this->fill_queue($campaign_id, $campagna);

		if ($totale === 0)
		{
			return 0;
		}

		$this->update_campaign($campaign_id, array(
			'campaign_status'	=> self::STATUS_RUNNING,
			'campaign_started'	=> 0,
			'campaign_last_run'	=> 0,
			'campaign_finished'	=> 0,
		));

		return $totale;
	}

	/**
	 * Quante richieste aspettano una risposta
	 *
	 * @return int
	 */
	public function count_pending()
	{
		$sql = 'SELECT COUNT(campaign_id) AS totale
			FROM ' . $this->campaigns_table . '
			WHERE campaign_status = ' . self::STATUS_PENDING;
		$result = $this->db->sql_query($sql);
		$totale = (int) $this->db->sql_fetchfield('totale');
		$this->db->sql_freeresult($result);

		return $totale;
	}

	/**
	 * @param int $campaign_id
	 * @param int $status
	 */
	public function set_status($campaign_id, $status)
	{
		$dati = array('campaign_status' => (int) $status);

		if ((int) $status === self::STATUS_CANCELLED || (int) $status === self::STATUS_DONE)
		{
			$dati['campaign_finished'] = time();
		}

		$this->update_campaign($campaign_id, $dati);
	}

	/**
	 * Rimette in coda i destinatari falliti.
	 *
	 * Utile dopo un guasto del server di posta, quando i fallimenti non
	 * dipendono dagli indirizzi. Il contatore dei tentativi viene azzerato,
	 * altrimenti le righe che li avevano gia esauriti verrebbero saltate
	 * subito.
	 *
	 * @param int $campaign_id
	 * @return int Righe rimesse in coda
	 */
	public function requeue_failed($campaign_id)
	{
		$campaign_id = (int) $campaign_id;

		$sql = 'UPDATE ' . $this->queue_table . '
			SET queue_status = ' . self::QUEUE_PENDING . ", queue_attempts = 0, queue_error = '', queue_time = 0
			WHERE campaign_id = " . $campaign_id . '
				AND queue_status = ' . self::QUEUE_FAILED;
		$this->db->sql_query($sql);

		$quante = (int) $this->db->sql_affectedrows();

		if ($quante > 0)
		{
			$this->update_campaign($campaign_id, array(
				'campaign_failed'	=> 0,
				'campaign_status'	=> self::STATUS_RUNNING,
				'campaign_finished'	=> 0,
				// Azzerare l'ultima esecuzione fa partire il primo lotto senza
				// aspettare l'intervallo
				'campaign_last_run'	=> 0,
			));
		}

		return $quante;
	}

	/**
	 * @param int $campaign_id
	 */
	public function delete_campaign($campaign_id)
	{
		$campaign_id = (int) $campaign_id;

		$this->db->sql_query('DELETE FROM ' . $this->queue_table . ' WHERE campaign_id = ' . $campaign_id);
		$this->db->sql_query('DELETE FROM ' . $this->campaigns_table . ' WHERE campaign_id = ' . $campaign_id);
	}

	/**
	 * Svuota il registro.
	 *
	 * Le campagne ancora in corso vengono risparmiate: cancellare la coda di un
	 * invio a meta significherebbe non poterlo ne riprendere ne sapere chi ha
	 * gia ricevuto il messaggio.
	 *
	 * @param bool $include_running
	 * @return int Campagne rimosse
	 */
	public function delete_all_campaigns($include_running = false)
	{
		$condizione = '';

		if (!$include_running)
		{
			// Le richieste in attesa si salvano insieme agli invii in corso: sono
			// lavoro di qualcun altro che aspetta una risposta, e cancellarle
			// con una pulizia generale sarebbe una sparizione senza spiegazione
			$condizione = ' WHERE ' . $this->db->sql_in_set('campaign_status', array(
				self::STATUS_RUNNING,
				self::STATUS_PAUSED,
				self::STATUS_PENDING,
			), true);
		}

		$ids = array();

		$sql = 'SELECT campaign_id FROM ' . $this->campaigns_table . $condizione;
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$ids[] = (int) $riga['campaign_id'];
		}
		$this->db->sql_freeresult($result);

		if (empty($ids))
		{
			return 0;
		}

		$this->db->sql_query('DELETE FROM ' . $this->queue_table . ' WHERE ' . $this->db->sql_in_set('campaign_id', $ids));
		$this->db->sql_query('DELETE FROM ' . $this->campaigns_table . ' WHERE ' . $this->db->sql_in_set('campaign_id', $ids));

		return count($ids);
	}

	/**
	 * Righe della coda di una campagna
	 *
	 * @param int $campaign_id
	 * @param int $status -1 per tutte
	 * @param int $start
	 * @param int $limit
	 * @return array
	 */
	public function get_queue_rows($campaign_id, $status = -1, $start = 0, $limit = 50)
	{
		$righe = array();

		$sql = 'SELECT * FROM ' . $this->queue_table . '
			WHERE campaign_id = ' . (int) $campaign_id
			. (($status >= 0) ? ' AND queue_status = ' . (int) $status : '') . '
			ORDER BY queue_status DESC, queue_id ASC';
		$result = $this->db->sql_query_limit($sql, $limit, $start);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$righe[] = $riga;
		}
		$this->db->sql_freeresult($result);

		return $righe;
	}

	/**
	 * @param int $campaign_id
	 * @param int $status
	 * @return int
	 */
	public function count_queue_rows($campaign_id, $status = -1)
	{
		$sql = 'SELECT COUNT(queue_id) AS totale FROM ' . $this->queue_table . '
			WHERE campaign_id = ' . (int) $campaign_id
			. (($status >= 0) ? ' AND queue_status = ' . (int) $status : '');
		$result = $this->db->sql_query($sql);
		$totale = (int) $this->db->sql_fetchfield('totale');
		$this->db->sql_freeresult($result);

		return $totale;
	}

	/* =====================================================================
	 * Invio
	 * ================================================================== */

	/**
	 * Prima campagna pronta per un lotto.
	 *
	 * "Pronta" significa in corso, non programmata nel futuro e con
	 * l'intervallo trascorso dall'ultimo lotto. Le campagne piu vecchie hanno
	 * la precedenza, cosi due invii avviati a poca distanza si alternano invece
	 * di bloccarsi a vicenda.
	 *
	 * @param bool $ignore_interval
	 * @return array|false
	 */
	public function next_campaign($ignore_interval = false)
	{
		$adesso = time();

		$sql = 'SELECT * FROM ' . $this->campaigns_table . '
			WHERE campaign_status = ' . self::STATUS_RUNNING . '
				AND campaign_schedule <= ' . $adesso
			. ($ignore_interval ? '' : ' AND campaign_last_run + campaign_interval <= ' . $adesso) . '
			ORDER BY campaign_last_run ASC, campaign_id ASC';
		$result = $this->db->sql_query_limit($sql, 1);
		$riga = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $riga;
	}

	/**
	 * Invia un lotto.
	 *
	 * @param int  $campaign_id     0 per lasciare scegliere alla coda
	 * @param bool $ignore_interval Vero quando il lotto e richiesto a mano
	 * @return array Riepilogo dell'esecuzione
	 */
	public function process($campaign_id = 0, $ignore_interval = false)
	{
		$esito = array(
			'campaign_id'	=> 0,
			'subject'		=> '',
			'sent'			=> 0,
			'failed'		=> 0,
			'pending'		=> 0,
			'finished'		=> false,
			'reason'		=> '',
		);

		if (empty($this->config['newsletter_enabled']))
		{
			$esito['reason'] = 'NL_DISABLED';
			return $esito;
		}

		if ($campaign_id > 0)
		{
			$campagna = $this->get_campaign($campaign_id);

			if (!$campagna || (int) $campagna['campaign_status'] !== self::STATUS_RUNNING)
			{
				$esito['reason'] = 'NL_NOTHING_TO_SEND';
				return $esito;
			}

			if (!$ignore_interval && ((int) $campagna['campaign_last_run'] + (int) $campagna['campaign_interval']) > time())
			{
				$esito['reason'] = 'NL_WAITING_INTERVAL';
				return $esito;
			}
		}
		else
		{
			$campagna = $this->next_campaign($ignore_interval);
		}

		if (!$campagna)
		{
			$esito['reason'] = 'NL_NOTHING_TO_SEND';
			return $esito;
		}

		$campaign_id = (int) $campagna['campaign_id'];
		$esito['campaign_id'] = $campaign_id;
		$esito['subject'] = (string) $campagna['campaign_subject'];

		// Prenotazione della campagna. Se due processi arrivano insieme - il
		// cron del sistema e una visita al forum, per esempio - solo quello che
		// riesce a spostare campaign_last_run prosegue. Senza questo controllo
		// lo stesso lotto partirebbe due volte e gli utenti riceverebbero il
		// messaggio in doppia copia
		$ultima = (int) $campagna['campaign_last_run'];

		$this->db->sql_query('UPDATE ' . $this->campaigns_table . '
			SET campaign_last_run = ' . time() . '
			WHERE campaign_id = ' . $campaign_id . '
				AND campaign_last_run = ' . $ultima);

		if (!$this->db->sql_affectedrows())
		{
			$esito['reason'] = 'NL_ALREADY_RUNNING';
			return $esito;
		}

		if (!$campagna['campaign_started'])
		{
			$this->update_campaign($campaign_id, array('campaign_started' => time()));
		}

		// Conversione del formato e blocchi accessori si risolvono una volta
		// per lotto e non una volta per destinatario: sono gli stessi per tutti
		$campagna = $this->prepare_campaign($campagna);

		$limite = (int) $campagna['campaign_batch'];
		$limite = ($limite < 1) ? 1 : min(100, $limite);

		$max_tentativi = max(1, (int) $this->config['newsletter_max_attempts']);
		$budget = max(5, (int) $this->config['newsletter_time_budget']);

		// La serie di fallimenti prosegue fra un lotto e l'altro: dieci
		// fallimenti spalmati su tre lotti sono lo stesso guasto di dieci di
		// fila, e ripartire da zero a ogni lotto renderebbe la rete inutile
		$serie = isset($campagna['campaign_fail_streak']) ? (int) $campagna['campaign_fail_streak'] : 0;
		$soglia = $this->failsafe_available() ? (int) $this->config['newsletter_fail_streak'] : 0;
		$fermata = false;

		// Dichiarata qui e non solo dentro il ciclo: il motivo della pausa la
		// legge dopo, e con una coda vuota il ciclo non gira nemmeno
		$errore = '';
		$inizio = time();

		$righe = array();

		$sql = 'SELECT * FROM ' . $this->queue_table . '
			WHERE campaign_id = ' . $campaign_id . '
				AND queue_status = ' . self::QUEUE_PENDING . '
			ORDER BY queue_id ASC';
		$result = $this->db->sql_query_limit($sql, $limite);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$righe[] = $riga;
		}
		$this->db->sql_freeresult($result);

		foreach ($righe as $riga)
		{
			// Un lotto da cento indirizzi su un server SMTP lento supera senza
			// difficolta il tempo massimo di esecuzione. Fermarsi prima lascia
			// le righe rimanenti in attesa e il lotto successivo le riprende:
			// meglio un lotto piu corto che un processo ucciso a meta con i
			// contatori non aggiornati
			if ((time() - $inizio) >= $budget)
			{
				break;
			}

			$errore = '';

			// Un solo destinatario non deve poter fermare il lotto. Senza
			// questa rete, un indirizzo che manda in errore la funzione di
			// posta interromperebbe l'invio a meta lasciando i contatori
			// disallineati, e il lotto successivo ripartirebbe dallo stesso
			// punto ripetendo lo stesso errore all'infinito
			try
			{
				$inviata = $this->mailer->send($riga, $campagna, $errore);
			}
			catch (\Exception $e)
			{
				$inviata = false;
				$errore = $this->truncate_error($e->getMessage());
			}
			catch (\Throwable $e)
			{
				$inviata = false;
				$errore = $this->truncate_error($e->getMessage());
			}

			$tentativi = (int) $riga['queue_attempts'] + 1;

			if ($inviata)
			{
				$nuovo_stato = self::QUEUE_SENT;
				$esito['sent']++;

				// Un solo successo basta a dire che il server risponde: la
				// serie riparte da zero
				$serie = 0;
			}
			else if ($tentativi >= $max_tentativi || $this->errore_definitivo($errore))
			{
				$nuovo_stato = self::QUEUE_FAILED;
				$esito['failed']++;
			}
			else
			{
				// Un solo tentativo non basta a distinguere un indirizzo
				// inesistente da un server momentaneamente irraggiungibile: la
				// riga torna in attesa e verra ritentata nel lotto seguente
				$nuovo_stato = self::QUEUE_PENDING;
			}

			$aggiornamento = array(
				'queue_status'		=> $nuovo_stato,
				'queue_attempts'	=> $tentativi,
				'queue_time'		=> time(),
				'queue_error'		=> $inviata ? '' : $errore,
			);

			$this->db->sql_query('UPDATE ' . $this->queue_table . '
				SET ' . $this->db->sql_build_array('UPDATE', $aggiornamento) . '
				WHERE queue_id = ' . (int) $riga['queue_id']);

			if (!$inviata)
			{
				$serie++;
			}

			// Tanti fallimenti uno dopo l'altro non sono indirizzi sbagliati:
			// sono il server di posta che non risponde. Continuare vorrebbe
			// dire bruciare i tentativi di tutti i destinatari rimasti per un
			// guasto che magari dura cinque minuti
			if ($soglia > 0 && $serie >= $soglia)
			{
				$fermata = true;
				break;
			}
		}

		$stats = $this->get_stats($campaign_id);

		$esito['pending'] = $stats['pending'];

		$aggiornamento = array(
			'campaign_sent'		=> $stats['sent'],
			'campaign_failed'	=> $stats['failed'],
		);

		if ($this->failsafe_available())
		{
			$aggiornamento['campaign_fail_streak'] = $serie;
		}

		if ($stats['pending'] === 0)
		{
			$aggiornamento['campaign_status'] = self::STATUS_DONE;
			$aggiornamento['campaign_finished'] = time();
			$esito['finished'] = true;
		}
		else if ($fermata)
		{
			// In pausa e non annullata: le righe rimaste restano in attesa, e
			// riprendere e questione di un clic quando il server torna
			$aggiornamento['campaign_status'] = self::STATUS_PAUSED;

			if ($this->failsafe_available())
			{
				$aggiornamento['campaign_pause_reason'] = $this->truncate_error(
					$this->language->lang('NL_PAUSED_REASON', $serie, $errore)
				);
			}

			$esito['auto_paused'] = true;
			$esito['streak'] = $serie;
		}

		$this->update_campaign($campaign_id, $aggiornamento);

		if ($esito['finished'])
		{
			$this->log->add('admin', (int) $campagna['campaign_author'], '', 'LOG_NEWSLETTER_COMPLETED', false, array(
				$this->plain((string) $campagna['campaign_subject']),
				$stats['sent'],
				$stats['failed'],
			));

			$this->send_report($campagna, $stats);
		}

		if (!empty($esito['auto_paused']))
		{
			$this->log->add('admin', (int) $campagna['campaign_author'], '', 'LOG_NEWSLETTER_AUTO_PAUSED', false, array(
				$this->plain((string) $campagna['campaign_subject']),
				$serie,
			));

			$this->send_report($campagna, $stats, true);
		}

		$this->config->set('newsletter_last_run', time(), false);

		return $esito;
	}

	/**
	 * Le colonne delle reti di sicurezza esistono?
	 *
	 * @return bool
	 */
	public function failsafe_available()
	{
		return isset($this->config['newsletter_fail_streak']);
	}

	/**
	 * Resoconto all'autore a fine campagna, o quando l'invio si ferma da solo.
	 *
	 * Senza, un invio andato male si scopre solo aprendo il registro per caso.
	 * Il messaggio va a chi l'ha scritta e non all'indirizzo del forum: e la
	 * persona che sa cosa doveva succedere.
	 *
	 * @param array $campagna
	 * @param array $stats
	 * @param bool  $fermata
	 */
	protected function send_report(array $campagna, array $stats, $fermata = false)
	{
		if (!$this->failsafe_available() || empty($this->config['newsletter_report_email']))
		{
			return;
		}

		$autore = $this->get_user_row((int) $campagna['campaign_author']);

		if (!$autore || trim((string) $autore['user_email']) === '')
		{
			return;
		}

		$sostituzioni = array(
			'{SUBJECT}'		=> $this->plain((string) $campagna['campaign_subject']),
			'{SENT}'		=> (int) $stats['sent'],
			'{FAILED}'		=> (int) $stats['failed'],
			'{PENDING}'		=> (int) $stats['pending'],
		);

		$chiave = $fermata ? 'NL_MAIL_PAUSED' : 'NL_MAIL_REPORT';

		$campagna_avviso = array(
			'campaign_id'			=> 0,
			'campaign_subject'		=> strtr($this->lang_for($autore['user_lang'], $chiave . '_SUBJECT'), $sostituzioni),
			'campaign_body'			=> strtr($this->lang_for($autore['user_lang'], $chiave . '_BODY'), $sostituzioni),
			'campaign_css'			=> '',
			'campaign_format'		=> self::FORMAT_TEXT,
			'campaign_banner'		=> 0,
			'campaign_topics'		=> '',
			'campaign_priority'		=> 3,
			'campaign_importance'	=> $fermata ? 'high' : 'normal',
			'campaign_sensitivity'	=> '',
			'campaign_from_name'	=> '',
			'campaign_from_email'	=> '',
			'campaign_reply_to'		=> '',
			'campaign_topics_block'	=> '',
			'campaign_banner_block'	=> '',
			'campaign_archive_block'=> '',
		);

		$errore = '';

		// Come per i messaggi di conferma: un resoconto che non parte non deve
		// far cadere il lotto che lo ha generato
		try
		{
			$this->mailer->send(array(
				'user_id'		=> (int) $autore['user_id'],
				'username'		=> (string) $autore['username'],
				'user_email'	=> (string) $autore['user_email'],
				'user_lang'		=> (string) $autore['user_lang'],
			), $campagna_avviso, $errore);
		}
		catch (\Exception $e)
		{
		}
		catch (\Throwable $e)
		{
		}
	}

	/**
	 * Da quanto tempo una campagna avviata non fa un passo.
	 *
	 * Se il cron non gira - poco traffico di notte, cron di sistema fermo - gli
	 * invii si bloccano senza che nessuno lo dica. Questo confronto non
	 * richiede dati nuovi: basta l'ora dell'ultimo lotto.
	 *
	 * @param array $campagna
	 * @return int Secondi di ritardo oltre il previsto, 0 se procede
	 */
	public function stall_seconds(array $campagna)
	{
		if ((int) $campagna['campaign_status'] !== self::STATUS_RUNNING
			|| empty($campagna['campaign_last_run']))
		{
			return 0;
		}

		$tolleranza = $this->failsafe_available()
			? max(300, (int) $this->config['newsletter_stall_grace'])
			: 3600;

		$atteso = (int) $campagna['campaign_last_run'] + (int) $campagna['campaign_interval'] + $tolleranza;
		$ritardo = time() - $atteso;

		return ($ritardo > 0) ? $ritardo : 0;
	}

	/**
	 * L'errore esclude ogni possibilita di riuscita?
	 *
	 * Ritentare su un indirizzo malformato o su un dominio inesistente non
	 * porta da nessuna parte, e ogni tentativo inutile occupa un posto nel
	 * lotto che spetterebbe a un destinatario raggiungibile.
	 *
	 * @param string $errore
	 * @return bool
	 */
	protected function errore_definitivo($errore)
	{
		return in_array($errore, array('NL_ERR_NO_ADDRESS', 'NL_ERR_INVALID_ADDRESS', 'NL_ERR_DEAD_DOMAIN'), true);
	}

	/**
	 * Invio di prova a un solo indirizzo
	 *
	 * @param string $address
	 * @param array  $campaign
	 * @param string $error
	 * @return bool
	 */
	public function send_test($address, array $campaign, &$error = '')
	{
		$destinatario = array(
			'user_id'		=> (int) $this->user->data['user_id'],
			'username'		=> (string) $this->user->data['username'],
			'user_email'	=> $address,
			'user_lang'		=> (string) $this->user->data['user_lang'],
		);

		$campaign['campaign_subject'] = $this->language->lang('NL_TEST_PREFIX') . ' ' . $campaign['campaign_subject'];
		$campaign = $this->prepare_campaign($campaign);

		try
		{
			return $this->mailer->send($destinatario, $campaign, $error);
		}
		catch (\Exception $e)
		{
			$error = $this->truncate_error($e->getMessage());
			return false;
		}
		catch (\Throwable $e)
		{
			$error = $this->truncate_error($e->getMessage());
			return false;
		}
	}

	/**
	 * Anteprima del messaggio, cosi come lo ricevera il destinatario
	 *
	 * @param array $campaign
	 * @param array $recipient
	 * @return string
	 */
	public function preview(array $campaign, array $recipient)
	{
		$campaign = $this->prepare_campaign($campaign);

		return $this->mailer->build_body($campaign, $recipient, !empty($campaign['campaign_format']));
	}

	/**
	 * @return \salvocortesiano\newsletter\core\bbcode
	 */
	public function bbcode()
	{
		return $this->bbcode;
	}

	/**
	 * Segna nel giornale che un utente ha scritto una newsletter.
	 *
	 * Nel registro utente e non in quello amministrativo: l'azione e sua, e
	 * deve comparire accanto al suo nome. E l'unica traccia che resta se un
	 * profilo viene usato da qualcun altro.
	 *
	 * @param \phpbb\user $user
	 * @param string $oggetto
	 * @param bool   $in_attesa
	 */
	public function log_user_send($user, $oggetto, $in_attesa)
	{
		$this->log->add(
			'user',
			(int) $user->data['user_id'],
			$user->ip,
			$in_attesa ? 'LOG_NEWSLETTER_USER_QUEUED' : 'LOG_NEWSLETTER_USER_SENT',
			false,
			array('reportee_id' => (int) $user->data['user_id'], $this->plain($oggetto))
		);
	}

	/**
	 * Registra un'azione nel giornale di phpBB
	 *
	 * @param string $chiave
	 * @param array  $dati
	 */
	public function log_admin($chiave, array $dati = array())
	{
		foreach ($dati as $indice => $valore)
		{
			if (is_string($valore))
			{
				$dati[$indice] = $this->plain($valore);
			}
		}

		$this->log->add('admin', (int) $this->user->data['user_id'], $this->user->ip, $chiave, false, $dati);
	}

	/**
	 * Messaggio di errore ridotto alla misura della colonna del registro
	 *
	 * @param string $testo
	 * @return string
	 */
	protected function truncate_error($testo)
	{
		$testo = trim(preg_replace('/\s+/', ' ', (string) $testo));

		return function_exists('utf8_substr') ? utf8_substr($testo, 0, 240) : substr($testo, 0, 240);
	}

	/**
	 * Testo adatto al giornale: senza marcatura e senza caratteri che certi
	 * database non accettano
	 *
	 * @param string $testo
	 * @return string
	 */
	protected function plain($testo)
	{
		return $this->html->strip_supplementary(strip_tags((string) $testo), false);
	}

	/**
	 * Rimozione delle campagne concluse piu vecchie del periodo impostato
	 *
	 * @return int
	 */
	public function prune()
	{
		// Indipendente dal periodo di conservazione delle campagne: una
		// iscrizione senza profilo e sempre da togliere
		$this->remove_orphans();

		$giorni = (int) $this->config['newsletter_keep_days'];

		if ($giorni <= 0)
		{
			return 0;
		}

		$soglia = time() - ($giorni * 86400);
		$ids = array();

		$sql = 'SELECT campaign_id FROM ' . $this->campaigns_table . '
			WHERE ' . $this->db->sql_in_set('campaign_status', array(self::STATUS_DONE, self::STATUS_CANCELLED)) . '
				AND campaign_finished > 0
				AND campaign_finished < ' . $soglia;
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$ids[] = (int) $riga['campaign_id'];
		}
		$this->db->sql_freeresult($result);

		if (empty($ids))
		{
			return 0;
		}

		$this->db->sql_query('DELETE FROM ' . $this->queue_table . ' WHERE ' . $this->db->sql_in_set('campaign_id', $ids));
		$this->db->sql_query('DELETE FROM ' . $this->campaigns_table . ' WHERE ' . $this->db->sql_in_set('campaign_id', $ids));

		return count($ids);
	}

	/* =====================================================================
	 * Utilita
	 * ================================================================== */

	/**
	 * @param string $elenco
	 * @return array
	 */
	public function split_ids($elenco)
	{
		return array_values(array_filter(array_map('intval', explode(',', (string) $elenco))));
	}

	/**
	 * Secondi convertiti in ore, minuti e secondi
	 *
	 * @param int $secondi
	 * @return string
	 */
	public static function seconds_to_time($secondi)
	{
		$secondi = max(0, (int) $secondi);

		return sprintf('%02d:%02d:%02d', floor($secondi / 3600), floor(($secondi % 3600) / 60), $secondi % 60);
	}

	/**
	 * Ore, minuti e secondi convertiti in secondi.
	 *
	 * Alcuni browser omettono i secondi quando valgono zero, percio la stringa
	 * puo arrivare come "00:10" invece che come "00:10:00".
	 *
	 * @param string $valore
	 * @return int
	 */
	public static function time_to_seconds($valore)
	{
		$pezzi = array_map('intval', explode(':', trim((string) $valore)));

		$ore = isset($pezzi[0]) ? $pezzi[0] : 0;
		$minuti = isset($pezzi[1]) ? $pezzi[1] : 0;
		$secondi = isset($pezzi[2]) ? $pezzi[2] : 0;

		return ($ore * 3600) + ($minuti * 60) + $secondi;
	}
}
