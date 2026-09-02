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

use salvocortesiano\newsletter\core\manager;

/**
 * Scrittura di una newsletter da parte di chi non amministra il forum.
 *
 * Volutamente piu povera del modulo di amministrazione: niente HTML grezzo,
 * niente scelta dei gruppi, niente cadenza di invio. Chi scrive da qui compone
 * un testo e sceglie a quale notiziario mandarlo; tutto il resto - a quanti
 * alla volta, con quale mittente, con quale immagine - resta deciso da chi
 * amministra. Ogni cosa che si aggiunge qui e una cosa in piu che puo fare chi
 * entrasse in un profilo altrui.
 */
class send_module
{
	/** @var string */
	public $u_action;

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
		/** @var \salvocortesiano\newsletter\core\access $access */
		$access = $phpbb_container->get('salvocortesiano.newsletter.access');

		$language->add_lang('newsletter', 'salvocortesiano/newsletter');

		$this->tpl_name = 'ucp_newsletter_send';
		$this->page_title = $language->lang('UCP_NEWSLETTER_SEND');

		$form_key = 'salvocortesiano_newsletter_send';
		add_form_key($form_key);

		$user_id = (int) $user->data['user_id'];

		// Il controllo si rifa a ogni richiesta e non si fida della schermata
		// precedente: un gruppo puo essere stato tolto nel frattempo, e la
		// pagina resta raggiungibile da chi ne conosce l'indirizzo
		if (!$access->can_send($user->data))
		{
			$template->assign_var('S_NL_DENIED', true);
			return;
		}

		$notiziari = $this->notiziari_disponibili($manager, $access);

		if (empty($notiziari))
		{
			$template->assign_var('S_NL_NO_LISTS', true);
			return;
		}

		$limite = isset($config['newsletter_send_limit']) ? (int) $config['newsletter_send_limit'] : 2;
		$fatti = $manager->count_recent_sends($user_id);
		$esaurito = ($limite > 0 && $fatti >= $limite);

		$dati = array(
			'subject'	=> '',
			'body'		=> '',
			'format'	=> manager::FORMAT_BBCODE,
			'list'		=> (int) key($notiziari),
		);

		$errori = array();
		$anteprima = '';

		if ($request->is_set_post('submit_send') || $request->is_set_post('submit_preview'))
		{
			if (!check_form_key($form_key))
			{
				trigger_error('FORM_INVALID');
			}

			$dati = array(
				'subject'	=> html_entity_decode($request->variable('nl_subject', '', true), ENT_COMPAT, 'UTF-8'),
				'body'		=> html_entity_decode($request->variable('nl_body', '', true), ENT_COMPAT, 'UTF-8'),
				// Solo testo semplice o BBCode: l'HTML grezzo resta agli
				// amministratori, perche da li si costruiscono messaggi che
				// imitano qualunque cosa
				'format'	=> $request->variable('nl_format', 0) ? manager::FORMAT_BBCODE : manager::FORMAT_TEXT,
				'list'		=> $request->variable('nl_list', 0),
			);

			$errori = $this->controlla($dati, $notiziari, $manager, $language);

			if (empty($errori) && $request->is_set_post('submit_preview'))
			{
				$anteprima = $this->anteprima($manager, $user, $dati);
			}

			if (empty($errori) && $request->is_set_post('submit_send'))
			{
				if ($esaurito)
				{
					trigger_error($this->messaggio($language, $language->lang('NL_SEND_LIMIT_REACHED', $limite)));
				}

				$this->salva($manager, $access, $user, $dati, $language, $config);
			}
		}

		$destinatari = $manager->count_recipients(array(), true, '', (int) $dati['list']);

		foreach ($notiziari as $list_id => $notiziario)
		{
			$template->assign_block_vars('notiziari', array(
				'VALUE'			=> $list_id,
				'NAME'			=> (string) $notiziario['list_name'],
				'S_SELECTED'	=> ((int) $dati['list'] === $list_id),
			));
		}

		foreach ($errori as $errore)
		{
			$template->assign_block_vars('errori', array('MESSAGE' => $errore));
		}

