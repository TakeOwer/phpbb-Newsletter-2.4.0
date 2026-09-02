<?php
/**
 *
 * Newsletter. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 salvocortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\newsletter\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \salvocortesiano\newsletter\core\access */
	protected $access;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\controller\helper */
	protected $helper;

	/**
	 * Constructor
	 */
	public function __construct(
		\phpbb\config\config $config,
		\phpbb\user $user,
		\phpbb\auth\auth $auth,
		\phpbb\language\language $language,
		\phpbb\template\template $template,
		\phpbb\controller\helper $helper,
		\salvocortesiano\newsletter\core\access $access
	)
	{
		$this->config = $config;
		$this->user = $user;
		$this->auth = $auth;
		$this->access = $access;
		$this->language = $language;
		$this->template = $template;
		$this->helper = $helper;
	}

	/**
	 * {@inheritdoc}
	 */
	static public function getSubscribedEvents()
	{
		return array(
			'core.user_setup'	=> 'user_setup',
			'core.permissions'	=> 'add_permissions',
			'core.page_header'	=> 'page_header',
		);
	}

	/**
	 * Carica il file di lingua dei registri.
	 *
	 * Deve essere disponibile ovunque si mostri il giornale di phpBB, non solo
	 * nelle pagine dell'estensione: senza, in ACP - Manutenzione - Log le voci
	 * comparirebbero come chiavi grezze del tipo LOG_NEWSLETTER_SENT.
	 *
	 * @param \phpbb\event\data $event
	 */
	public function user_setup($event)
	{
		$lang_set_ext = $event['lang_set_ext'];

		$lang_set_ext[] = array(
			'ext_name'	=> 'salvocortesiano/newsletter',
			'lang_set'	=> 'logs_newsletter',
		);

		$event['lang_set_ext'] = $lang_set_ext;
	}

	/**
	 * Collegamento all'archivio nella barra di navigazione.
	 *
	 * Compare solo se l'archivio e aperto a chi sta guardando: mostrarlo a un
	 * ospite quando serve l'accesso significa mandarlo su una pagina che gli
	 * chiede di identificarsi, cioe promettere qualcosa e non mantenerlo.
	 *
	 * @param \phpbb\event\data $event
	 */
	public function page_header($event)
	{
		if (empty($this->config['newsletter_archive_navbar']))
		{
			return;
		}

		// La voce compare solo a chi puo davvero entrare: mostrarla a un ospite
		// davanti a un archivio riservato significa prometterlo e poi chiedergli
		// di identificarsi, e mostrarla a chi non e nel gruppo giusto significa
		// portarlo a una pagina che risponde "non disponibile"
		if ($this->access->archive_access($this->user->data, $this->auth->acl_get('a_newsletter')) !== 'ok')
		{
			return;
		}

		// Questo metodo gira su OGNI pagina del forum. Se la costruzione
		// dell'indirizzo fallisce - la rotta non e ancora nella cache compilata
		// perche i file sono stati sostituiti senza svuotarla - una eccezione
		// non raccolta qui manda in errore l'intero forum, non la sola voce di
		// menu. Un collegamento che non compare e un inconveniente; un forum
		// che risponde 500 a tutti e un guasto
		try
		{
			$indirizzo = $this->helper->route('salvocortesiano_newsletter_archive');
		}
		catch (\Exception $e)
		{
			return;
		}
		catch (\Throwable $e)
		{
			return;
		}

		$this->language->add_lang('newsletter', 'salvocortesiano/newsletter');

		$this->template->assign_var('U_NL_ARCHIVE', $indirizzo);
	}

	/**
	 * Dichiara il permesso nel pannello dei permessi.
	 *
	 * Il file permissions_newsletter.php da solo fornisce la traduzione ma non
	 * basta a far comparire la voce: e questo evento a inserirla nella
	 * categoria giusta.
	 *
	 * @param \phpbb\event\data $event
	 */
	public function add_permissions($event)
	{
		$this->language->add_lang('permissions_newsletter', 'salvocortesiano/newsletter');

		$permissions = $event['permissions'];

		$permissions['a_newsletter'] = array('lang' => 'ACL_A_NEWSLETTER', 'cat' => 'misc');

		$event['permissions'] = $permissions;
	}
}
