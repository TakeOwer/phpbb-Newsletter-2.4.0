<?php
/**
 *
 * Newsletter. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 salvocortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\newsletter\ucp;

class newsletter_module
{
	/** @var string */
	public $u_action;

	/** @var bool Inviare il messaggio che conferma la cancellazione */
	protected $config_goodbye = false;

	/** @var string */
	public $tpl_name;

	/** @var string */
	public $page_title;

	/**
	 * Punto di ingresso del modulo
	 *
	 * @param int    $id
	 * @param string $mode
	 */
	public function main($id, $mode)
	{
		global $phpbb_container;

		/** @var \phpbb\config\config $config */
		$config = $phpbb_container->get('config');
		/** @var \phpbb\config\db_text $config_text */
		$config_text = $phpbb_container->get('config_text');
		/** @var \phpbb\request\request $request */
		$request = $phpbb_container->get('request');
		/** @var \phpbb\template\template $template */
		$template = $phpbb_container->get('template');
		/** @var \phpbb\language\language $language */
		$language = $phpbb_container->get('language');
		/** @var \phpbb\user $user */
		$user = $phpbb_container->get('user');
		/** @var \salvocortesiano\newsletter\core\manager $manager */
		$manager = $phpbb_container->get('salvocortesiano.newsletter.manager');

		$language->add_lang('newsletter', 'salvocortesiano/newsletter');

		$this->tpl_name = 'ucp_newsletter';
		$this->page_title = $language->lang('UCP_NEWSLETTER');

		$php_ext = $phpbb_container->getParameter('core.php_ext');

		$form_key = 'salvocortesiano_newsletter_ucp';
		add_form_key($form_key);

		$user_id = (int) $user->data['user_id'];
		$indirizzo = trim((string) $user->data['user_email']);
		$attivo = !empty($config['newsletter_subs_enabled']);
		$this->config_goodbye = !empty($config['newsletter_goodbye_email']);

		// Notiziari offerti: quelli aperti, piu quelli chiusi a cui si e gia
		// iscritti. Chi si trova dentro un notiziario chiuso deve poterne
		// uscire: togliergli la casella lo lascerebbe iscritto senza via
		// d'uscita se non il collegamento nel messaggio
		$iscrizioni = $manager->get_user_lists($user_id);
		$disponibili = array();

		if ($manager->lists_available())
		{
			foreach ($manager->get_lists() as $notiziario)
			{
				$list_id = (int) $notiziario['list_id'];

				if (!empty($notiziario['list_enabled']) || isset($iscrizioni[$list_id]))
				{
					$disponibili[$list_id] = $notiziario;
				}
			}
		}
		else
		{
			// Prima della migrazione i notiziari non esistono, ma la pagina
			// deve funzionare lo stesso: si costruisce un notiziario finto
			// intestato al forum, e tutto il resto - casella, iscrizione,
			// disiscrizione - prosegue senza sapere della differenza
			$disponibili[0] = array(
				'list_id'		=> 0,
				'list_name'		=> html_entity_decode((string) $config['sitename'], ENT_COMPAT, 'UTF-8'),
				'list_desc'		=> '',
				'list_enabled'	=> 1,
			);
		}

		$messaggio = '';
		$errore = '';

		if ($request->is_set_post('submit'))
		{
			if (!check_form_key($form_key))
			{
				trigger_error('FORM_INVALID');
			}

			if (!$attivo)
			{
				trigger_error('NL_SUBS_CLOSED');
			}

			if ($indirizzo === '')
			{
				$errore = $language->lang('NL_NO_EMAIL');
			}
			else
			{
				$messaggio = $this->applica($manager, $user, $request, $language, $disponibili, $iscrizioni);
				$iscrizioni = $manager->get_user_lists($user_id);
			}
		}

		$conteggi = $manager->count_by_list();

		foreach ($disponibili as $list_id => $notiziario)
		{
			$template->assign_block_vars('notiziari', array(
				'LIST_ID'		=> $list_id,
				'NAME'			=> (string) $notiziario['list_name'],
				'DESCRIPTION'	=> (string) $notiziario['list_desc'],
				'SUBSCRIBERS'	=> isset($conteggi[$list_id]) ? $conteggi[$list_id] : 0,
				'S_CHECKED'		=> isset($iscrizioni[$list_id]),
				'S_CLOSED'		=> empty($notiziario['list_enabled']),
				'SINCE'			=> (isset($iscrizioni[$list_id]) && $iscrizioni[$list_id] > 0)
					? $user->format_date((int) $iscrizioni[$list_id])
					: '',
				// Via d'uscita diretta, senza passare dalla spunta: e lo stesso
				// collegamento che si trova in fondo ai messaggi ricevuti
				'U_UNSUB'		=> isset($iscrizioni[$list_id])
					? $manager->unsubscribe_link($user->data, $list_id)
					: '',
			));
		}

		// Campo vuoto significa "usa il testo predefinito": una pagina che
		// chiede di iscriversi senza dire a che cosa non convince nessuno
		$intro = trim((string) $config_text->get('newsletter_intro'));

		if ($intro === '')
		{
			$intro = $language->lang('NL_DEFAULT_INTRO');
		}

		$template->assign_vars(array(
			'S_NL_ENABLED'		=> $attivo,
			'S_NL_HAS_EMAIL'	=> ($indirizzo !== ''),
			'S_NL_LISTS'		=> !empty($disponibili),
			'S_NL_ANY'			=> !empty($iscrizioni),
			'NL_EMAIL'			=> $indirizzo,
			'NL_INTRO'			=> $intro,
			'NL_BOARD_NAME'		=> $config['sitename'],
			'NL_MESSAGE'		=> $messaggio,
			'NL_ERROR'			=> $errore,
			'U_NL_CHANGE_EMAIL'	=> append_sid('ucp.' . $php_ext, 'i=ucp_profile&amp;mode=reg_details'),
			'U_NL_PREFS'		=> append_sid('ucp.' . $php_ext, 'i=ucp_prefs&amp;mode=personal'),
			// Identificativo e modalita viaggiano anche come campi nascosti.
			// L'indirizzo del modulo lo fornisce phpBB, ma se per qualsiasi
			// ragione arriva incompleto la richiesta finisce su un modulo che
			// non esiste e phpBB risponde "Modulo non accessibile" prima ancora
			// di eseguire il nostro codice. Con i due campi la richiesta si
			// identifica da sola
			'NL_MODULE_ID'		=> $id,
			'NL_MODULE_MODE'	=> $mode,
			'U_NL_UNSUB_ALL'	=> !empty($iscrizioni) ? $manager->unsubscribe_link($user->data, 0) : '',
			'U_ACTION'			=> $this->u_action,
		));
	}

	/**
	 * Applica le scelte fatte, notiziario per notiziario
	 *
	 * @param \salvocortesiano\newsletter\core\manager $manager
	 * @param \phpbb\user $user
	 * @param \phpbb\request\request $request
	 * @param \phpbb\language\language $language
	 * @param array $disponibili
	 * @param array $iscrizioni
	 * @return string Messaggio da mostrare
	 */
	protected function applica($manager, $user, $request, $language, array $disponibili, array $iscrizioni)
	{
		$user_id = (int) $user->data['user_id'];
		$scelte = array_map('intval', $request->variable('nl_lists', array(0)));

		$aggiunti = 0;
		$tolti = 0;

		foreach ($disponibili as $list_id => $notiziario)
		{
			$vuole = in_array($list_id, $scelte, true);
			$aveva = isset($iscrizioni[$list_id]);

			if ($vuole === $aveva)
			{
				continue;
			}

			// Un notiziario chiuso non accetta nuove iscrizioni: da li si puo
			// soltanto uscire
			if ($vuole && empty($notiziario['list_enabled']))
			{
				continue;
			}

			if ($vuole)
			{
				// La casella del profilo va riaperta prima di iscrivere: chi si
				// era disiscritto in passato l'ha chiusa, e senza riaprirla
				// l'iscrizione resterebbe senza effetto
				$manager->allow_massemail($user_id);
				$manager->subscribe($user->data, $user->ip, $list_id);
				$aggiunti++;
			}
			else
			{
				// Uscire da un notiziario non spegne le email di massa del
				// profilo: quella scelta riguarda tutto cio che il forum manda,
				// non un notiziario solo
				$manager->unsubscribe($user_id, false, $user->ip, null, $list_id);
				$tolti++;

				if (!empty($this->config_goodbye))
				{
					$manager->send_service_email($user->data, 'NL_MAIL_GOODBYE_SUBJECT', 'NL_MAIL_GOODBYE_BODY', $list_id);
				}
			}
		}

		if (!$aggiunti && !$tolti)
		{
			return $language->lang('NL_NOTHING_CHANGED');
		}

		return $language->lang('NL_LISTS_UPDATED', $aggiunti, $tolti);
	}
}