		// Le faccine del forum, come nel pannello di amministrazione: chi
		// scrive da qui compone lo stesso BBCode e non ha motivo di avere
		// meno strumenti
		if (!empty($config['newsletter_bbcode_smilies']))
		{
			global $phpbb_root_path;

			$cartella = $phpbb_root_path . $config['smilies_path'] . '/';

			foreach ($manager->get_smilies() as $faccina)
			{
				$template->assign_block_vars('faccine', array(
					'CODE'		=> htmlspecialchars($faccina['code'], ENT_QUOTES, 'UTF-8'),
					'EMOTION'	=> htmlspecialchars($faccina['emotion'], ENT_COMPAT, 'UTF-8'),
					'SRC'		=> $cartella . rawurlencode($faccina['url']),
				));
			}
		}

		$template->assign_vars(array(
			'S_NL_CAN_SEND'		=> true,
			'S_NL_APPROVAL'		=> $access->needs_approval(),
			'S_NL_EXHAUSTED'	=> $esaurito,
			'S_FORMAT_BBCODE'	=> ((int) $dati['format'] === manager::FORMAT_BBCODE),
			'S_PREVIEW'			=> ($anteprima !== ''),
			'NL_PREVIEW'		=> $anteprima,
			'NL_SUBJECT'		=> htmlspecialchars($dati['subject'], ENT_COMPAT, 'UTF-8'),
			'NL_BODY'			=> htmlspecialchars($dati['body'], ENT_COMPAT, 'UTF-8'),
			'NL_RECIPIENTS'		=> $destinatari,
			'NL_LIMIT'			=> $limite,
			'NL_DONE'			=> $fatti,
			'NL_LEFT'			=> ($limite > 0) ? max(0, $limite - $fatti) : 0,
			'NL_MODULE_ID'		=> $id,
			'NL_MODULE_MODE'	=> $mode,
			'U_ACTION'			=> $this->u_action,
		));
	}

	/**
	 * Messaggio di esito con il collegamento per tornare indietro.
	 *
	 * Non si usa adm_back_link: quella funzione vive in includes/functions_acp,
	 * che nel pannello utente non e caricato. Chiamarla qui fa cadere la pagina
	 * con un errore fatale proprio nel momento in cui l'operazione e riuscita.
	 *
	 * @param \phpbb\language\language $language
	 * @param string $testo
	 * @return string
	 */
	protected function messaggio($language, $testo)
	{
		return $testo . '<br /><br />' . $language->lang(
			'RETURN_UCP',
			'<a href="' . $this->u_action . '">',
			'</a>'
		);
	}

	/**
	 * Notiziari che questo utente puo usare.
	 *
	 * Se l'amministratore non ne ha assegnato nessuno, l'elenco resta vuoto e
	 * la pagina lo dice: aprire su tutti i notiziari perche la lista e vuota
	 * sarebbe la lettura sbagliata di una casella non compilata.
	 *
	 * @param \salvocortesiano\newsletter\core\manager $manager
	 * @param \salvocortesiano\newsletter\core\access  $access
	 * @return array
	 */
	protected function notiziari_disponibili($manager, $access)
	{
		$ammessi = $access->allowed_lists();

		if (empty($ammessi))
		{
			return array();
		}

		$disponibili = array();

		foreach ($manager->get_lists(true) as $notiziario)
		{
			$list_id = (int) $notiziario['list_id'];

			if (in_array($list_id, $ammessi, true))
			{
				$disponibili[$list_id] = $notiziario;
			}
		}

		return $disponibili;
	}

	/**
	 * @param array $dati
	 * @param array $notiziari
	 * @param \salvocortesiano\newsletter\core\manager $manager
	 * @param \phpbb\language\language $language
	 * @return array
	 */
	protected function controlla(array $dati, array $notiziari, $manager, $language)
	{
		$errori = array();

		if (trim($dati['subject']) === '')
		{
			$errori[] = $language->lang('NL_ERROR_NO_SUBJECT');
		}

		if (trim($dati['body']) === '')
		{
			$errori[] = $language->lang('NL_ERROR_NO_BODY');
		}
		else if ((int) $dati['format'] === manager::FORMAT_BBCODE)
		{
			$avvisi = array();
			$manager->bbcode()->to_storage($dati['body'], $avvisi);

			foreach ($avvisi as $avviso)
			{
				$errori[] = $avviso;
			}
		}

		if (!isset($notiziari[(int) $dati['list']]))
		{
			$errori[] = $language->lang('NL_ERROR_NO_LIST');
		}

		return $errori;
	}

	/**
	 * @param \salvocortesiano\newsletter\core\manager $manager
	 * @param \phpbb\user $user
	 * @param array $dati
	 * @return string
	 */
	protected function anteprima($manager, $user, array $dati)
	{
		$corpo = $manager->preview($this->riga($manager, $dati), array(
			'user_id'		=> (int) $user->data['user_id'],
			'username'		=> (string) $user->data['username'],
			'user_email'	=> (string) $user->data['user_email'],
			'user_lang'		=> (string) $user->data['user_lang'],
		));

		return ((int) $dati['format'] === manager::FORMAT_TEXT)
			? nl2br(htmlspecialchars($corpo, ENT_COMPAT, 'UTF-8'))
			: $corpo;
	}

	/**
	 * Riga di campagna, nella forma che il resto dell'estensione si aspetta
	 *
	 * @param \salvocortesiano\newsletter\core\manager $manager
	 * @param array $dati
	 * @return array
	 */
	protected function riga($manager, array $dati)
	{
		$corpo = $dati['body'];

		if ((int) $dati['format'] === manager::FORMAT_BBCODE)
		{
			$scarta = array();
			$corpo = $manager->bbcode()->to_storage($corpo, $scarta);
		}

		return array(
			'campaign_id'			=> 0,
			'campaign_subject'		=> $dati['subject'],
			'campaign_body'			=> $corpo,
			'campaign_css'			=> '',
			'campaign_format'		=> (int) $dati['format'],
			'campaign_list_id'		=> (int) $dati['list'],
			'campaign_banner'		=> 1,
			'campaign_public'		=> 0,
			'campaign_topics'		=> '',
			'campaign_priority'		=> 3,
			'campaign_importance'	=> 'normal',
			'campaign_sensitivity'	=> '',
			'campaign_from_name'	=> '',
			'campaign_from_email'	=> '',
			'campaign_reply_to'		=> '',
		);
	}

	/**
	 * Registra la richiesta, e la avvia solo se l'approvazione non serve
	 *
	 * @param \salvocortesiano\newsletter\core\manager $manager
	 * @param \salvocortesiano\newsletter\core\access  $access
	 * @param \phpbb\user $user
	 * @param array $dati
	 * @param \phpbb\language\language $language
	 * @param \phpbb\config\config $config
	 */
	protected function salva($manager, $access, $user, array $dati, $language, $config)
	{
		$riga = $this->riga($manager, $dati);

		unset($riga['campaign_id']);

		// Le colonne aggiunte dalle migrazioni successive esistono solo se
		// quelle migrazioni sono passate: scriverle prima farebbe fallire
		// l'inserimento con un errore SQL
		if (!$manager->archive_available())
		{
			unset($riga['campaign_public']);
		}

		if (!$manager->lists_available())
		{
			unset($riga['campaign_list_id']);
		}

		$riga['campaign_groups'] = '';
		// I destinatari sono gli iscritti al notiziario, e nessun altro: chi
		// scrive da qui non sceglie i gruppi del forum
		$riga['campaign_subs'] = 1;
		$riga['campaign_lang'] = '';
		$riga['campaign_batch'] = max(10, min(100, (int) $config['newsletter_batch_size']));
		$riga['campaign_interval'] = max(60, (int) $config['newsletter_interval']);
		$riga['campaign_schedule'] = 0;
		$riga['campaign_created'] = time();
		$riga['campaign_author'] = (int) $user->data['user_id'];
		$riga['campaign_author_name'] = (string) $user->data['username'];
		$riga['campaign_status'] = $access->needs_approval() ? manager::STATUS_PENDING : manager::STATUS_RUNNING;

		$campaign_id = $manager->create_campaign($riga);

		$manager->log_user_send($user, $dati['subject'], $access->needs_approval());

		if ($access->needs_approval())
		{
			trigger_error($this->messaggio($language, $language->lang('NL_SEND_QUEUED')));
		}

		$totale = $manager->fill_queue($campaign_id, $manager->get_campaign($campaign_id));

		if ($totale === 0)
		{
			$manager->set_status($campaign_id, manager::STATUS_CANCELLED);

			trigger_error($this->messaggio($language, $language->lang('NL_ERROR_NO_RECIPIENTS')), E_USER_WARNING);
		}

		trigger_error($this->messaggio($language, $language->lang('NL_SEND_STARTED', $totale)));
	}
}
