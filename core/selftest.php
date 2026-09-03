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
 * Verifica dell'estensione.
 *
 * Sta in un servizio a se e non dentro il modulo del pannello perche fa due
 * lavori diversi: raccogliere lo stato dell'installazione e provare un invio
 * reale. Tenerli separati dalla parte che disegna la pagina rende possibile
 * leggere l'esito da altrove - una riga di comando, un cron di controllo -
 * senza duplicare niente.
 */
class selftest
{
	/** Numero da cui partono gli utenti finti della prova */
	const FAKE_USER_BASE = 900000;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\db\tools\tools_interface */
	protected $db_tools;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\controller\helper */
	protected $helper;

	/** @var \phpbb\extension\manager */
	protected $ext_manager;

	/** @var \salvocortesiano\newsletter\core\manager */
	protected $manager;

	/** @var \salvocortesiano\newsletter\core\mailer */
	protected $mailer;

	/** @var \salvocortesiano\newsletter\core\access */
	protected $access;

	/** @var \salvocortesiano\newsletter\core\bbcode */
	protected $bbcode;

	/** @var \salvocortesiano\newsletter\core\html */
	protected $html;

	/** @var string */
	protected $table_prefix;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/** @var array Righe raccolte */
	protected $righe = array();

	/** @var array */
	protected $totali = array('ok' => 0, 'warn' => 0, 'error' => 0);

	/** @var int Indirizzi inesistenti che il server ha comunque accettato */
	protected $accepted_invalid = 0;

