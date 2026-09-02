<?php
/**
 *
 * Newsletter. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 salvocortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\newsletter\controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * Archivio pubblico dei numeri inviati.
 *
 * Serve a due cose. La prima e il collegamento in cima al messaggio, quello
 * che si apre quando il lettore di posta ha rovinato la formattazione: senza
 * una copia consultabile, un messaggio arrivato male e perso. La seconda e
 * dare a chi non e iscritto un posto dove vedere che cosa si perde.
 */
class archive
{
	/** Visibilita configurata */
	const CLOSED = 0;
	const REGISTERED = 1;
	const EVERYONE = 2;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\controller\helper */
	protected $helper;

	/** @var \phpbb\pagination */
	protected $pagination;

	/** @var \salvocortesiano\newsletter\core\manager */
	protected $manager;

	/** @var \salvocortesiano\newsletter\core\html */
	protected $html;

	/** @var \salvocortesiano\newsletter\core\access */
	protected $access;

	/**
	 * Constructor
	 */
	public function __construct(
		\phpbb\config\config $config,
		\phpbb\auth\auth $auth,
		\phpbb\language\language $language,
		\phpbb\template\template $template,
		\phpbb\request\request $request,
		\phpbb\user $user,
		\phpbb\controller\helper $helper,
		\phpbb\pagination $pagination,
		\salvocortesiano\newsletter\core\manager $manager,
		\salvocortesiano\newsletter\core\html $html,
		\salvocortesiano\newsletter\core\access $access
	)
	{
		$this->config = $config;
		$this->auth = $auth;
		$this->language = $language;
		$this->template = $template;
		$this->request = $request;
		$this->user = $user;
		$this->helper = $helper;
		$this->pagination = $pagination;
		$this->manager = $manager;
		$this->html = $html;
		$this->access = $access;
	}

