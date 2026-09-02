<?php
/**
 * Strumento di verifica dell'estensione Newsletter.
 *
 * Da caricare nella cartella principale del forum, accanto a index.php, e da
 * aprire con il browser. Fa due cose, separate:
 *
 *   1. Una VERIFICA, che si limita a leggere: tabelle, colonne, impostazioni,
 *      servizi, rotte, file di lingua, stato del cron. Non tocca niente.
 *
 *   2. Una PROVA D'INVIO, che crea una campagna finta e la manda soltanto agli
 *      indirizzi che scrivi tu. La coda viene riempita a mano: gli iscritti
 *      veri non vengono mai letti, e non c'e nessun percorso in questo file
 *      che possa scrivere loro. A fine prova la campagna viene cancellata.
 *
 * Riservato ai fondatori. Cancellalo dal server quando hai finito: e uno
 * strumento di servizio, non una pagina del forum.
 *
 * @copyright (c) 2026 salvocortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);

require($phpbb_root_path . 'common.' . $phpEx);

$user->session_begin();
$auth->acl($user->data);
$user->setup();

// Solo i fondatori. Non e una formalita: da qui si mandano email e si
// leggono pezzi di configurazione del server di posta
if ((int) $user->data['user_type'] !== USER_FOUNDER)
{
	trigger_error('NO_AUTH_OPERATION');
}

$prova = new newsletter_selftest($phpbb_container, $phpbb_root_path, $phpEx);
$prova->esegui();

/**
 * Verifica e prova dell'estensione
 */
class newsletter_selftest
{
	/** Prefisso comune delle nostre tabelle */
	const EXT = 'salvocortesiano/newsletter';

	/** @var \Symfony\Component\DependencyInjection\ContainerInterface */
	protected $container;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/** @var \phpbb\request\request phpBB disattiva le variabili superglobali
	 *       e vuole che i dati in ingresso passino da qui: e il modo in cui
	 *       il forum garantisce che tutto quello che arriva sia filtrato */
	protected $request;

	/** @var array Righe del resoconto */
	protected $righe = array();

	/** @var array Conteggi */
	protected $totali = array('ok' => 0, 'avviso' => 0, 'errore' => 0);

	/**
	 * @param object $container
	 * @param string $root_path
	 * @param string $php_ext
	 */
	public function __construct($container, $root_path, $php_ext)
	{
		$this->container = $container;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
		$this->request = $container->get('request');
	}

	/**
	 * Punto di ingresso
	 */
	public function esegui()
	{
		$azione = $this->request->variable('azione', '');

		$this->intestazione();

		switch ($azione)
		{
			case 'verifica':
				$this->verifica();
			break;

			case 'invio':
				$this->prova_invio();
			break;

			default:
				$this->schermata_iniziale();
			break;
		}

		$this->chiusura();
	}

	/* =====================================================================
	 * Verifica: legge soltanto
	 * ================================================================== */

	protected function verifica()
	{
		echo '<h2>Verifica</h2>';

		$this->controlla_estensione();
		$this->controlla_tabelle();
		$this->controlla_colonne();
		$this->controlla_configurazioni();
		$this->controlla_servizi();
		$this->controlla_rotte();
		$this->controlla_lingue();
		$this->controlla_template();
		$this->controlla_cron();
		$this->controlla_posta();
		$this->controlla_dati();

		$this->stampa_righe();
		$this->riepilogo();

		echo '<p><a class="pulsante" href="?">&larr; Torna indietro</a></p>';
	}

	protected function controlla_estensione()
	{
		$this->sezione('Estensione');

		$gestore = $this->container->get('ext.manager');

		if (!$gestore->is_enabled(self::EXT))
		{
			$this->errore('Stato', 'L\'estensione non risulta abilitata. Tutto il resto della verifica non ha senso finche non lo e.');
			return;
		}

		$this->ok('Stato', 'abilitata');

		$composer = $this->root_path . 'ext/' . self::EXT . '/composer.json';

		if (is_readable($composer))
		{
			$dati = json_decode(file_get_contents($composer), true);
			$this->ok('Versione', isset($dati['version']) ? $dati['version'] : 'non indicata');
		}

		$this->ok('phpBB', $this->container->get('config')['version']);
		$this->ok('PHP', PHP_VERSION);
	}

