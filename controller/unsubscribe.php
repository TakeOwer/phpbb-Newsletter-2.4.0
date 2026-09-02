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

use salvocortesiano\newsletter\core\mailer;

/**
 * Disiscrizione con un solo clic.
 *
 * La pagina funziona senza che l'utente sia collegato, ed e l'unica scelta
 * sensata: chi riceve un messaggio che non vuole piu non ha voglia di
 * ricordare la parola d'ordine per liberarsene, e ogni ostacolo in piu si
 * traduce in una segnalazione di posta indesiderata invece che in una
 * cancellazione. La firma nel collegamento garantisce che a disiscriversi sia
 * il destinatario e non chi si diverte a cambiare i numeri nell'indirizzo.
 */
class unsubscribe
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\controller\helper */
	protected $helper;

	/** @var \salvocortesiano\newsletter\core\manager */
	protected $manager;

	/**
	 * Constructor
	 */
	public function __construct(
		\phpbb\config\config $config,
		\phpbb\language\language $language,
		\phpbb\template\template $template,
		\phpbb\user $user,
		\phpbb\controller\helper $helper,
		\salvocortesiano\newsletter\core\manager $manager
	)
	{
		$this->config = $config;
		$this->language = $language;
		$this->template = $template;
		$this->user = $user;
		$this->helper = $helper;
		$this->manager = $manager;
	}

	/**
	 * Uscita da tutti i notiziari.
	 *
	 * E la rotta storica, quella che gira dentro le email gia spedite: il suo
	 * significato non cambia, e continua a spegnere anche la casella delle
	 * email di massa nel profilo.
	 *
	 * @param int    $user_id
	 * @param string $token
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function handle($user_id, $token)
	{
		$atteso = mailer::token((string) $this->config['newsletter_secret'], (int) $user_id);

		return $this->esegui((int) $user_id, $token, $atteso, -1, true);
	}

	/**
	 * Uscita da un solo notiziario.
	 *
	 * Qui la casella del profilo non viene toccata: riguarda tutto cio che il
	 * forum manda, e chi esce da un notiziario non ha chiesto di zittire anche
	 * gli altri.
	 *
	 * @param int    $user_id
	 * @param int    $list_id
	 * @param string $token
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function handle_list($user_id, $list_id, $token)
	{
		$atteso = mailer::list_token((string) $this->config['newsletter_secret'], (int) $user_id, (int) $list_id);

		return $this->esegui((int) $user_id, $token, $atteso, (int) $list_id, false);
	}

	/**
	 * Verifica la firma ed esegue la disiscrizione
	 *
	 * @param int    $user_id
	 * @param string $token
	 * @param string $atteso
	 * @param int    $list_id  -1 per tutti
	 * @param bool   $blocca   Spegnere anche le email di massa del profilo
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	protected function esegui($user_id, $token, $atteso, $list_id, $blocca)
	{
		$this->language->add_lang('newsletter', 'salvocortesiano/newsletter');

		// hash_equals confronta in tempo costante: un confronto normale
		// impiegherebbe piu tempo quanti piu caratteri iniziali coincidono, e
		// da quella differenza si puo ricostruire la firma un carattere per
		// volta
		$valido = ((string) $this->config['newsletter_secret'] !== '') && hash_equals($atteso, (string) $token);

		$dati = $valido ? $this->manager->get_user_row($user_id) : false;

		if (!$valido || !$dati)
		{
			$this->template->assign_var('S_NL_INVALID', true);

			return $this->helper->render(
				'@salvocortesiano_newsletter/newsletter_unsubscribe.html',
				$this->language->lang('NL_UNSUBSCRIBE_TITLE')
			);
		}

		$cambiato = $this->manager->unsubscribe($user_id, $blocca, $this->user->ip, null, $list_id);

		$nome_lista = ($list_id > 0) ? $this->manager->list_name($list_id) : '';

		// Restano altri notiziari attivi? Se si, l'utente deve saperlo: avere
		// premuto un pulsante di disiscrizione e continuare a ricevere posta
		// dallo stesso forum e il modo migliore per essere segnalati
		$rimaste = $this->manager->get_user_lists($user_id);

		$this->template->assign_vars(array(
			'S_NL_DONE'			=> true,
			'S_NL_ALREADY'		=> !$cambiato,
			'S_NL_ONE_LIST'		=> ($list_id > 0),
			'S_NL_REMAINING'	=> !empty($rimaste),
			'NL_LIST_NAME'		=> $nome_lista,
			'NL_REMAINING'		=> count($rimaste),
			'NL_USERNAME'		=> (string) $dati['username'],
			'NL_EMAIL'			=> (string) $dati['user_email'],
			'NL_BOARD_NAME'		=> (string) $this->config['sitename'],
			'U_NL_BOARD'		=> generate_board_url(),
			'U_NL_ALL'			=> $this->tutti_url($user_id),
		));

		return $this->helper->render(
			'@salvocortesiano_newsletter/newsletter_unsubscribe.html',
			$this->language->lang('NL_UNSUBSCRIBE_TITLE')
		);
	}

	/**
	 * Collegamento per uscire da tutto, offerto dopo una uscita singola
	 *
	 * @param int $user_id
	 * @return string
	 */
	protected function tutti_url($user_id)
	{
		$token = mailer::token((string) $this->config['newsletter_secret'], $user_id);

		try
		{
			return $this->helper->route('salvocortesiano_newsletter_unsubscribe', array(
				'user_id'	=> $user_id,
				'token'		=> $token,
			));
		}
		catch (\Exception $e)
		{
			return '';
		}
	}
}