	/**
	 * Constructor
	 */
	public function __construct(
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\db\tools\tools_interface $db_tools,
		\phpbb\language\language $language,
		\phpbb\controller\helper $helper,
		\phpbb\extension\manager $ext_manager,
		\salvocortesiano\newsletter\core\manager $manager,
		\salvocortesiano\newsletter\core\mailer $mailer,
		\salvocortesiano\newsletter\core\access $access,
		\salvocortesiano\newsletter\core\bbcode $bbcode,
		\salvocortesiano\newsletter\core\html $html,
		$table_prefix,
		$root_path,
		$php_ext
	)
	{
		$this->config = $config;
		$this->db = $db;
		$this->db_tools = $db_tools;
		$this->language = $language;
		$this->helper = $helper;
		$this->ext_manager = $ext_manager;
		$this->manager = $manager;
		$this->mailer = $mailer;
		$this->access = $access;
		$this->bbcode = $bbcode;
		$this->html = $html;
		$this->table_prefix = $table_prefix;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * @return array
	 */
	public function get_rows()
	{
		return $this->righe;
	}

	/**
	 * @return array
	 */
	public function get_totals()
	{
		return $this->totali;
	}

	/* =====================================================================
	 * Verifica: legge soltanto
	 * ================================================================== */

	/**
	 * Esegue tutti i controlli di stato
	 */
	public function run_checks()
	{
		$this->righe = array();
		$this->totali = array('ok' => 0, 'warn' => 0, 'error' => 0);

		$this->check_schema();
		$this->check_config();
		$this->check_services();
		$this->check_routes();
		$this->check_languages();
		$this->check_cron();
		$this->check_mail();
		$this->check_unsubscribe();
		$this->check_modules();
		$this->check_archive();
		$this->check_bbcode();
		$this->check_subscription_cycle();
		$this->check_data();
	}

	protected function check_schema()
	{
		$this->section('NL_TEST_SCHEMA');

		$tabelle = array(
			'newsletter_campaigns'	=> false,
			'newsletter_queue'		=> false,
			'newsletter_lists'		=> false,
			'newsletter_list_subs'	=> false,
			// La vecchia tabella e tenuta come copia dopo la migrazione dei
			// notiziari: la sua assenza non e un problema
			'newsletter_subs'		=> true,
		);

		foreach ($tabelle as $nome => $facoltativa)
		{
			$esiste = $this->db_tools->sql_table_exists($this->table_prefix . $nome);

			if ($esiste)
			{
				$this->ok($this->table_prefix . $nome, $this->language->lang('NL_TEST_PRESENT'));
			}
			else if ($facoltativa)
			{
				$this->warn($this->table_prefix . $nome, $this->language->lang('NL_TEST_OPTIONAL_TABLE'));
			}
			else
			{
				$this->error($this->table_prefix . $nome, $this->language->lang('NL_TEST_MISSING_TABLE'));
			}
		}

		$colonne = array(
			'campaign_banner', 'campaign_public', 'campaign_views', 'campaign_bbcode_uid',
			'campaign_list_id', 'campaign_fail_streak', 'campaign_pause_reason',
		);

		$mancanti = array();

		foreach ($colonne as $colonna)
		{
			if (!$this->db_tools->sql_column_exists($this->table_prefix . 'newsletter_campaigns', $colonna))
			{
				$mancanti[] = $colonna;
			}
		}

		if (empty($mancanti))
		{
			$this->ok($this->language->lang('NL_TEST_COLUMNS'), $this->language->lang('NL_TEST_COLUMNS_OK', count($colonne)));
		}
		else
		{
			$this->error($this->language->lang('NL_TEST_COLUMNS'), $this->language->lang('NL_TEST_COLUMNS_MISSING', implode(', ', $mancanti)));
		}
	}

	protected function check_config()
	{
		$this->section('NL_TEST_SETTINGS');

		$attese = array(
			'newsletter_enabled', 'newsletter_batch_size', 'newsletter_interval',
			'newsletter_max_attempts', 'newsletter_time_budget', 'newsletter_secret',
			'newsletter_subs_enabled', 'newsletter_archive_visibility', 'newsletter_archive_groups',
			'newsletter_bbcode_smilies', 'newsletter_default_list', 'newsletter_send_groups',
			'newsletter_send_approval', 'newsletter_fail_streak', 'newsletter_report_email',
			'newsletter_stall_grace',
		);

		$mancanti = array();

		foreach ($attese as $chiave)
		{
			if (!isset($this->config[$chiave]))
			{
				$mancanti[] = $chiave;
			}
		}

		if (empty($mancanti))
		{
			$this->ok($this->language->lang('NL_TEST_KEYS'), $this->language->lang('NL_TEST_KEYS_OK', count($attese)));
		}
		else
		{
			$this->error($this->language->lang('NL_TEST_KEYS'), $this->language->lang('NL_TEST_KEYS_MISSING', implode(', ', $mancanti)));
		}

		// La chiave firma i collegamenti di disiscrizione: senza, nessuno
		// riesce a cancellarsi e ogni messaggio diventa una segnalazione
		$segreto = isset($this->config['newsletter_secret']) ? (string) $this->config['newsletter_secret'] : '';

		if (strlen($segreto) >= 16)
		{
			$this->ok($this->language->lang('NL_TEST_SECRET'), $this->language->lang('NL_TEST_SECRET_OK', strlen($segreto)));
		}
		else
		{
			$this->error($this->language->lang('NL_TEST_SECRET'), $this->language->lang('NL_TEST_SECRET_BAD'));
		}
	}

	protected function check_services()
	{
		$this->section('NL_TEST_SERVICES');

		global $phpbb_container;

		$servizi = array(
			'salvocortesiano.newsletter.manager',
			'salvocortesiano.newsletter.mailer',
			'salvocortesiano.newsletter.access',
			'salvocortesiano.newsletter.banner',
			'salvocortesiano.newsletter.bbcode',
			'salvocortesiano.newsletter.html',
			'salvocortesiano.newsletter.diagnostics',
		);

		$rotti = array();

		foreach ($servizi as $id)
		{
			try
			{
				$phpbb_container->get($id);
			}
			catch (\Exception $e)
			{
				$rotti[] = $id . ' (' . $e->getMessage() . ')';
			}
			catch (\Throwable $e)
			{
				$rotti[] = $id . ' (' . $e->getMessage() . ')';
			}
		}

		if (empty($rotti))
		{
			$this->ok($this->language->lang('NL_TEST_SERVICES'), $this->language->lang('NL_TEST_SERVICES_OK', count($servizi)));
		}
		else
		{
			$this->error($this->language->lang('NL_TEST_SERVICES'), implode('<br />', $rotti));
		}
	}

	protected function check_routes()
	{
		$this->section('NL_TEST_ROUTES');

		$rotte = array(
			'salvocortesiano_newsletter_archive'			=> array(),
			'salvocortesiano_newsletter_list'				=> array('list_id' => 1),
			'salvocortesiano_newsletter_issue'				=> array('campaign_id' => 1),
			'salvocortesiano_newsletter_view'				=> array('campaign_id' => 1),
			'salvocortesiano_newsletter_unsubscribe'		=> array('user_id' => 2, 'token' => str_repeat('a', 32)),
			'salvocortesiano_newsletter_unsubscribe_list'	=> array('user_id' => 2, 'list_id' => 1, 'token' => str_repeat('a', 32)),
		);

		$rotti = array();

		foreach ($rotte as $nome => $parametri)
		{
			try
			{
				$this->helper->route($nome, $parametri);
			}
			catch (\Exception $e)
			{
				$rotti[] = $nome;
			}
			catch (\Throwable $e)
			{
				$rotti[] = $nome;
			}
		}

		if (empty($rotti))
		{
			$this->ok($this->language->lang('NL_TEST_ROUTES'), $this->language->lang('NL_TEST_ROUTES_OK', count($rotte)));
		}
		else
		{
			// Quasi sempre e il contenitore compilato rimasto indietro
			$this->error($this->language->lang('NL_TEST_ROUTES'), $this->language->lang('NL_TEST_ROUTES_BAD', implode(', ', $rotti)));
		}
	}

	protected function check_languages()
	{
		$this->section('NL_TEST_LANGUAGES');

		$base = $this->root_path . 'ext/salvocortesiano/newsletter/language/';
		$file = array('newsletter', 'info_acp_newsletter', 'info_ucp_newsletter', 'logs_newsletter', 'permissions_newsletter');
		$conteggi = array();

		foreach (array('en', 'it') as $lingua)
		{
			$totale = 0;
			$manca = false;

			foreach ($file as $nome)
			{
				$percorso = $base . $lingua . '/' . $nome . '.' . $this->php_ext;

				if (!is_readable($percorso))
				{
					$manca = true;
					continue;
				}

				$lang = array();
				include($percorso);
				$totale += count($lang);
			}

			$conteggi[$lingua] = $totale;

			if ($manca)
			{
				$this->error($lingua, $this->language->lang('NL_TEST_LANG_MISSING'));
			}
			else
			{
				$this->ok($lingua, $this->language->lang('NL_TEST_LANG_KEYS', $totale));
			}
		}

		// Due lingue con conteggi diversi significano traduzioni indietro
		if (count($conteggi) === 2 && $conteggi['en'] !== $conteggi['it'])
		{
			$this->warn($this->language->lang('NL_TEST_LANG_PARITY'), $this->language->lang('NL_TEST_LANG_PARITY_BAD', abs($conteggi['en'] - $conteggi['it'])));
		}
	}

	protected function check_cron()
	{
		$this->section('NL_TEST_CRON');

		$ultimo = isset($this->config['newsletter_last_run']) ? (int) $this->config['newsletter_last_run'] : 0;

		if ($ultimo === 0)
		{
			$this->warn($this->language->lang('NL_TEST_CRON_LAST'), $this->language->lang('NL_TEST_CRON_NEVER'));
		}
		else
		{
			$da = time() - $ultimo;

			if ($da > 86400)
			{
				$this->warn($this->language->lang('NL_TEST_CRON_LAST'), $this->language->lang('NL_TEST_CRON_OLD', $this->duration($da)));
			}
			else
			{
				$this->ok($this->language->lang('NL_TEST_CRON_LAST'), $this->language->lang('NL_TEST_CRON_RECENT', $this->duration($da)));
			}
		}

		if (!empty($this->config['use_system_cron']))
		{
			$this->ok($this->language->lang('NL_TEST_CRON_TYPE'), $this->language->lang('NL_TEST_CRON_SYSTEM'));
		}
		else
		{
			$this->warn($this->language->lang('NL_TEST_CRON_TYPE'), $this->language->lang('NL_TEST_CRON_VISITS'));
		}
	}

	protected function check_mail()
	{
		$this->section('NL_TEST_MAIL');

		if (empty($this->config['email_enable']))
		{
			$this->error($this->language->lang('NL_TEST_MAIL_STATE'), $this->language->lang('NL_TEST_MAIL_OFF'));
			return;
		}

		$this->ok($this->language->lang('NL_TEST_MAIL_STATE'), $this->language->lang('NL_TEST_MAIL_ON'));

		if (!empty($this->config['smtp_delivery']))
		{
			$host = (string) $this->config['smtp_host'];
			$porta = (int) $this->config['smtp_port'];

			$this->ok($this->language->lang('NL_TEST_MAIL_METHOD'), 'SMTP ' . htmlspecialchars($host, ENT_COMPAT, 'UTF-8') . ':' . $porta);

			if (strpos($host, '://') === false && in_array($porta, array(465, 587), true))
			{
				$suggerito = ($porta === 465) ? 'ssl://' : 'tls://';

				$this->warn($this->language->lang('NL_TEST_MAIL_TLS'), $this->language->lang('NL_TEST_MAIL_TLS_BAD', $porta, $suggerito . htmlspecialchars($host, ENT_COMPAT, 'UTF-8')));
			}
		}
		else
		{
			$this->ok($this->language->lang('NL_TEST_MAIL_METHOD'), $this->language->lang('NL_TEST_MAIL_PHP'));
		}
	}

	protected function check_data()
	{
		$this->section('NL_TEST_DATA');

		try
		{
			$notiziari = $this->manager->get_lists();
			$conteggi = $this->manager->count_by_list();

			$this->ok($this->language->lang('NL_LISTS'), count($notiziari));

			foreach ($notiziari as $notiziario)
			{
				$id = (int) $notiziario['list_id'];

				$this->ok('&nbsp;&nbsp;' . htmlspecialchars((string) $notiziario['list_name'], ENT_COMPAT, 'UTF-8'),
					$this->language->lang('NL_TEST_SUBS', isset($conteggi[$id]) ? $conteggi[$id] : 0)
					. (empty($notiziario['list_enabled']) ? ' &middot; ' . $this->language->lang('NL_LIST_CLOSED') : ''));
			}

			$this->ok($this->language->lang('NL_TEST_PEOPLE'), $this->manager->count_unique_subscribers());
			$this->ok($this->language->lang('NL_TEST_CAMPAIGNS'), $this->manager->count_campaigns());

			$attesa = $this->manager->count_pending();

			if ($attesa > 0)
			{
				$this->warn($this->language->lang('NL_TEST_PENDING'), $attesa);
			}
			else
			{
				$this->ok($this->language->lang('NL_TEST_PENDING'), 0);
			}

			$ferme = 0;

			foreach ($this->manager->get_campaigns(0, 200) as $campagna)
			{
				if ($this->manager->stall_seconds($campagna) > 0)
				{
					$ferme++;
				}
			}

			if ($ferme > 0)
			{
				$this->warn($this->language->lang('NL_TEST_STALLED'), $this->language->lang('NL_TEST_STALLED_BAD', $ferme));
			}
			else
			{
				$this->ok($this->language->lang('NL_TEST_STALLED'), $this->language->lang('NL_TEST_STALLED_NONE'));
			}
		}
		catch (\Exception $e)
		{
			$this->error($this->language->lang('NL_TEST_DATA'), $e->getMessage());
		}
		catch (\Throwable $e)
		{
			$this->error($this->language->lang('NL_TEST_DATA'), $e->getMessage());
		}
	}

	/**
	 * La catena della firma di disiscrizione.
	 *
	 * Non si apre la pagina - servirebbe una richiesta HTTP a se stessi, che
	 * su molti hosting non funziona - ma si verifica la sola cosa che puo
	 * rompersi in silenzio: che la firma prodotta dal componente di invio sia
	 * la stessa che il controller si aspetta, e che una firma manomessa venga
	 * respinta. Un pulsante di disiscrizione rotto e il modo piu rapido per
	 * finire segnalati come posta indesiderata.
	 */
	protected function check_unsubscribe()
	{
		$this->section('NL_TEST_UNSUB');

		$segreto = (string) $this->config['newsletter_secret'];

		if ($segreto === '')
		{
			$this->error($this->language->lang('NL_TEST_UNSUB_CHAIN'), $this->language->lang('NL_TEST_SECRET_BAD'));
			return;
		}

		$utente = 12345;
		$lista = 1;

		$token = mailer::token($segreto, $utente);
		$token_lista = mailer::list_token($segreto, $utente, $lista);

		// Firma generale e firma per notiziario devono essere diverse: se
		// coincidessero, il collegamento di un notiziario varrebbe per tutti
		if ($token === $token_lista)
		{
			$this->error($this->language->lang('NL_TEST_UNSUB_CHAIN'), $this->language->lang('NL_TEST_UNSUB_SAME'));
		}
		else if (mailer::list_token($segreto, $utente, 1) === mailer::list_token($segreto, $utente, 2))
		{
			$this->error($this->language->lang('NL_TEST_UNSUB_CHAIN'), $this->language->lang('NL_TEST_UNSUB_LISTS'));
		}
		else if (!hash_equals($token, mailer::token($segreto, $utente)))
		{
			$this->error($this->language->lang('NL_TEST_UNSUB_CHAIN'), $this->language->lang('NL_TEST_UNSUB_UNSTABLE'));
		}
		else if (hash_equals($token, mailer::token($segreto, $utente + 1)))
		{
			$this->error($this->language->lang('NL_TEST_UNSUB_CHAIN'), $this->language->lang('NL_TEST_UNSUB_WEAK'));
		}
		else
		{
			$this->ok($this->language->lang('NL_TEST_UNSUB_CHAIN'), $this->language->lang('NL_TEST_UNSUB_OK'));
		}

		// L'indirizzo che finisce nel messaggio deve coincidere con quello che
		// la rotta genera: se divergono, il collegamento porta a una pagina che
		// non esiste
		$dal_messaggio = $this->mailer->unsubscribe_url(array('user_id' => $utente), 0);

		try
		{
			$dalla_rotta = $this->helper->route('salvocortesiano_newsletter_unsubscribe', array(
				'user_id'	=> $utente,
				'token'		=> $token,
			), true, false, \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
		}
		catch (\Exception $e)
		{
			$dalla_rotta = '';
		}
		catch (\Throwable $e)
		{
			$dalla_rotta = '';
		}

		if ($dalla_rotta !== '' && strpos($dal_messaggio, '/newsletter/unsubscribe/' . $utente . '/' . $token) !== false)
		{
			$this->ok($this->language->lang('NL_TEST_UNSUB_URL'), htmlspecialchars($dal_messaggio, ENT_COMPAT, 'UTF-8'));
		}
		else
		{
			$this->error($this->language->lang('NL_TEST_UNSUB_URL'), $this->language->lang('NL_TEST_UNSUB_URL_BAD', htmlspecialchars($dal_messaggio, ENT_COMPAT, 'UTF-8')));
		}

		// Il controller e i suoi due metodi devono esistere: una rotta che
		// punta a un metodo assente da una pagina bianca, non un errore chiaro
		foreach (array('handle', 'handle_list') as $metodo)
		{
			if (method_exists('\salvocortesiano\newsletter\controller\unsubscribe', $metodo))
			{
				$this->ok('unsubscribe::' . $metodo . '()', $this->language->lang('NL_TEST_PRESENT'));
			}
			else
			{
				$this->error('unsubscribe::' . $metodo . '()', $this->language->lang('NL_TEST_METHOD_MISSING'));
			}
		}
	}

	/**
	 * Moduli del pannello utente e di amministrazione.
	 *
	 * Un modulo dichiarato ma non registrato nel database non compare, e la
	 * pagina corrispondente resta irraggiungibile senza che niente lo segnali.
	 */
	protected function check_modules()
	{
		$this->section('NL_TEST_MODULES');

		$attesi = array(
			array('ucp', '\salvocortesiano\newsletter\ucp\newsletter_module', 'manage'),
			array('ucp', '\salvocortesiano\newsletter\ucp\send_module', 'send'),
			array('acp', '\salvocortesiano\newsletter\acp\newsletter_module', 'compose'),
			array('acp', '\salvocortesiano\newsletter\acp\newsletter_module', 'logs'),
			array('acp', '\salvocortesiano\newsletter\acp\newsletter_module', 'lists'),
			array('acp', '\salvocortesiano\newsletter\acp\newsletter_module', 'subs'),
			array('acp', '\salvocortesiano\newsletter\acp\newsletter_module', 'settings'),
			array('acp', '\salvocortesiano\newsletter\acp\newsletter_module', 'test'),
		);

		$mancanti = array();

		foreach ($attesi as $modulo)
		{
			$sql = 'SELECT module_id
				FROM ' . MODULES_TABLE . "
				WHERE module_class = '" . $this->db->sql_escape($modulo[0]) . "'
					AND module_basename = '" . $this->db->sql_escape($modulo[1]) . "'
					AND module_mode = '" . $this->db->sql_escape($modulo[2]) . "'";
			$result = $this->db->sql_query($sql);
			$riga = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			if (!$riga)
			{
				$mancanti[] = $modulo[0] . '/' . $modulo[2];
			}
		}

		if (empty($mancanti))
		{
			$this->ok($this->language->lang('NL_TEST_MODULES'), $this->language->lang('NL_TEST_MODULES_OK', count($attesi)));
		}
		else
		{
			$this->error($this->language->lang('NL_TEST_MODULES'), $this->language->lang('NL_TEST_MODULES_MISSING', implode(', ', $mancanti)));
		}

		// Chi puo scrivere dal pannello utente, secondo la configurazione
		$gruppi = trim((string) $this->config['newsletter_send_groups']);
		$liste = trim((string) $this->config['newsletter_send_lists']);

		if ($gruppi === '' || $liste === '')
		{
			$this->ok($this->language->lang('NL_TEST_UCP_SEND'), $this->language->lang('NL_TEST_UCP_SEND_OFF'));
		}
		else
		{
			$this->ok($this->language->lang('NL_TEST_UCP_SEND'), $this->language->lang(
				'NL_TEST_UCP_SEND_ON',
				count(explode(',', $gruppi)),
				count(explode(',', $liste)),
				$this->access->needs_approval() ? $this->language->lang('YES') : $this->language->lang('NO')
			));
		}
	}

	/**
	 * Archivio pubblico: chi vede cosa, e le interrogazioni funzionano
	 */
	protected function check_archive()
	{
		$this->section('NL_TEST_ARCHIVE');

		$ospite = array('user_id' => ANONYMOUS, 'group_id' => 0);
		$membro = array('user_id' => 2, 'group_id' => 0);

		$esiti = array(
			'NL_TEST_ARCHIVE_GUEST'		=> $this->access->archive_access($ospite, false),
			'NL_TEST_ARCHIVE_MEMBER'	=> $this->access->archive_access($membro, false),
			'NL_TEST_ARCHIVE_ADMIN'		=> $this->access->archive_access($membro, true),
		);

		$leggibile = array(
			'ok'	=> 'NL_TEST_ARCHIVE_YES',
			'login'	=> 'NL_TEST_ARCHIVE_LOGIN',
			'no'	=> 'NL_TEST_ARCHIVE_NO',
		);

		foreach ($esiti as $chiave => $esito)
		{
			$this->ok($this->language->lang($chiave), $this->language->lang(isset($leggibile[$esito]) ? $leggibile[$esito] : 'NL_TEST_ARCHIVE_NO'));
		}

		// Chi amministra deve vedere sempre: nascondere l'archivio a chi lo
		// riempie sarebbe un controsenso
		if ($esiti['NL_TEST_ARCHIVE_ADMIN'] !== 'ok')
		{
			$this->error($this->language->lang('NL_TEST_ARCHIVE_ADMIN'), $this->language->lang('NL_TEST_ARCHIVE_ADMIN_BAD'));
		}

		try
		{
			$totale = $this->manager->count_archive();
			$numeri = $this->manager->get_archive(0, 5);

			$this->ok($this->language->lang('NL_TEST_ARCHIVE_COUNT'), $this->language->lang('NL_TEST_ARCHIVE_ISSUES', $totale));

			if ($totale !== 0 && empty($numeri))
			{
				$this->warn($this->language->lang('NL_TEST_ARCHIVE_COUNT'), $this->language->lang('NL_TEST_ARCHIVE_MISMATCH'));
			}
		}
		catch (\Exception $e)
		{
			$this->error($this->language->lang('NL_TEST_ARCHIVE_COUNT'), $e->getMessage());
		}
		catch (\Throwable $e)
		{
			$this->error($this->language->lang('NL_TEST_ARCHIVE_COUNT'), $e->getMessage());
		}
	}

	/**
	 * Conversione del BBCode.
	 *
	 * Si converte davvero un frammento, invece di limitarsi a controllare che
	 * il servizio si costruisca: il motore del forum puo esserci e comunque
	 * non produrre nulla, per esempio se i tag sono stati disattivati.
	 */
	protected function check_bbcode()
	{
		$this->section('NL_TEST_BBCODE');

		try
		{
			$conservato = $this->bbcode->to_storage('[b]prova[/b] [url=https://example.com]collegamento[/url]');
			$html = $this->bbcode->to_html($conservato);
		}
		catch (\Exception $e)
		{
			$this->error($this->language->lang('NL_TEST_BBCODE_CONV'), $e->getMessage());
			return;
		}
		catch (\Throwable $e)
		{
			$this->error($this->language->lang('NL_TEST_BBCODE_CONV'), $e->getMessage());
			return;
		}

		$grassetto = (stripos($html, '<strong') !== false || stripos($html, '<b>') !== false);
		$collegamento = (stripos($html, '<a ') !== false);

		if ($grassetto && $collegamento)
		{
			$this->ok($this->language->lang('NL_TEST_BBCODE_CONV'), $this->language->lang('NL_TEST_BBCODE_OK'));
		}
		else
		{
			$this->error($this->language->lang('NL_TEST_BBCODE_CONV'), $this->language->lang('NL_TEST_BBCODE_BAD', htmlspecialchars(substr($html, 0, 120), ENT_COMPAT, 'UTF-8')));
		}

		// I percorsi relativi delle faccine non significano nulla dentro un
		// messaggio di posta: questa e la conversione che li salva
		$assoluto = $this->html->absolutise('<img src="./images/smilies/x.gif" alt=":)">', 'https://esempio.test/forum');

		if (strpos($assoluto, 'https://esempio.test/forum/images/smilies/x.gif') !== false)
		{
			$this->ok($this->language->lang('NL_TEST_BBCODE_PATHS'), $this->language->lang('NL_TEST_BBCODE_PATHS_OK'));
		}
		else
		{
			$this->error($this->language->lang('NL_TEST_BBCODE_PATHS'), htmlspecialchars($assoluto, ENT_COMPAT, 'UTF-8'));
		}

		// Una immagine deve lasciare il suo testo alternativo nella copia
		// testuale, altrimenti le faccine spariscono senza lasciare traccia
		$testo = $this->html->to_text('<p>Ciao <img src="x.gif" alt=":risata:"> come stai?</p>');

		if (strpos($testo, ':risata:') !== false)
		{
			$this->ok($this->language->lang('NL_TEST_BBCODE_ALT'), $this->language->lang('NL_TEST_BBCODE_ALT_OK'));
		}
		else
		{
			$this->error($this->language->lang('NL_TEST_BBCODE_ALT'), htmlspecialchars($testo, ENT_COMPAT, 'UTF-8'));
		}
	}

	/**
	 * Giro completo di iscrizione e cancellazione.
	 *
	 * Si lavora su un notiziario creato per l'occasione e cancellato subito
	 * dopo: nessun notiziario reale viene toccato, e nessun iscritto vero
	 * cambia stato. Le email di conferma vengono spente per la durata della
	 * prova, altrimenti un controllo che dichiara di non mandare posta ne
	 * manderebbe due.
	 */
	protected function check_subscription_cycle()
	{
		$this->section('NL_TEST_CYCLE');

		if (!$this->manager->lists_available())
		{
			$this->error($this->language->lang('NL_TEST_CYCLE'), $this->language->lang('NL_TEST_CYCLE_NO_LISTS'));
			return;
		}

		global $user;

		$user_id = (int) $user->data['user_id'];

		$benvenuto = isset($this->config['newsletter_welcome_email']) ? (int) $this->config['newsletter_welcome_email'] : 0;
		$addio = isset($this->config['newsletter_goodbye_email']) ? (int) $this->config['newsletter_goodbye_email'] : 0;

		$this->config->set('newsletter_welcome_email', 0);
		$this->config->set('newsletter_goodbye_email', 0);

		$list_id = 0;

		try
		{
			$list_id = $this->manager->create_list(array(
				'list_name'		=> $this->language->lang('NL_TEST_CYCLE_LIST'),
				'list_desc'		=> '',
				'list_order'	=> 999,
				'list_enabled'	=> 0,
				'list_default'	=> 0,
				'list_public'	=> 0,
				'list_groups'	=> '',
			));

			$prima = $this->manager->is_subscribed($user_id, $list_id);

			$this->manager->subscribe($user->data, '127.0.0.1', $list_id);
			$dopo_iscrizione = $this->manager->is_subscribed($user_id, $list_id);

			$elenco = $this->manager->get_user_lists($user_id);
			$compare = isset($elenco[$list_id]);

			$this->manager->unsubscribe($user_id, false, '127.0.0.1', null, $list_id);
			$dopo_cancellazione = $this->manager->is_subscribed($user_id, $list_id);

			if (!$prima && $dopo_iscrizione && $compare && !$dopo_cancellazione)
			{
				$this->ok($this->language->lang('NL_TEST_CYCLE'), $this->language->lang('NL_TEST_CYCLE_OK'));
			}
			else
			{
				$this->error($this->language->lang('NL_TEST_CYCLE'), $this->language->lang(
					'NL_TEST_CYCLE_BAD',
					$prima ? 1 : 0,
					$dopo_iscrizione ? 1 : 0,
					$compare ? 1 : 0,
					$dopo_cancellazione ? 1 : 0
				));
			}
		}
		catch (\Exception $e)
		{
			$this->error($this->language->lang('NL_TEST_CYCLE'), $e->getMessage());
		}
		catch (\Throwable $e)
		{
			$this->error($this->language->lang('NL_TEST_CYCLE'), $e->getMessage());
		}

		// Ripristino, qualunque cosa sia successa sopra
		if ($list_id > 0)
		{
			$this->manager->delete_list($list_id);
		}

		$this->config->set('newsletter_welcome_email', $benvenuto);
		$this->config->set('newsletter_goodbye_email', $addio);

		$this->ok($this->language->lang('NL_TEST_CLEANUP'), $this->language->lang('NL_TEST_CYCLE_CLEAN'));
	}

	/* =====================================================================
	 * Prova d'invio
	 * ================================================================== */

	/**
	 * Crea una campagna finta e la manda ai soli indirizzi indicati.
	 *
	 * La coda viene riempita a mano: gli iscritti veri non vengono mai letti,
	 * e non esiste in questo metodo un percorso che possa scrivere loro.
	 *
	 * @param array $user_row  Chi esegue la prova
	 * @param string $indirizzo
	 * @param int $inesistenti Indirizzi su un dominio che non esiste
	 * @param int $rifiutati   Caselle inesistenti sul dominio del forum
	 * @param int $soglia      Soglia della pausa solo per questa prova
	 * @return bool
	 */
	public function run_send_test(array $user_row, $indirizzo, $inesistenti, $rifiutati, $soglia)
	{
		$this->righe = array();
		$this->totali = array('ok' => 0, 'warn' => 0, 'error' => 0);
		$this->accepted_invalid = 0;

		$indirizzo = trim((string) $indirizzo);

		if ($indirizzo === '' || !preg_match('/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $indirizzo))
		{
			$this->error($this->language->lang('NL_TEST_ADDRESS'), $this->language->lang('NL_TEST_ADDRESS_BAD'));
			return false;
		}

		$this->section('NL_TEST_PREPARE');

		$campaign_id = $this->create_test_campaign($user_row);

		$this->ok($this->language->lang('NL_TEST_CAMPAIGN'), $this->language->lang('NL_TEST_CAMPAIGN_MADE', $campaign_id));

		$quante = $this->fill_test_queue($campaign_id, $user_row, $indirizzo, $inesistenti, $rifiutati);

		$this->ok($this->language->lang('NL_TEST_QUEUE'), $this->language->lang('NL_TEST_QUEUE_MADE', $quante, $inesistenti, $rifiutati));

		$soglia_prima = isset($this->config['newsletter_fail_streak']) ? (int) $this->config['newsletter_fail_streak'] : null;

		if ($soglia > 0 && $soglia_prima !== null)
		{
			$this->config->set('newsletter_fail_streak', $soglia);
			$this->ok($this->language->lang('NL_TEST_THRESHOLD'), $this->language->lang('NL_TEST_THRESHOLD_SET', $soglia));
		}

		$this->section('NL_TEST_BATCHES');

		$this->run_batches($campaign_id);

		$this->section('NL_TEST_RESULT');

		$this->report_result($campaign_id);

		$this->section('NL_TEST_CLEANUP');

		if ($soglia > 0 && $soglia_prima !== null)
		{
			$this->config->set('newsletter_fail_streak', $soglia_prima);
			$this->ok($this->language->lang('NL_TEST_THRESHOLD'), $this->language->lang('NL_TEST_THRESHOLD_BACK', $soglia_prima));
		}

		$this->manager->delete_campaign($campaign_id);

		$this->ok($this->language->lang('NL_TEST_CAMPAIGN'), $this->language->lang('NL_TEST_CAMPAIGN_GONE'));

		return true;
	}

	/**
	 * @param array $user_row
	 * @return int
	 */
	protected function create_test_campaign(array $user_row)
	{
		$riga = array(
			'campaign_subject'		=> $this->language->lang('NL_TEST_MAIL_SUBJECT'),
			'campaign_body'			=> $this->language->lang('NL_TEST_MAIL_BODY'),
			'campaign_css'			=> '',
			'campaign_format'		=> manager::FORMAT_TEXT,
			'campaign_banner'		=> 0,
			'campaign_topics'		=> '',
			'campaign_groups'		=> '',
			// Nessun gruppo e nessun iscritto: la coda la riempiamo noi
			'campaign_subs'			=> 0,
			'campaign_lang'			=> '',
			'campaign_priority'		=> 3,
			'campaign_importance'	=> 'normal',
			'campaign_sensitivity'	=> '',
			'campaign_from_name'	=> '',
			'campaign_from_email'	=> '',
			'campaign_reply_to'		=> '',
			'campaign_batch'		=> 5,
			'campaign_interval'		=> 60,
			'campaign_schedule'		=> 0,
			'campaign_created'		=> time(),
			'campaign_author'		=> (int) $user_row['user_id'],
			'campaign_author_name'	=> (string) $user_row['username'],
			'campaign_status'		=> manager::STATUS_RUNNING,
		);

		$tabella = $this->table_prefix . 'newsletter_campaigns';

		foreach (array('campaign_public' => 0, 'campaign_list_id' => 0, 'campaign_fail_streak' => 0) as $colonna => $valore)
		{
			if ($this->db_tools->sql_column_exists($tabella, $colonna))
			{
				$riga[$colonna] = $valore;
			}
		}

		return $this->manager->create_campaign($riga);
	}

	/**
	 * Riempie la coda a mano.
	 *
	 * La coda ha una chiave univoca su (campagna, utente): gli indirizzi finti
	 * hanno bisogno di numeri distinti fra loro e diversi da quello vero.
	 *
	 * @param int $campaign_id
	 * @param array $user_row
	 * @param string $indirizzo
	 * @param int $inesistenti
	 * @param int $rifiutati
	 * @return int
	 */
	protected function fill_test_queue($campaign_id, array $user_row, $indirizzo, $inesistenti, $rifiutati)
	{
		$coda = array($this->queue_row($campaign_id, (int) $user_row['user_id'], (string) $user_row['username'], $indirizzo, (string) $user_row['user_lang']));

		$falso = self::FAKE_USER_BASE;
		$lingua = (string) $user_row['user_lang'];

		// Dominio riservato dalla RFC 2606: non esiste e non esistera mai
		for ($i = 1; $i <= $inesistenti; $i++)
		{
			$falso = $this->next_fake_id($falso, (int) $user_row['user_id']);
			$coda[] = $this->queue_row($campaign_id, $falso, 'Test ' . $i, 'nl-test-' . $i . '@non-esiste.invalid', $lingua);
		}

		// Caselle inesistenti sul dominio del forum. Il server e autoritativo
		// per quel dominio, quindi sa gia che non esistono e le rifiuta durante
		// la conversazione: e l'unico modo per vedere un fallimento vero
		// invece di un rimbalzo che arriva mezz'ora dopo
		$dominio = $this->board_domain();

		if ($dominio !== '')
		{
			for ($i = 1; $i <= $rifiutati; $i++)
			{
				$falso = $this->next_fake_id($falso, (int) $user_row['user_id']);
				$coda[] = $this->queue_row($campaign_id, $falso, 'Test R' . $i, 'nl-test-rifiutato-' . $i . '-' . mt_rand(1000, 9999) . '@' . $dominio, $lingua);
			}
		}

		$this->db->sql_multi_insert($this->table_prefix . 'newsletter_queue', $coda);

		return count($coda);
	}

	/**
	 * @param int $attuale
	 * @param int $vero
	 * @return int
	 */
	protected function next_fake_id($attuale, $vero)
	{
		$attuale++;

		if ($attuale === $vero)
		{
			$attuale++;
		}

		return $attuale;
	}

	/**
	 * @return array
	 */
	protected function queue_row($campaign_id, $user_id, $username, $email, $lingua)
	{
		return array(
			'campaign_id'	=> (int) $campaign_id,
			'user_id'		=> (int) $user_id,
			'username'		=> (string) $username,
			'user_email'	=> (string) $email,
			'user_lang'		=> (string) $lingua,
			'queue_status'	=> manager::QUEUE_PENDING,
			'queue_attempts'=> 0,
			'queue_time'	=> 0,
			'queue_error'	=> '',
		);
	}

	/**
	 * Dominio del forum, ricavato dall'indirizzo di contatto
	 *
	 * @return string
	 */
	protected function board_domain()
	{
		$indirizzo = (string) $this->config['board_email'];
		$pezzi = explode('@', $indirizzo);

		return (count($pezzi) === 2 && strpos($pezzi[1], '.') !== false) ? $pezzi[1] : '';
	}

	/**
	 * @param int $campaign_id
	 */
	protected function run_batches($campaign_id)
	{
		// Dopo il primo lotto si mette in pausa e si riprende: e l'unico modo
		// per vedere che la ripresa riparta dal punto giusto invece che
		// dall'inizio, e che una campagna in pausa venga davvero ignorata
		$prova_pausa = true;

		$motivi = array(
			'NL_NOTHING_TO_SEND'	=> 'NL_TEST_NOT_RUNNING',
			'NL_WAITING_INTERVAL'	=> 'NL_TEST_WAITING',
		);

		for ($giro = 1; $giro <= 20; $giro++)
		{
			$etichetta = $this->language->lang('NL_TEST_BATCH', $giro);

			try
			{
				$esito = $this->manager->process($campaign_id, true);
			}
			catch (\Exception $e)
			{
				$this->error($etichetta, $e->getMessage());
				return;
			}
			catch (\Throwable $e)
			{
				$this->error($etichetta, $e->getMessage());
				return;
			}

			if (!empty($esito['reason']))
			{
				$this->warn($etichetta, isset($motivi[$esito['reason']]) ? $this->language->lang($motivi[$esito['reason']]) : $esito['reason']);
				return;
			}

			$testo = $this->language->lang('NL_TEST_BATCH_RESULT', (int) $esito['sent'], (int) $esito['failed'], (int) $esito['pending']);

			if (!empty($esito['auto_paused']))
			{
				$this->warn($etichetta, $testo . ' &mdash; ' . $this->language->lang('NL_TEST_AUTO_PAUSED', (int) $esito['streak']));
				return;
			}

			if (!empty($esito['finished']))
			{
				$this->ok($etichetta, $testo . ' &mdash; ' . $this->language->lang('NL_TEST_FINISHED'));
				return;
			}

			$this->ok($etichetta, $testo);

			if ($prova_pausa)
			{
				$prova_pausa = false;
				$this->pause_and_resume($campaign_id);
			}
		}

		$this->warn($this->language->lang('NL_TEST_BATCHES'), $this->language->lang('NL_TEST_TOO_MANY'));
	}

	/**
	 * Mette in pausa, verifica che l'invio si fermi davvero, e riprende
	 *
	 * @param int $campaign_id
	 */
	protected function pause_and_resume($campaign_id)
	{
		$prima = $this->manager->get_stats($campaign_id);

		$this->manager->set_status($campaign_id, manager::STATUS_PAUSED);

		$esito = $this->manager->process($campaign_id, true);
		$dopo = $this->manager->get_stats($campaign_id);

		if (!empty($esito['reason']) && $dopo['sent'] === $prima['sent'])
		{
			$this->ok($this->language->lang('NL_TEST_PAUSE'), $this->language->lang('NL_TEST_PAUSE_OK'));
		}
		else
		{
			$this->error($this->language->lang('NL_TEST_PAUSE'), $this->language->lang('NL_TEST_PAUSE_BAD'));
		}

		$ripresa = array(
			'campaign_status'	=> manager::STATUS_RUNNING,
			'campaign_finished'	=> 0,
		);

		if ($this->manager->failsafe_available())
		{
			$ripresa['campaign_fail_streak'] = 0;
			$ripresa['campaign_pause_reason'] = '';
		}

		$this->manager->update_campaign($campaign_id, $ripresa);

		$this->ok($this->language->lang('NL_TEST_RESUME'), $this->language->lang('NL_TEST_RESUME_OK', $prima['pending']));
	}

	/**
	 * @param int $campaign_id
	 */
	protected function report_result($campaign_id)
	{
		$stats = $this->manager->get_stats($campaign_id);

		$this->ok($this->language->lang('NL_QUEUE_SENT'), $stats['sent']);

		if ($stats['failed'] > 0)
		{
			$this->warn($this->language->lang('NL_QUEUE_FAILED'), $stats['failed']);
		}
		else
		{
			$this->ok($this->language->lang('NL_QUEUE_FAILED'), 0);
		}

		$stati = array(
			manager::QUEUE_PENDING	=> 'NL_QUEUE_PENDING',
			manager::QUEUE_SENT		=> 'NL_QUEUE_SENT',
			manager::QUEUE_FAILED	=> 'NL_QUEUE_FAILED',
		);

		foreach ($this->manager->get_queue_rows($campaign_id, -1, 0, 60) as $riga)
		{
			$stato = (int) $riga['queue_status'];
			$testo = $this->language->lang(isset($stati[$stato]) ? $stati[$stato] : 'NL_QUEUE_PENDING')
				. ' &middot; ' . $this->language->lang('NL_TEST_ATTEMPTS', (int) $riga['queue_attempts']);

			if ((string) $riga['queue_error'] !== '')
			{
				$testo .= ' &middot; ' . htmlspecialchars((string) $riga['queue_error'], ENT_COMPAT, 'UTF-8');
			}

			$etichetta = '&nbsp;&nbsp;' . htmlspecialchars((string) $riga['user_email'], ENT_COMPAT, 'UTF-8');

			if ($stato === manager::QUEUE_SENT && strpos((string) $riga['user_email'], '@non-esiste.invalid') !== false)
			{
				$this->accepted_invalid++;
			}

			// Anche una riga ancora in attesa, se porta un errore, e qualcosa
			// da guardare: segnarla come a posto direbbe il contrario
			if ($stato === manager::QUEUE_FAILED || (string) $riga['queue_error'] !== '')
			{
				$this->warn($etichetta, $testo);
			}
			else
			{
				$this->ok($etichetta, $testo);
			}
		}

		$campagna = $this->manager->get_campaign($campaign_id);

		if ($campagna && !empty($campagna['campaign_pause_reason']))
		{
			$this->warn($this->language->lang('NL_PAUSED_LABEL'), htmlspecialchars((string) $campagna['campaign_pause_reason'], ENT_COMPAT, 'UTF-8'));
		}

		// Gli indirizzi su dominio inesistente risultano quasi sempre
		// "recapitati": il server accetta e rimbalza dopo, e quel rimbalzo non
		// lo vede nessuno. Va detto ogni volta che ce ne sono, non solo quando
		// non ci sono fallimenti, altrimenti proprio nella prova piu completa
		// - dove i fallimenti ci sono - l'avviso sparisce
		if ($this->accepted_invalid > 0)
		{
			$this->warn($this->language->lang('NL_TEST_ACCEPTED'), $this->language->lang('NL_TEST_ACCEPTED_NOTE', $this->accepted_invalid));
		}
	}

	/* =====================================================================
	 * Raccolta delle righe
	 * ================================================================== */

	protected function section($chiave)
	{
		$this->righe[] = array('type' => 'section', 'label' => $this->language->lang($chiave), 'value' => '');
	}

	protected function ok($etichetta, $valore)
	{
		$this->totali['ok']++;
		$this->righe[] = array('type' => 'ok', 'label' => $etichetta, 'value' => $valore);
	}

	protected function warn($etichetta, $valore)
	{
		$this->totali['warn']++;
		$this->righe[] = array('type' => 'warn', 'label' => $etichetta, 'value' => $valore);
	}

	protected function error($etichetta, $valore)
	{
		$this->totali['error']++;
		$this->righe[] = array('type' => 'error', 'label' => $etichetta, 'value' => $valore);
	}

	/**
	 * @param int $secondi
	 * @return string
	 */
	protected function duration($secondi)
	{
		if ($secondi < 60)
		{
			return $this->language->lang('NL_TEST_SECONDS', $secondi);
		}

		if ($secondi < 3600)
		{
			return $this->language->lang('NL_TEST_MINUTES', (int) round($secondi / 60));
		}

		if ($secondi < 86400)
		{
			return $this->language->lang('NL_TEST_HOURS', (int) round($secondi / 3600));
		}

		return $this->language->lang('NL_TEST_DAYS', (int) round($secondi / 86400));
	}
}