	protected function controlla_tabelle()
	{
		$this->sezione('Tabelle');

		$strumenti = $this->container->get('dbal.tools');
		$prefisso = $this->container->getParameter('core.table_prefix');

		$attese = array(
			'newsletter_campaigns'	=> 'campagne',
			'newsletter_queue'		=> 'coda di invio',
			'newsletter_subs'		=> 'iscrizioni (vecchia, tenuta come copia)',
			'newsletter_lists'		=> 'notiziari',
			'newsletter_list_subs'	=> 'iscrizioni per notiziario',
		);

		foreach ($attese as $nome => $descrizione)
		{
			if ($strumenti->sql_table_exists($prefisso . $nome))
			{
				$this->ok($prefisso . $nome, $descrizione);
			}
			else if ($nome === 'newsletter_subs')
			{
				$this->avviso($prefisso . $nome, 'assente: e la vecchia tabella, la sua mancanza non e un problema se la migrazione dei notiziari e passata');
			}
			else
			{
				$this->errore($prefisso . $nome, 'assente. Disabilita e riabilita l\'estensione per far passare le migrazioni.');
			}
		}
	}

	protected function controlla_colonne()
	{
		$this->sezione('Colonne aggiunte dalle migrazioni');

		$strumenti = $this->container->get('dbal.tools');
		$prefisso = $this->container->getParameter('core.table_prefix');
		$tabella = $prefisso . 'newsletter_campaigns';

		$attese = array(
			'campaign_banner'		=> 'immagine di intestazione',
			'campaign_public'		=> 'archivio pubblico',
			'campaign_views'		=> 'letture nell\'archivio',
			'campaign_bbcode_uid'	=> 'BBCode',
			'campaign_list_id'		=> 'notiziari multipli',
			'campaign_fail_streak'	=> 'pausa automatica',
			'campaign_pause_reason'	=> 'motivo della pausa',
		);

		foreach ($attese as $colonna => $descrizione)
		{
			if ($strumenti->sql_column_exists($tabella, $colonna))
			{
				$this->ok($colonna, $descrizione);
			}
			else
			{
				$this->errore($colonna, $descrizione . ' — colonna assente, quella funzione non lavorera');
			}
		}
	}

	protected function controlla_configurazioni()
	{
		$this->sezione('Impostazioni');

		$config = $this->container->get('config');

		$attese = array(
			'newsletter_enabled', 'newsletter_batch_size', 'newsletter_interval',
			'newsletter_max_attempts', 'newsletter_time_budget', 'newsletter_secret',
			'newsletter_subs_enabled', 'newsletter_banner', 'newsletter_archive_visibility',
			'newsletter_archive_groups', 'newsletter_bbcode_smilies', 'newsletter_default_list',
			'newsletter_send_groups', 'newsletter_send_approval', 'newsletter_fail_streak',
			'newsletter_report_email', 'newsletter_stall_grace',
		);

		$mancanti = array();

		foreach ($attese as $chiave)
		{
			if (!isset($config[$chiave]))
			{
				$mancanti[] = $chiave;
			}
		}

		if (empty($mancanti))
		{
			$this->ok('Voci di configurazione', count($attese) . ' presenti');
		}
		else
		{
			$this->errore('Voci mancanti', implode(', ', $mancanti) . ' — disabilita e riabilita l\'estensione');
		}

		// La chiave segreta firma i collegamenti di disiscrizione: senza, quei
		// collegamenti non si possono verificare e nessuno riesce a cancellarsi
		if (isset($config['newsletter_secret']) && strlen((string) $config['newsletter_secret']) >= 16)
		{
			$this->ok('Chiave di firma', 'presente (' . strlen((string) $config['newsletter_secret']) . ' caratteri)');
		}
		else
		{
			$this->errore('Chiave di firma', 'assente o troppo corta: i collegamenti di disiscrizione non funzioneranno');
		}
	}