	/**
	 * Elenco dei numeri pubblicati
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function index()
	{
		return $this->elenco(0);
	}

	/**
	 * Numeri di un solo notiziario
	 *
	 * @param int $list_id
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function lista($list_id)
	{
		return $this->elenco((int) $list_id);
	}

	/**
	 * Elenco dei numeri, eventualmente ristretto a un notiziario
	 *
	 * @param int $list_id
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	protected function elenco($list_id)
	{
		$this->prepare();

		$per_pagina = max(5, min(100, (int) $this->config['newsletter_archive_per_page']));
		$start = max(0, $this->request->variable('start', 0));

		$totale = $this->manager->count_archive($list_id);

		foreach ($this->manager->get_archive($start, $per_pagina, $list_id) as $riga)
		{
			$this->template->assign_block_vars('numeri', array(
				'SUBJECT'	=> (string) $riga['campaign_subject'],
				'DATE'		=> $this->user->format_date((int) $riga['campaign_finished']),
				'AUTHOR'	=> (string) $riga['campaign_author_name'],
				'VIEWS'		=> (int) $riga['campaign_views'],
				'RECIPIENTS'=> (int) $riga['campaign_sent'],
				'FORMAT'	=> $this->language->lang($riga['campaign_format'] ? 'NL_FORMAT_HTML' : 'NL_FORMAT_TEXT'),
				'U_VIEW'	=> $this->helper->route('salvocortesiano_newsletter_issue', array('campaign_id' => (int) $riga['campaign_id'])),
			));
		}

		// Indirizzo di base come stringa e scostamento numerico, lo stesso
		// schema usato nel pannello: append_sid sa gia se attaccare la
		// interrogazione con ? oppure con &
		$base = ($list_id > 0)
			? $this->helper->route('salvocortesiano_newsletter_list', array('list_id' => $list_id))
			: $this->helper->route('salvocortesiano_newsletter_archive');

		// Barra dei notiziari, mostrata solo quando ce n'e piu di uno con
		// almeno un numero: con un notiziario solo sarebbe un filtro inutile
		$notiziari = $this->manager->get_archive_lists();

		if (count($notiziari) > 1)
		{
			$this->template->assign_block_vars('notiziari', array(
				'NAME'			=> $this->language->lang('NL_LIST_FILTER_ALL'),
				'COUNT'			=> $this->manager->count_archive(),
				'S_SELECTED'	=> ($list_id === 0),
				'U_LIST'		=> $this->helper->route('salvocortesiano_newsletter_archive'),
			));

			foreach ($notiziari as $notiziario)
			{
				$id = (int) $notiziario['list_id'];

				$this->template->assign_block_vars('notiziari', array(
					'NAME'			=> (string) $notiziario['list_name'],
					'DESCRIPTION'	=> (string) $notiziario['list_desc'],
					'COUNT'			=> (int) $notiziario['numeri'],
					'S_SELECTED'	=> ($list_id === $id),
					'U_LIST'		=> $this->helper->route('salvocortesiano_newsletter_list', array('list_id' => $id)),
				));
			}
		}

		$nome_lista = ($list_id > 0) ? $this->manager->list_name($list_id) : '';

		$this->pagination->generate_template_pagination(
			$base,
			'pagination',
			'start',
			$totale,
			$per_pagina,
			$start
		);

		$this->template->assign_vars(array(
			'NL_TOTAL'			=> $totale,
			'NL_ARCHIVE_INTRO'	=> ($nome_lista !== '')
				? $this->language->lang('NL_ARCHIVE_INTRO_LIST', $nome_lista)
				: $this->language->lang('NL_ARCHIVE_INTRO', (string) $this->config['sitename']),
			'NL_LIST_NAME'		=> $nome_lista,
			'S_NL_EMPTY'		=> ($totale === 0),
			'U_NL_SUBSCRIBE'	=> $this->ucp_url(),
			'S_NL_CAN_SUBSCRIBE'=> ($this->user->data['user_id'] != ANONYMOUS && !empty($this->config['newsletter_subs_enabled'])),
		));

		return $this->helper->render(
			'@salvocortesiano_newsletter/newsletter_archive.html',
			$this->language->lang('NL_ARCHIVE_TITLE')
		);
	}

	/**
	 * Un singolo numero
	 *
	 * @param int $campaign_id
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function view($campaign_id)
	{
		$this->prepare();

		$campagna = $this->manager->get_campaign($campaign_id);

		if (!$campagna || !$this->manager->is_public($campagna))
		{
			// Stessa risposta sia per un numero mai esistito sia per uno non
			// pubblicato: distinguerli permetterebbe di scoprire quante
			// newsletter sono state scritte e quando, provando i numeri uno a uno
			throw new \phpbb\exception\http_exception(404, 'NL_ARCHIVE_NOT_FOUND');
		}

		$this->manager->add_view($campaign_id);

		$html = !empty($campagna['campaign_format']);
		$corpo = $this->manager->archive_body($campagna);

		if ($html)
		{
			// La marcatura del messaggio viene resa dentro un riquadro isolato
			// invece che dentro la pagina: un numero puo contenere HTML
			// arbitrario, e dentro la pagina del forum ne romperebbe il
			// disegno o peggio. L'attributo sandbox toglie script, moduli e
			// navigazione, e senza allow-same-origin quel contenuto non puo
			// nemmeno leggere i cookie del forum
			$documento = $this->html->wrap_document(
				$this->html->sanitize($corpo),
				(string) $campagna['campaign_subject'],
				(string) $campagna['campaign_css']
			);

			$this->template->assign_var('NL_BODY_HTML', htmlspecialchars($documento, ENT_QUOTES, 'UTF-8'));
		}
		else
		{
			$this->template->assign_var('NL_BODY_TEXT', nl2br(htmlspecialchars($corpo, ENT_COMPAT, 'UTF-8')));
		}

		$this->template->assign_vars(array(
			'S_NL_HTML'		=> $html,
			'NL_SUBJECT'	=> (string) $campagna['campaign_subject'],
			'NL_DATE'		=> $this->user->format_date((int) $campagna['campaign_finished']),
			'NL_AUTHOR'		=> (string) $campagna['campaign_author_name'],
			'NL_LIST_NAME'	=> isset($campagna['campaign_list_id']) ? $this->manager->list_name((int) $campagna['campaign_list_id']) : '',
			'U_NL_LIST'		=> (isset($campagna['campaign_list_id']) && (int) $campagna['campaign_list_id'] > 0)
				? $this->helper->route('salvocortesiano_newsletter_list', array('list_id' => (int) $campagna['campaign_list_id']))
				: '',
			'NL_VIEWS'		=> (int) $campagna['campaign_views'] + 1,
			'NL_RECIPIENTS'	=> (int) $campagna['campaign_sent'],
			'U_NL_ARCHIVE'	=> $this->helper->route('salvocortesiano_newsletter_archive'),
			'U_NL_SUBSCRIBE'=> $this->ucp_url(),
			'S_NL_CAN_SUBSCRIBE'=> ($this->user->data['user_id'] != ANONYMOUS && !empty($this->config['newsletter_subs_enabled'])),
		));

		return $this->helper->render(
			'@salvocortesiano_newsletter/newsletter_view.html',
			(string) $campagna['campaign_subject']
		);
	}

	/**
	 * Lingua e controllo di visibilita, comuni alle due pagine
	 */
	protected function prepare()
	{
		$this->language->add_lang('newsletter', 'salvocortesiano/newsletter');

		$accesso = $this->access->archive_access($this->user->data, $this->auth->acl_get('a_newsletter'));

		if ($accesso === 'login')
		{
			// login_box porta all'accesso e riporta qui dopo: e la risposta
			// giusta per un ospite davanti a una pagina riservata, meglio di un
			// errore che sembra dire che la pagina non esiste
			login_box('', $this->language->lang('NL_ARCHIVE_LOGIN'));
		}

		if ($accesso !== 'ok')
		{
			throw new \phpbb\exception\http_exception(404, 'NL_ARCHIVE_NOT_FOUND');
		}
	}

	/**
	 * Pagina di iscrizione nel pannello utente
	 *
	 * @return string
	 */
	protected function ucp_url()
	{
		return append_sid(generate_board_url() . '/ucp.' . $this->php_ext(), 'i=-salvocortesiano-newsletter-ucp-newsletter_module&amp;mode=manage');
	}

	/**
	 * @return string
	 */
	protected function php_ext()
	{
		global $phpEx;

		return isset($phpEx) ? $phpEx : 'php';
	}
}