	protected function controlla_servizi()
	{
		$this->sezione('Servizi');

		$servizi = array(
			'salvocortesiano.newsletter.manager'	=> 'gestore delle campagne',
			'salvocortesiano.newsletter.mailer'		=> 'invio',
			'salvocortesiano.newsletter.access'		=> 'permessi',
			'salvocortesiano.newsletter.banner'		=> 'immagine di intestazione',
			'salvocortesiano.newsletter.bbcode'		=> 'BBCode',
			'salvocortesiano.newsletter.html'		=> 'marcatura',
			'salvocortesiano.newsletter.diagnostics'=> 'diagnostica della posta',
		);

		foreach ($servizi as $id => $descrizione)
		{
			try
			{
				$this->container->get($id);
				$this->ok($id, $descrizione);
			}
			catch (\Exception $e)
			{
				$this->errore($id, $descrizione . ' — ' . $e->getMessage());
			}
			catch (\Throwable $e)
			{
				$this->errore($id, $descrizione . ' — ' . $e->getMessage());
			}
		}
	}

	protected function controlla_rotte()
	{
		$this->sezione('Indirizzi pubblici');

		$helper = $this->container->get('controller.helper');

		$rotte = array(
			'salvocortesiano_newsletter_archive'	=> array(),
			'salvocortesiano_newsletter_list'		=> array('list_id' => 1),
			'salvocortesiano_newsletter_issue'		=> array('campaign_id' => 1),
			'salvocortesiano_newsletter_view'		=> array('campaign_id' => 1),
			'salvocortesiano_newsletter_unsubscribe'	=> array('user_id' => 2, 'token' => str_repeat('a', 32)),
			'salvocortesiano_newsletter_unsubscribe_list'	=> array('user_id' => 2, 'list_id' => 1, 'token' => str_repeat('a', 32)),
		);

		foreach ($rotte as $nome => $parametri)
		{
			try
			{
				$this->ok($nome, $helper->route($nome, $parametri));
			}
			catch (\Exception $e)
			{
				$this->errore($nome, 'non costruibile — svuota cache/production/ via FTP: ' . $e->getMessage());
			}
		}
	}

	protected function controlla_lingue()
	{
		$this->sezione('File di lingua');

		$base = $this->root_path . 'ext/' . self::EXT . '/language/';
		$file = array('newsletter', 'info_acp_newsletter', 'info_ucp_newsletter', 'logs_newsletter', 'permissions_newsletter');

		foreach (array('it', 'en') as $lingua)
		{
			$totale = 0;

			foreach ($file as $nome)
			{
				$percorso = $base . $lingua . '/' . $nome . '.' . $this->php_ext;

				if (!is_readable($percorso))
				{
					$this->errore($lingua . '/' . $nome, 'file assente');
					continue;
				}

				$lang = array();
				include($percorso);
				$totale += count($lang);
			}

			$this->ok('Lingua ' . $lingua, $totale . ' chiavi');
		}
	}

	protected function controlla_template()
	{
		$this->sezione('Modelli di pagina');

		$base = $this->root_path . 'ext/' . self::EXT . '/';

		$attesi = array(
			'adm/style/acp_newsletter_compose.html',
			'adm/style/acp_newsletter_confirm.html',
			'adm/style/acp_newsletter_logs.html',
			'adm/style/acp_newsletter_log.html',
			'adm/style/acp_newsletter_settings.html',
			'adm/style/acp_newsletter_subs.html',
			'adm/style/acp_newsletter_lists.html',
			'styles/all/template/ucp_newsletter.html',
			'styles/all/template/ucp_newsletter_send.html',
			'styles/all/template/newsletter_archive.html',
			'styles/all/template/newsletter_view.html',
			'styles/all/template/newsletter_unsubscribe.html',
		);

		$mancanti = array();

		foreach ($attesi as $file)
		{
			if (!is_readable($base . $file))
			{
				$mancanti[] = $file;
			}
		}

		if (empty($mancanti))
		{
			$this->ok('Modelli', count($attesi) . ' presenti');
		}
		else
		{
			$this->errore('Modelli mancanti', implode(', ', $mancanti));
		}
	}

	protected function controlla_cron()
	{
		$this->sezione('Cron');

		$config = $this->container->get('config');

		$ultimo = isset($config['newsletter_last_run']) ? (int) $config['newsletter_last_run'] : 0;

		if ($ultimo === 0)
		{
			$this->avviso('Ultima esecuzione', 'mai. Normale se non hai ancora inviato niente.');
		}
		else
		{
			$da = time() - $ultimo;
			$testo = date('d/m/Y H:i:s', $ultimo) . ' (' . $this->durata($da) . ' fa)';

			if ($da > 86400)
			{
				$this->avviso('Ultima esecuzione', $testo . ' — se hai invii in corso, il cron non sta girando');
			}
			else
			{
				$this->ok('Ultima esecuzione', $testo);
			}
		}

		if (!empty($config['use_system_cron']))
		{
			$this->ok('Tipo', 'cron di sistema');
		}
		else
		{
			$this->avviso('Tipo', 'innescato dalle visite. Con poco traffico notturno gli invii lunghi rallentano o si fermano.');
		}
	}

	protected function controlla_posta()
	{
		$this->sezione('Posta');

		$config = $this->container->get('config');

		if (empty($config['email_enable']))
		{
			$this->errore('Posta del forum', 'disattivata: nessuna newsletter puo partire');
			return;
		}

		$this->ok('Posta del forum', 'attiva');

		if (!empty($config['smtp_delivery']))
		{
			$host = (string) $config['smtp_host'];
			$porta = (int) $config['smtp_port'];

			$this->ok('Metodo', 'SMTP ' . $host . ':' . $porta);

			$prefisso = (strpos($host, '://') !== false);

			if (!$prefisso && in_array($porta, array(465, 587), true))
			{
				$suggerito = ($porta === 465) ? 'ssl://' : 'tls://';

				$this->avviso('Cifratura', 'la porta ' . $porta . ' di norma la prevede, ma il nome del server non ha prefisso. Se le email non partono, prova a scrivere ' . $suggerito . $host);
			}
		}
		else
		{
			$this->ok('Metodo', 'funzione di posta del server');
		}

		$this->ok('Indirizzo del forum', (string) $config['board_email']);
	}

	protected function controlla_dati()
	{
		$this->sezione('Dati');

		$manager = $this->container->get('salvocortesiano.newsletter.manager');

		try
		{
			$notiziari = $manager->get_lists();
			$this->ok('Notiziari', count($notiziari));

			$conteggi = $manager->count_by_list();

			foreach ($notiziari as $notiziario)
			{
				$id = (int) $notiziario['list_id'];
				$this->ok('&nbsp;&nbsp;' . htmlspecialchars($notiziario['list_name']),
					(isset($conteggi[$id]) ? $conteggi[$id] : 0) . ' iscritti'
					. (empty($notiziario['list_enabled']) ? ' — chiuso alle iscrizioni' : ''));
			}

			$this->ok('Persone iscritte in tutto', $manager->count_unique_subscribers());
			$this->ok('Campagne', $manager->count_campaigns());
			$this->ok('In attesa di approvazione', $manager->count_pending());

			// Campagne avviate che non avanzano piu
			$ferme = 0;

			foreach ($manager->get_campaigns(0, 200) as $campagna)
			{
				if ($manager->stall_seconds($campagna) > 0)
				{
					$ferme++;
				}
			}

			if ($ferme > 0)
			{
				$this->avviso('Campagne ferme', $ferme . ' avviate ma senza progressi: controlla il cron');
			}
			else
			{
				$this->ok('Campagne ferme', 'nessuna');
			}
		}
		catch (\Exception $e)
		{
			$this->errore('Lettura dati', $e->getMessage());
		}
		catch (\Throwable $e)
		{
			$this->errore('Lettura dati', $e->getMessage());
		}
	}

	/* =====================================================================
	 * Prova d'invio: crea una campagna finta, solo verso gli indirizzi dati
	 * ================================================================== */

	protected function prova_invio()
	{
		echo '<h2>Prova d\'invio</h2>';

		$indirizzo = trim($this->request->variable('indirizzo', ''));
		$rotti = max(0, min(30, $this->request->variable('rotti', 0)));
		$soglia = max(0, min(50, $this->request->variable('soglia', 0)));

		if ($indirizzo === '' || !preg_match('/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $indirizzo))
		{
			$this->errore('Indirizzo', 'scrivi un indirizzo valido: e l\'unico a cui questa prova manda posta');
			$this->stampa_righe();
			echo '<p><a class="pulsante" href="?">&larr; Torna indietro</a></p>';
			return;
		}

		$manager = $this->container->get('salvocortesiano.newsletter.manager');
		$db = $this->container->get('dbal.conn');
		$config = $this->container->get('config');
		$prefisso = $this->container->getParameter('core.table_prefix');

		global $user;

		$this->sezione('Preparazione');

		// La campagna viene creata in bozza e la coda riempita a mano: non si
		// chiama mai fill_queue, che leggerebbe gli iscritti veri
		$riga = array(
			'campaign_subject'		=> '[PROVA] Verifica dell\'estensione Newsletter',
			'campaign_body'			=> "Questo e un messaggio di prova generato dallo strumento di verifica.\n\nSe lo stai leggendo, l'invio funziona.\n\nDestinatario: {USERNAME} <{EMAIL}>\nData: {DATE}",
			'campaign_css'			=> '',
			'campaign_format'		=> 0,
			'campaign_banner'		=> 0,
			'campaign_topics'		=> '',
			'campaign_groups'		=> '',
			'campaign_subs'			=> 0,
			'campaign_lang'			=> '',
			'campaign_priority'		=> 3,
			'campaign_importance'	=> 'normal',
			'campaign_sensitivity'	=> '',
			'campaign_from_name'	=> '',
			'campaign_from_email'	=> '',
			'campaign_reply_to'		=> '',
			'campaign_batch'		=> 3,
			'campaign_interval'		=> 60,
			'campaign_schedule'		=> 0,
			'campaign_created'		=> time(),
			'campaign_author'		=> (int) $user->data['user_id'],
			'campaign_author_name'	=> (string) $user->data['username'],
			'campaign_status'		=> 1,
		);

		$strumenti = $this->container->get('dbal.tools');
		$tabella = $prefisso . 'newsletter_campaigns';

		foreach (array('campaign_public' => 0, 'campaign_list_id' => 0, 'campaign_fail_streak' => 0) as $colonna => $valore)
		{
			if ($strumenti->sql_column_exists($tabella, $colonna))
			{
				$riga[$colonna] = $valore;
			}
		}

		$campaign_id = $manager->create_campaign($riga);

		$this->ok('Campagna di prova', 'creata con numero ' . $campaign_id);

		// Coda: il tuo indirizzo, piu eventuali indirizzi rotti per vedere
		// come si comporta il ritentativo e la pausa automatica
		$coda = array(array(
			'campaign_id'	=> $campaign_id,
			'user_id'		=> (int) $user->data['user_id'],
			'username'		=> (string) $user->data['username'],
			'user_email'	=> $indirizzo,
			'user_lang'		=> (string) $user->data['user_lang'],
			'queue_status'	=> 0,
			'queue_attempts'=> 0,
			'queue_time'	=> 0,
			'queue_error'	=> '',
		));

		for ($i = 1; $i <= $rotti; $i++)
		{
			$coda[] = array(
				'campaign_id'	=> $campaign_id,
				'user_id'		=> 0,
				'username'		=> 'Prova ' . $i,
				// Dominio riservato dalla RFC 2606: non esiste e non esistera
				// mai, quindi il fallimento e garantito e non disturba nessuno
				'user_email'	=> 'prova' . $i . '@non-esiste.invalid',
				'user_lang'		=> (string) $user->data['user_lang'],
				'queue_status'	=> 0,
				'queue_attempts'=> 0,
				'queue_time'	=> 0,
				'queue_error'	=> '',
			);
		}

		$db->sql_multi_insert($prefisso . 'newsletter_queue', $coda);

		$this->ok('Coda', count($coda) . ' righe: 1 verso ' . htmlspecialchars($indirizzo) . ($rotti ? ', ' . $rotti . ' verso indirizzi inesistenti' : ''));

		// Soglia della pausa automatica, solo per la durata della prova
		$soglia_prima = isset($config['newsletter_fail_streak']) ? (int) $config['newsletter_fail_streak'] : null;

		if ($soglia > 0 && $soglia_prima !== null)
		{
			$config->set('newsletter_fail_streak', $soglia);
			$this->ok('Soglia di pausa', 'portata a ' . $soglia . ' per questa prova');
		}

		$this->sezione('Lotti');

		$giro = 0;
		$stato_finale = 1;

		while ($giro < 10)
		{
			$giro++;

			try
			{
				$esito = $manager->process($campaign_id, true);
			}
			catch (\Exception $e)
			{
				$this->errore('Lotto ' . $giro, 'eccezione: ' . $e->getMessage());
				break;
			}
			catch (\Throwable $e)
			{
				$this->errore('Lotto ' . $giro, 'eccezione: ' . $e->getMessage());
				break;
			}

			// process() non torna mai false: quando non c'e niente da fare lo
			// dice mettendo un motivo nell'esito
			if (!empty($esito['reason']))
			{
				$motivi = array(
					'NL_NOTHING_TO_SEND'	=> 'la campagna non risulta avviata',
					'NL_WAITING_INTERVAL'	=> 'in attesa dell\'intervallo fra un lotto e l\'altro',
				);

				$this->avviso('Lotto ' . $giro, isset($motivi[$esito['reason']]) ? $motivi[$esito['reason']] : $esito['reason']);
				break;
			}

			$testo = 'recapitate ' . (int) $esito['sent'] . ', fallite ' . (int) $esito['failed'] . ', in attesa ' . (int) $esito['pending'];

			if (!empty($esito['auto_paused']))
			{
				$this->avviso('Lotto ' . $giro, $testo . ' — PAUSA AUTOMATICA dopo ' . (int) $esito['streak'] . ' fallimenti consecutivi');
				$stato_finale = 2;
				break;
			}

			if (!empty($esito['finished']))
			{
				$this->ok('Lotto ' . $giro, $testo . ' — campagna conclusa');
				$stato_finale = 3;
				break;
			}

			$this->ok('Lotto ' . $giro, $testo);
		}

		$this->sezione('Esito');

		$stats = $manager->get_stats($campaign_id);

		$this->ok('Recapitate', $stats['sent']);

		if ($stats['failed'] > 0)
		{
			$this->avviso('Fallite', $stats['failed'] . ' (atteso, se hai chiesto indirizzi rotti)');
		}
		else
		{
			$this->ok('Fallite', 0);
		}

		foreach ($manager->get_queue_rows($campaign_id, -1, 0, 50) as $r)
		{
			$stati = array(0 => 'in attesa', 1 => 'recapitata', 2 => 'fallita');
			$s = isset($stati[(int) $r['queue_status']]) ? $stati[(int) $r['queue_status']] : '?';

			$this->ok('&nbsp;&nbsp;' . htmlspecialchars($r['user_email']),
				$s . ', tentativi ' . (int) $r['queue_attempts']
				. ((string) $r['queue_error'] !== '' ? ' — ' . htmlspecialchars($r['queue_error']) : ''));
		}

		if ($stato_finale === 2)
		{
			$campagna = $manager->get_campaign($campaign_id);

			if (!empty($campagna['campaign_pause_reason']))
			{
				$this->ok('Motivo della pausa', htmlspecialchars((string) $campagna['campaign_pause_reason']));
			}
		}

		// Ripristino e pulizia
		$this->sezione('Pulizia');

		if ($soglia > 0 && $soglia_prima !== null)
		{
			$config->set('newsletter_fail_streak', $soglia_prima);
			$this->ok('Soglia di pausa', 'riportata a ' . $soglia_prima);
		}

		$manager->delete_campaign($campaign_id);

		$this->ok('Campagna di prova', 'cancellata insieme alla sua coda');

		$this->stampa_righe();
		$this->riepilogo();

		echo '<p><a class="pulsante" href="?">&larr; Torna indietro</a></p>';
	}

	/* =====================================================================
	 * Schermata iniziale
	 * ================================================================== */

	protected function schermata_iniziale()
	{
		global $user;

		$indirizzo = htmlspecialchars((string) $user->data['user_email'], ENT_COMPAT, 'UTF-8');

		echo '<h2>Cosa vuoi fare</h2>';

		echo '<div class="scheda">';
		echo '<h3>Verifica</h3>';
		echo '<p>Controlla tabelle, colonne, impostazioni, servizi, indirizzi pubblici, file di lingua, stato del cron e configurazione della posta. <strong>Si limita a leggere: non tocca niente e non manda email.</strong></p>';
		echo '<form method="post"><input type="hidden" name="azione" value="verifica" /><button class="pulsante" type="submit">Esegui la verifica</button></form>';
		echo '</div>';

		echo '<div class="scheda">';
		echo '<h3>Prova d\'invio</h3>';
		echo '<p>Crea una campagna finta e la manda <strong>solo all\'indirizzo che scrivi qui sotto</strong>. La coda viene riempita a mano: gli iscritti veri non vengono nemmeno letti. A fine prova la campagna viene cancellata.</p>';
		echo '<p>Aggiungendo qualche indirizzo inesistente puoi vedere come si comportano il ritentativo, il conteggio dei fallimenti e la pausa automatica.</p>';
		echo '<form method="post">';
		echo '<input type="hidden" name="azione" value="invio" />';
		echo '<p><label>Manda a questo indirizzo<br /><input type="email" name="indirizzo" value="' . $indirizzo . '" size="40" required="required" /></label></p>';
		echo '<p><label>Aggiungi indirizzi inesistenti<br /><input type="number" name="rotti" value="0" min="0" max="30" /></label> <span class="nota">servono a provare fallimenti e ritentativi</span></p>';
		echo '<p><label>Soglia della pausa automatica per questa prova<br /><input type="number" name="soglia" value="0" min="0" max="50" /></label> <span class="nota">zero lascia il valore delle impostazioni; con 3 la vedi scattare subito</span></p>';
		echo '<button class="pulsante" type="submit">Esegui la prova</button>';
		echo '</form>';
		echo '</div>';

		echo '<div class="scheda avviso">';
		echo '<h3>Quando hai finito</h3>';
		echo '<p><strong>Cancella questo file dal server.</strong> E uno strumento di servizio: lasciarlo in giro significa lasciare accessibile una pagina che manda email e mostra pezzi della configurazione del server di posta.</p>';
		echo '</div>';
	}

	/* =====================================================================
	 * Presentazione
	 * ================================================================== */

	protected function sezione($titolo)
	{
		$this->righe[] = array('tipo' => 'sezione', 'titolo' => $titolo);
	}

	protected function ok($etichetta, $valore)
	{
		$this->totali['ok']++;
		$this->righe[] = array('tipo' => 'ok', 'etichetta' => $etichetta, 'valore' => $valore);
	}

	protected function avviso($etichetta, $valore)
	{
		$this->totali['avviso']++;
		$this->righe[] = array('tipo' => 'avviso', 'etichetta' => $etichetta, 'valore' => $valore);
	}

	protected function errore($etichetta, $valore)
	{
		$this->totali['errore']++;
		$this->righe[] = array('tipo' => 'errore', 'etichetta' => $etichetta, 'valore' => $valore);
	}

	protected function stampa_righe()
	{
		$simboli = array('ok' => '&#10003;', 'avviso' => '!', 'errore' => '&#10007;');

		foreach ($this->righe as $riga)
		{
			if ($riga['tipo'] === 'sezione')
			{
				echo '<h3>' . htmlspecialchars($riga['titolo'], ENT_COMPAT, 'UTF-8') . '</h3>';
				echo '<table>';
				continue;
			}

			echo '<tr class="' . $riga['tipo'] . '">';
			echo '<td class="simbolo">' . $simboli[$riga['tipo']] . '</td>';
			echo '<td class="etichetta">' . $riga['etichetta'] . '</td>';
			echo '<td>' . $riga['valore'] . '</td>';
			echo '</tr>';
		}

		echo '</table>';

		$this->righe = array();
	}

	protected function riepilogo()
	{
		$classe = $this->totali['errore'] ? 'errore' : ($this->totali['avviso'] ? 'avviso' : 'ok');

		echo '<div class="riepilogo ' . $classe . '">';
		echo '<strong>' . $this->totali['ok'] . '</strong> a posto, ';
		echo '<strong>' . $this->totali['avviso'] . '</strong> da guardare, ';
		echo '<strong>' . $this->totali['errore'] . '</strong> da correggere';
		echo '</div>';
	}

	protected function durata($secondi)
	{
		if ($secondi < 60)
		{
			return $secondi . ' secondi';
		}

		if ($secondi < 3600)
		{
			return round($secondi / 60) . ' minuti';
		}

		if ($secondi < 86400)
		{
			return round($secondi / 3600) . ' ore';
		}

		return round($secondi / 86400) . ' giorni';
	}

	protected function intestazione()
	{
		echo '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8" />';
		echo '<meta name="robots" content="noindex, nofollow" />';
		echo '<title>Verifica dell\'estensione Newsletter</title><style>';
		echo 'body{font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;padding:24px;background:#f4f6f8;color:#222}';
		echo '.foglio{max-width:1000px;margin:0 auto;background:#fff;padding:24px 28px;border:1px solid #d9dde1;border-radius:6px}';
		echo 'h1{margin:0 0 4px;font-size:20px}h2{margin:24px 0 8px;font-size:17px;border-bottom:1px solid #e3e6e9;padding-bottom:6px}';
		echo 'h3{margin:20px 0 6px;font-size:14px;color:#555}';
		echo 'table{width:100%;border-collapse:collapse;margin-bottom:4px}';
		echo 'td{padding:5px 8px;border-bottom:1px solid #f0f2f4;vertical-align:top}';
		echo '.simbolo{width:22px;text-align:center;font-weight:bold}';
		echo '.etichetta{width:290px;color:#444}';
		echo 'tr.ok .simbolo{color:#2f8132}tr.avviso .simbolo{color:#c8860a}tr.errore .simbolo{color:#bc2a4d}';
		echo 'tr.avviso{background:#fdf9f0}tr.errore{background:#fdf1f4}';
		echo '.scheda{border:1px solid #dfe3e6;border-radius:6px;padding:14px 18px;margin-bottom:16px;background:#fbfcfd}';
		echo '.scheda.avviso{background:#fdf9f0;border-color:#ead2a8}';
		echo '.scheda h3{margin-top:0;color:#222;font-size:15px}';
		echo '.pulsante{display:inline-block;padding:8px 16px;background:#0f5d9d;color:#fff;border:0;border-radius:4px;font-size:14px;cursor:pointer;text-decoration:none}';
		echo '.pulsante:hover{background:#0a4373}';
		echo 'input{padding:6px 8px;border:1px solid #c8ced3;border-radius:4px;font-size:14px}';
		echo '.nota{color:#777;font-size:12px}';
		echo '.riepilogo{margin-top:18px;padding:12px 16px;border-radius:4px;border:1px solid #cfd4d8;background:#f7f9fa}';
		echo '.riepilogo.ok{background:#eef7ee;border-color:#a8ceaa}';
		echo '.riepilogo.avviso{background:#fdf9f0;border-color:#ead2a8}';
		echo '.riepilogo.errore{background:#fdf1f4;border-color:#e0aab6}';
		echo '</style></head><body><div class="foglio">';
		echo '<h1>Verifica dell\'estensione Newsletter</h1>';
		echo '<p class="nota">' . date('d/m/Y H:i:s') . '</p>';
	}

	protected function chiusura()
	{
		echo '</div></body></html>';
	}
}
