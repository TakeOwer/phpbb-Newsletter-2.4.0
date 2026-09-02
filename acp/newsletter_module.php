<?php
/**
 *
 * Newsletter. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 salvocortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\newsletter\acp;

use salvocortesiano\newsletter\core\manager;

class newsletter_module
{
	/** @var string URL delle azioni del modulo */
	public $u_action;

	/** @var string Template da usare */
	public $tpl_name;

	/** @var string Titolo della pagina */
	public $page_title;

	/** @var \Symfony\Component\DependencyInjection\ContainerInterface */
	protected $container;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\config\db_text */
	protected $config_text;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\user */
	protected $user;

	/** @var \salvocortesiano\newsletter\core\manager */
	protected $manager;

	/** @var \salvocortesiano\newsletter\core\html */
	protected $html;

	/** @var \salvocortesiano\newsletter\core\banner */
	protected $banner;

	/** @var string */
	protected $php_ext;

	/**
	 * Punto di ingresso del modulo
	 *
	 * @param int    $id
	 * @param string $mode
	 */
	public function main($id, $mode)
	{
		global $phpbb_container;

		$this->container = $phpbb_container;
		$this->config = $phpbb_container->get('config');
		$this->config_text = $phpbb_container->get('config_text');
		$this->request = $phpbb_container->get('request');
		$this->template = $phpbb_container->get('template');
		$this->language = $phpbb_container->get('language');
		$this->user = $phpbb_container->get('user');
		$this->manager = $phpbb_container->get('salvocortesiano.newsletter.manager');
		$this->html = $phpbb_container->get('salvocortesiano.newsletter.html');
		$this->banner = $phpbb_container->get('salvocortesiano.newsletter.banner');
		$this->php_ext = $phpbb_container->getParameter('core.php_ext');

		$this->language->add_lang('newsletter', 'salvocortesiano/newsletter');

		$this->page_title = 'ACP_NEWSLETTER';

		switch ($mode)
		{
			case 'lists':
				$this->page_title = 'ACP_NEWSLETTER_LISTS';
				$this->lists();
			break;

			case 'subs':
				$this->page_title = 'ACP_NEWSLETTER_SUBS';
				$this->subs();
			break;

			case 'logs':
				$this->page_title = 'ACP_NEWSLETTER_LOGS';
				$this->logs();
			break;

			case 'settings':
				$this->tpl_name = 'acp_newsletter_settings';
				$this->page_title = 'ACP_NEWSLETTER_SETTINGS';
				$this->settings();
			break;

			case 'compose':
			default:
				$this->page_title = 'ACP_NEWSLETTER_COMPOSE';
				$this->compose();
			break;
		}
	}

	/* =====================================================================
	 * Composizione
	 * ================================================================== */

	/**
	 * Modulo di composizione, conferma e avvio dell'invio
	 */
	protected function compose()
	{
		$this->tpl_name = 'acp_newsletter_compose';

		// L'esplorazione della cartella immagini risponde in JSON e chiude qui:
		// deve avvenire prima che il pannello cominci a produrre la pagina
		if ($this->request->variable('action', '') === 'browse')
		{
			$this->browse_images();
		}

		$form_key = 'newsletter_compose';
		add_form_key($form_key);

		$action = $this->request->variable('action', '');
		$campaign_id = $this->request->variable('campaign_id', 0);

		// Le azioni sul banner non compongono il messaggio: vanno distinte,
		// altrimenti caricare una immagine con l'oggetto ancora vuoto farebbe
		// comparire gli errori di un invio che nessuno ha chiesto
		$azione_banner = $this->request->is_set_post('submit_banner')
			|| $this->request->is_set_post('submit_banner_delete')
			|| $this->request->is_set_post('submit_banner_pick');

		$azione_predefiniti = $this->request->is_set_post('submit_defaults');

		$azione_messaggio = $this->request->is_set_post('submit_prepare')
			|| $this->request->is_set_post('submit_draft')
			|| $this->request->is_set_post('submit_preview')
			|| $this->request->is_set_post('submit_test');

		$inviato = $azione_banner || $azione_predefiniti || $azione_messaggio || $this->request->is_set_post('submit_confirm');

		if ($inviato && !check_form_key($form_key))
		{
			trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		// Conferma dell'invio: il messaggio e gia stato salvato come bozza nel
		// passaggio precedente, qui resta soltanto da riempire la coda
		if ($this->request->is_set_post('submit_confirm'))
		{
			$this->start_campaign($campaign_id);
			return;
		}

		$dati = $inviato ? $this->read_form() : $this->load_draft($campaign_id, $action);

		// Il formato scelto viene ricordato a ogni invio del modulo: chi manda
		// newsletter in HTML le manda quasi sempre in HTML, e ritrovare il
		// modulo su "testo semplice" porta prima o poi a spedire la marcatura
		// grezza per distrazione
		if ($inviato)
		{
			$this->config->set('newsletter_default_format', (int) $dati['format']);
		}

		$errori = $azione_messaggio ? $this->validate($dati) : array();
		$avvisi = array();
		$anteprima = '';

		if ($azione_banner)
		{
			$this->handle_banner($errori, $avvisi);
		}

		if ($azione_predefiniti)
		{
			$this->save_defaults($dati);
			$avvisi[] = $this->language->lang('NL_DEFAULTS_SAVED');
		}

		// Anteprima: il messaggio viene mostrato come lo ricevera il
		// destinatario, con i segnaposto risolti sui dati dell'amministratore,
		// cosi si vede subito se un {USERNAME} scritto male e rimasto tale
		if ($this->request->is_set_post('submit_preview') && empty($errori))
		{
			$anteprima = $this->manager->preview($this->to_campaign_row($dati), $this->self_recipient());

			$anteprima = ((int) $dati['format'] === manager::FORMAT_TEXT)
				? nl2br(htmlspecialchars($anteprima, ENT_COMPAT, 'UTF-8'))
				: $this->html->sanitize($anteprima);
		}

		if ($this->request->is_set_post('submit_test') && empty($errori))
		{
			$indirizzo = trim($this->request->variable('nl_test_email', '', true));

			if ($indirizzo === '' || !filter_var($indirizzo, FILTER_VALIDATE_EMAIL))
			{
				$errori[] = $this->language->lang('NL_ERROR_TEST_EMAIL');
			}
			else
			{
				$this->config->set('newsletter_test_email', $indirizzo);

				$errore = '';

				if ($this->manager->send_test($indirizzo, $this->to_campaign_row($dati), $errore))
				{
					trigger_error($this->language->lang('NL_TEST_SENT', $indirizzo) . adm_back_link($this->u_action));
				}

				$errori[] = $this->language->lang('NL_TEST_FAILED', $this->error_text($errore));

				// La consegna e affidata alle funzioni di phpBB: senza questa nota
				// l'errore sembra provenire dall'estensione, e si finisce a cercare
				// il guasto nel posto sbagliato
				$errori[] = $this->language->lang('NL_TEST_FAILED_HINT');
			}
		}

		if ($this->request->is_set_post('submit_draft') && empty($errori))
		{
			$campaign_id = $this->save_campaign($dati, $campaign_id);

			$this->manager->log_admin('LOG_NEWSLETTER_DRAFT', array($dati['subject']));

			trigger_error($this->language->lang('NL_DRAFT_SAVED') . adm_back_link($this->u_action . '&amp;action=edit&amp;campaign_id=' . $campaign_id));
		}

		if ($this->request->is_set_post('submit_prepare') && empty($errori))
		{
			$campaign_id = $this->save_campaign($dati, $campaign_id);

			$this->confirm_step($campaign_id, $dati);
			return;
		}

		$this->assign_compose_vars($dati, $campaign_id, $errori, $anteprima, $avvisi);
	}

	/**
	 * Caricamento o rimozione dell'immagine di intestazione
	 *
	 * @param array $errori
	 * @param array $avvisi
	 */
	protected function handle_banner(array &$errori, array &$avvisi)
	{
		if ($this->request->is_set_post('submit_banner_pick'))
		{
			$scelta = $this->request->variable('nl_banner_pick', '');
			$errore = '';

			if ($scelta === '')
			{
				$errori[] = $this->language->lang('NL_BANNER_PICK_NONE');
			}
			else if ($this->banner->adopt($scelta, $errore))
			{
				$this->manager->log_admin('LOG_NEWSLETTER_BANNER_UPLOADED', array($this->banner->get_filename()));
				$avvisi[] = $this->language->lang('NL_BANNER_PICKED');
			}
			else
			{
				$errori[] = ($errore !== '') ? $errore : $this->language->lang('NL_BANNER_UPLOAD_FAILED');
			}

			return;
		}

		if ($this->request->is_set_post('submit_banner_delete'))
		{
			if ($this->banner->remove())
			{
				$this->manager->log_admin('LOG_NEWSLETTER_BANNER_DELETED');
				$avvisi[] = $this->language->lang('NL_BANNER_DELETED');
			}

			return;
		}

		$errore = '';

		if ($this->banner->upload('nl_banner_file', $errore))
		{
			$this->manager->log_admin('LOG_NEWSLETTER_BANNER_UPLOADED', array($this->banner->get_filename()));
			$avvisi[] = $this->language->lang('NL_BANNER_UPLOADED');

			return;
		}

		$errori[] = ($errore !== '') ? $errore : $this->language->lang('NL_BANNER_UPLOAD_FAILED');
	}

	/**
	 * Elenco di una cartella dentro images/, in JSON.
	 *
	 * Sta dentro il modulo del pannello e non su una rotta pubblica: cosi il
	 * controllo di accesso e quello dell'amministrazione, gia fatto da phpBB
	 * prima di arrivare qui, e non serve rifarlo a mano. Il codice di controllo
	 * nel collegamento impedisce che la richiesta venga innescata da un'altra
	 * pagina all'insaputa di chi e collegato.
	 */
	protected function browse_images()
	{
		$risposta = new \phpbb\json_response();

		if (!check_link_hash($this->request->variable('hash', ''), 'nl_acp'))
		{
			$risposta->send(array('error' => $this->language->lang('FORM_INVALID')));
		}

		$elenco = $this->banner->browse($this->request->variable('dir', ''));

		if ($elenco === false)
		{
			$risposta->send(array('error' => $this->language->lang('NL_BANNER_PICK_NOT_FOUND')));
		}

		// send() scrive la risposta e chiude l'esecuzione da se
		$risposta->send($elenco);
	}

	/**
	 * Variabili dell'immagine di intestazione per il modulo
	 *
	 * @param array $dati
	 * @return array
	 */
	protected function banner_vars(array $dati = array())
	{
		list($larghezza, $altezza) = $this->banner->dimensions();

		return array(
			'S_NL_BANNER'			=> $this->banner->exists(),
			'S_NL_BANNER_ON'		=> !isset($dati['banner']) || !empty($dati['banner']),
			'NL_BANNER_URL'			=> $this->banner->preview_url(),
			// Indirizzo pubblico, quello che finisce davvero dentro il messaggio:
			// vederlo scritto per esteso e l'unico modo per accorgersi se il
			// dominio del forum e configurato in modo che l'immagine non si apra
			'NL_BANNER_PUBLIC_URL'	=> htmlspecialchars($this->banner->url(), ENT_COMPAT, 'UTF-8'),
			'NL_BANNER_FILE'		=> htmlspecialchars($this->banner->get_filename(), ENT_COMPAT, 'UTF-8'),
			'NL_BANNER_WIDTH'		=> $larghezza,
			'NL_BANNER_HEIGHT'		=> $altezza,
			'NL_BANNER_SIZE'		=> $this->banner->filesize(),
			'NL_BANNER_MAX'			=> $this->banner->max_size_kb(),
			'NL_BANNER_LINK'		=> htmlspecialchars($this->banner->link(), ENT_COMPAT, 'UTF-8'),
			'NL_BANNER_FOLDER'		=> \salvocortesiano\newsletter\core\banner::FOLDER,
			'NL_BANNER_MIN_H'		=> $this->banner->min_height(),
			'NL_BANNER_MAX_H'		=> $this->banner->max_height(),
			'NL_BANNER_MIN_W'		=> $this->banner->min_width(),
			'NL_BANNER_MAX_W'		=> $this->banner->max_width(),
			'U_NL_BROWSE'			=> $this->u_action . '&amp;action=browse&amp;hash=' . generate_link_hash('nl_acp'),
		);
	}

	/**
	 * Conserva le scelte correnti come punto di partenza dei prossimi messaggi.
	 *
	 * Non tocca oggetto e corpo: quelli cambiano a ogni newsletter, e
	 * ritrovarsi il testo della volta scorsa gia scritto nel modulo porta a
	 * spedirlo per sbaglio una seconda volta. Si conserva tutto il contorno,
	 * che invece resta quasi sempre uguale.
	 *
	 * @param array $dati
	 */
	protected function save_defaults(array $dati)
	{
		$this->config->set('newsletter_default_format', (int) $dati['format']);
		$this->config->set('newsletter_default_groups', implode(',', $dati['groups']));
		$this->config->set('newsletter_default_subs', (int) $dati['subs']);
		$this->config->set('newsletter_default_lang', (string) $dati['lang']);
		$this->config->set('newsletter_default_priority', (int) $dati['priority']);
		$this->config->set('newsletter_default_importance', (string) $dati['importance']);
		$this->config->set('newsletter_default_sensitivity', (string) $dati['sensitivity']);
		$this->config->set('newsletter_default_banner', (int) $dati['banner']);
		$this->config->set('newsletter_archive_default', (int) $dati['public']);
		$this->config->set('newsletter_from_name', (string) $dati['from_name']);
		$this->config->set('newsletter_from_email', (string) $dati['from_email']);
		$this->config->set('newsletter_reply_to', (string) $dati['reply_to']);
		$this->config->set('newsletter_batch_size', (int) $dati['batch']);
		$this->config->set('newsletter_interval', (int) $dati['interval']);

		$this->config_text->set('newsletter_default_css', (string) $dati['css']);

		$this->manager->log_admin('LOG_NEWSLETTER_DEFAULTS');
	}

	/**
	 * Valore predefinito di una impostazione della composizione.
	 *
	 * Il controllo con isset serve per le installazioni aggiornate sostituendo
	 * i file senza riabilitare l'estensione: la migrazione non e passata, la
	 * voce non esiste, e senza ripiego il modulo si aprirebbe con priorita zero
	 * e altre stranezze del genere.
	 *
	 * @param string $chiave
	 * @param mixed  $predefinito
	 * @return mixed
	 */
	protected function default_for($chiave, $predefinito)
	{
		return isset($this->config[$chiave]) && (string) $this->config[$chiave] !== ''
			? $this->config[$chiave]
			: $predefinito;
	}

	/**
	 * Legge i campi del modulo.
	 *
	 * L'oggetto e il corpo tornano da request->variable con i caratteri
	 * speciali gia convertiti in entita HTML: e la difesa che phpBB applica a
	 * ogni ingresso. Per un messaggio in HTML pero questo significherebbe
	 * spedire &lt;p&gt; al posto di un paragrafo, percio la conversione viene
	 * annullata subito e il testo conservato come e stato scritto. Da quel
	 * momento la responsabilita di ricodificarlo prima di mostrarlo in una
	 * pagina passa a chi lo mostra: qui lo fa assign_compose_vars().
	 *
	 * @return array
	 */
	protected function read_form()
	{
		return array(
			'subject'		=> $this->decode($this->request->variable('nl_subject', '', true)),
			'body'			=> $this->decode($this->request->variable('nl_body', '', true)),
			'css'			=> $this->decode($this->request->variable('nl_css', '', true)),
			'format'		=> max(0, min(2, $this->request->variable('nl_format', 0))),
			'groups'		=> array_filter(array_map('intval', $this->request->variable('nl_groups', array(0)))),
			'subs'			=> $this->request->variable('nl_subs', 0) ? 1 : 0,
			'list'			=> $this->request->variable('nl_list', 0),
			'banner'		=> $this->request->variable('nl_banner', 0) ? 1 : 0,
			'public'		=> $this->request->variable('nl_public', 0) ? 1 : 0,
			'lang'			=> $this->request->variable('nl_lang', ''),
			'topics'		=> $this->manager->normalize_topic_ids($this->request->variable('nl_topics', '')),
			'priority'		=> max(1, min(5, $this->request->variable('nl_priority', 3))),
			'importance'	=> $this->request->variable('nl_importance', 'normal'),
			'sensitivity'	=> $this->request->variable('nl_sensitivity', ''),
			'from_name'		=> $this->decode($this->request->variable('nl_from_name', '', true)),
			'from_email'	=> $this->request->variable('nl_from_email', ''),
			'reply_to'		=> $this->request->variable('nl_reply_to', ''),
			'batch'			=> $this->read_batch('nl_batch'),
			'interval'		=> $this->read_interval('nl_interval'),
			'schedule'		=> $this->read_schedule(),
		);
	}

	/**
	 * Numero di email per lotto, dalla tendina o dal campo libero.
	 *
	 * La tendina copre i valori che ricorrono nei limiti dei fornitori di
	 * hosting; il campo numerico resta per i casi che non ci rientrano, perche
	 * un limite reale raramente e un numero tondo. Quando la tendina non viene
	 * inviata affatto - un browser che non esegue lo script, una richiesta
	 * costruita a mano - vale il campo numerico, e il modulo continua a
	 * funzionare come prima.
	 *
	 * @param string $nome Prefisso dei campi del modulo
	 * @return int
	 */
	protected function read_batch($nome)
	{
		$scelta = $this->request->variable($nome . '_preset', 0);
		$valore = ($scelta > 0) ? $scelta : $this->request->variable($nome, 25);

		return max(10, min(100, (int) $valore));
	}

	/**
	 * Intervallo fra i lotti, dalla tendina o dal campo orario
	 *
	 * @param string $nome
	 * @return int
	 */
	protected function read_interval($nome)
	{
		$scelta = $this->request->variable($nome . '_preset', 0);

		$secondi = ($scelta > 0)
			? $scelta
			: manager::time_to_seconds($this->request->variable($nome, '00:10:00'));

		return max(60, min(86400, (int) $secondi));
	}

	/**
	 * Valori proposti nella tendina dei lotti
	 *
	 * @return array
	 */
	protected function batch_presets()
	{
		return array(10, 20, 25, 30, 40, 50, 60, 70, 80, 90, 100);
	}

	/**
	 * Valori proposti nella tendina degli intervalli, in secondi
	 *
	 * @return array
	 */
	protected function interval_presets()
	{
		return array(60, 300, 600, 900, 1800, 3600, 7200, 21600, 43200, 86400);
	}

	/**
	 * Riempie una tendina di valori proposti.
	 *
	 * L'ultima voce e sempre "personalizzato": viene scelta da sola quando il
	 * valore corrente non coincide con nessuna proposta, cosi riaprendo una
	 * bozza con un valore fuori elenco non si perde quel valore.
	 *
	 * @param string   $blocco    Nome del blocco del template
	 * @param array    $valori    Valori proposti
	 * @param int      $corrente  Valore in uso
	 * @param callable $etichetta Come scrivere il valore all'utente
	 * @return bool Vero se il valore corrente non e fra quelli proposti
	 */
	protected function assign_presets($blocco, array $valori, $corrente, $etichetta)
	{
		$corrente = (int) $corrente;
		$fuori_elenco = !in_array($corrente, $valori, true);

		foreach ($valori as $valore)
		{
			$this->template->assign_block_vars($blocco, array(
				'VALUE'			=> $valore,
				'NAME'			=> call_user_func($etichetta, $valore),
				'S_SELECTED'	=> (!$fuori_elenco && $corrente === $valore),
			));
		}

		$this->template->assign_block_vars($blocco, array(
			'VALUE'			=> 0,
			'NAME'			=> $this->language->lang('NL_PRESET_CUSTOM'),
			'S_SELECTED'	=> $fuori_elenco,
		));

		return $fuori_elenco;
	}

	/**
	 * Annulla la codifica applicata da request->variable
	 *
	 * @param string $valore
	 * @return string
	 */
	protected function decode($valore)
	{
		return html_entity_decode((string) $valore, ENT_COMPAT, 'UTF-8');
	}

	/**
	 * Data di programmazione, interpretata nel fuso dell'amministratore
	 *
	 * @return int
	 */
	protected function read_schedule()
	{
		$valore = trim($this->request->variable('nl_schedule', ''));

		if ($valore === '')
		{
			return 0;
		}

		try
		{
			// create_datetime applica il fuso orario impostato nel profilo:
			// scrivendo "09:00" l'amministratore intende le nove del suo
			// orologio, non quelle del server
			$data = $this->user->create_datetime(str_replace('T', ' ', $valore));

			return (int) $data->getTimestamp();
		}
		catch (\Exception $e)
		{
			return 0;
		}
	}

	/**
	 * Valori iniziali del modulo: quelli di una bozza da riprendere, oppure i
	 * predefiniti delle impostazioni
	 *
	 * @param int    $campaign_id
	 * @param string $action
	 * @return array
	 */
	protected function load_draft($campaign_id, $action)
	{
		$dati = array(
			'subject'		=> '',
			'body'			=> '',
			'css'			=> (string) $this->config_text->get('newsletter_default_css'),
			'format'		=> (int) $this->default_for('newsletter_default_format', 0),
			'groups'		=> $this->manager->split_ids((string) $this->default_for('newsletter_default_groups', '')),
			'subs'			=> (int) $this->default_for('newsletter_default_subs', 0),
			'list'			=> $this->manager->default_list_id(),
			'banner'		=> isset($this->config['newsletter_default_banner']) ? (int) $this->config['newsletter_default_banner'] : 1,
			'public'		=> isset($this->config['newsletter_archive_default']) ? (int) $this->config['newsletter_archive_default'] : 1,
			'lang'			=> (string) $this->default_for('newsletter_default_lang', ''),
			'topics'		=> '',
			'priority'		=> (int) $this->default_for('newsletter_default_priority', 3),
			'importance'	=> (string) $this->default_for('newsletter_default_importance', 'normal'),
			'sensitivity'	=> (string) $this->default_for('newsletter_default_sensitivity', ''),
			'from_name'		=> (string) $this->config['newsletter_from_name'],
			'from_email'	=> (string) $this->config['newsletter_from_email'],
			'reply_to'		=> (string) $this->config['newsletter_reply_to'],
			'batch'			=> max(10, min(100, (int) $this->default_for('newsletter_batch_size', 25))),
			'interval'		=> max(60, (int) $this->default_for('newsletter_interval', 600)),
			'schedule'		=> 0,
		);

		if ($action !== 'edit' || !$campaign_id)
		{
			return $dati;
		}

		$campagna = $this->manager->get_campaign($campaign_id);

		if (!$campagna)
		{
			return $dati;
		}

		return array(
			'subject'		=> (string) $campagna['campaign_subject'],
			// Il BBCode e conservato nella forma interna di phpBB, che non e
			// quella che l'amministratore ha scritto: va riportato indietro
			'body'			=> ((int) $campagna['campaign_format'] === manager::FORMAT_BBCODE)
				? $this->manager->bbcode()->to_edit((string) $campagna['campaign_body'])
				: (string) $campagna['campaign_body'],
			'css'			=> (string) $campagna['campaign_css'],
			'format'		=> (int) $campagna['campaign_format'],
			'groups'		=> $this->manager->split_ids((string) $campagna['campaign_groups']),
			'subs'			=> (int) $campagna['campaign_subs'],
			'list'			=> isset($campagna['campaign_list_id']) ? (int) $campagna['campaign_list_id'] : $this->manager->default_list_id(),
			'banner'		=> isset($campagna['campaign_banner']) ? (int) $campagna['campaign_banner'] : 1,
			'public'		=> isset($campagna['campaign_public']) ? (int) $campagna['campaign_public'] : 0,
			'lang'			=> (string) $campagna['campaign_lang'],
			'topics'		=> (string) $campagna['campaign_topics'],
			'priority'		=> (int) $campagna['campaign_priority'],
			'importance'	=> (string) $campagna['campaign_importance'],
			'sensitivity'	=> (string) $campagna['campaign_sensitivity'],
			'from_name'		=> (string) $campagna['campaign_from_name'],
			'from_email'	=> (string) $campagna['campaign_from_email'],
			'reply_to'		=> (string) $campagna['campaign_reply_to'],
			'batch'			=> (int) $campagna['campaign_batch'],
			'interval'		=> (int) $campagna['campaign_interval'],
			'schedule'		=> (int) $campagna['campaign_schedule'],
		);
	}

	/**
	 * @param array $dati
	 * @return array Messaggi di errore
	 */
	protected function validate(array $dati)
	{
		$errori = array();

		if (trim($dati['subject']) === '')
		{
			$errori[] = $this->language->lang('NL_ERROR_NO_SUBJECT');
		}

		if (trim($dati['body']) === '')
		{
			$errori[] = $this->language->lang('NL_ERROR_NO_BODY');
		}
		else if ((int) $dati['format'] === manager::FORMAT_BBCODE)
		{
			// Il motore segnala i tag non chiusi e quelli usati male: meglio
			// dirlo adesso che spedire un messaggio pieno di parentesi quadre
			$avvisi = array();
			$this->manager->bbcode()->to_storage($dati['body'], $avvisi);

			foreach ($avvisi as $avviso)
			{
				$errori[] = $avviso;
			}
		}

		if (empty($dati['groups']) && empty($dati['subs']))
		{
			$errori[] = $this->language->lang('NL_ERROR_NO_GROUPS');
		}

		if ($this->manager->lists_available() && !$this->manager->get_list((int) $dati['list']))
		{
			$errori[] = $this->language->lang('NL_ERROR_NO_LIST');
		}

		if ($dati['from_email'] !== '' && !filter_var($dati['from_email'], FILTER_VALIDATE_EMAIL))
		{
			$errori[] = $this->language->lang('NL_ERROR_FROM_EMAIL');
		}

		if ($dati['reply_to'] !== '' && !filter_var($dati['reply_to'], FILTER_VALIDATE_EMAIL))
		{
			$errori[] = $this->language->lang('NL_ERROR_REPLY_TO');
		}

		return $errori;
	}

	/**
	 * Crea o aggiorna la bozza
	 *
	 * @param array $dati
	 * @param int   $campaign_id
	 * @return int
	 */
	protected function save_campaign(array $dati, $campaign_id)
	{
		$corpo = $dati['body'];

		// Il BBCode passa dal motore del forum prima di essere conservato: e la
		// stessa forma usata dai messaggi, quindi tutto cio che phpBB sa fare
		// con un testo memorizzato vale anche qui
		if ((int) $dati['format'] === manager::FORMAT_BBCODE)
		{
			$avvisi_bbcode = array();
			$corpo = $this->manager->bbcode()->to_storage($corpo, $avvisi_bbcode);
		}

		$riga = array(
			'campaign_subject'		=> $dati['subject'],
			'campaign_body'			=> $corpo,
			'campaign_css'			=> $dati['css'],
			'campaign_format'		=> (int) $dati['format'],
			'campaign_groups'		=> implode(',', $dati['groups']),
			'campaign_subs'			=> (int) $dati['subs'],
			'campaign_banner'		=> (int) $dati['banner'],
			'campaign_lang'			=> $dati['lang'],
			'campaign_topics'		=> $dati['topics'],
			'campaign_priority'		=> (int) $dati['priority'],
			'campaign_importance'	=> $dati['importance'],
			'campaign_sensitivity'	=> $dati['sensitivity'],
			'campaign_from_name'	=> $dati['from_name'],
			'campaign_from_email'	=> $dati['from_email'],
			'campaign_reply_to'		=> $dati['reply_to'],
			'campaign_batch'		=> (int) $dati['batch'],
			'campaign_interval'		=> (int) $dati['interval'],
			'campaign_schedule'		=> (int) $dati['schedule'],
		);

		// La colonna dell'archivio esiste solo dopo la migrazione: scriverla
		// prima farebbe fallire il salvataggio con un errore SQL
		if ($this->manager->archive_available())
		{
			$riga['campaign_public'] = (int) $dati['public'];
		}

		if ($this->manager->lists_available())
		{
			$riga['campaign_list_id'] = (int) $dati['list'];
		}

		$esistente = $campaign_id ? $this->manager->get_campaign($campaign_id) : false;

		// Solo una bozza puo essere sovrascritta. Modificare una campagna gia
		// avviata significherebbe spedire due messaggi diversi sotto lo stesso
		// registro, e chi ha ricevuto il primo non comparirebbe da nessuna
		// parte come destinatario di una versione superata
		if ($esistente && (int) $esistente['campaign_status'] === manager::STATUS_DRAFT)
		{
			$this->manager->update_campaign($campaign_id, $riga);

			return (int) $campaign_id;
		}

		$riga['campaign_status'] = manager::STATUS_DRAFT;
		$riga['campaign_created'] = time();
		$riga['campaign_author'] = (int) $this->user->data['user_id'];
		$riga['campaign_author_name'] = (string) $this->user->data['username'];

		return $this->manager->create_campaign($riga);
	}

	/**
	 * Passaggio di conferma prima dell'invio.
	 *
	 * E qui che l'amministratore vede quanti messaggi partiranno davvero e in
	 * quanto tempo. La stima non e un ornamento: un invio a 4.000 iscritti con
	 * lotti da 25 ogni dieci minuti dura piu di un giorno, e chi lo avvia
	 * dovrebbe saperlo prima, non accorgersene il giorno dopo.
	 *
	 * @param int   $campaign_id
	 * @param array $dati
	 */
	protected function confirm_step($campaign_id, array $dati)
	{
		$this->tpl_name = 'acp_newsletter_confirm';
		$this->page_title = 'ACP_NEWSLETTER_COMPOSE';

		add_form_key('newsletter_compose');

		$totale = $this->manager->count_recipients($dati['groups'], !empty($dati['subs']), $dati['lang'], (int) $dati['list']);
		$lotti = ($dati['batch'] > 0) ? (int) ceil($totale / $dati['batch']) : 0;
		$durata = ($lotti > 1) ? ($lotti - 1) * $dati['interval'] : 0;

		foreach ($this->manager->get_group_names($dati['groups']) as $nome)
		{
			$this->template->assign_block_vars('gruppi', array('NAME' => $nome));
		}

		$soglia = max(1, (int) $this->config['newsletter_warn_threshold']);
		$lingue = $this->manager->get_languages();

		$this->template->assign_vars(array(
			'S_MANY_RECIPIENTS'	=> ($totale > $soglia),
			'S_NO_RECIPIENTS'	=> ($totale === 0),
			'S_SUBS'			=> !empty($dati['subs']),
			'S_PUBLIC'			=> !empty($dati['public']),
			'NL_LIST'			=> $this->manager->list_name((int) $dati['list']),
			'S_ARCHIVE_ON'		=> ($this->manager->archive_available() && (int) $this->config['newsletter_archive_visibility'] > 0),
			'NL_THRESHOLD'		=> $soglia,
			'NL_TOTAL'			=> $totale,
			'NL_BATCH'			=> (int) $dati['batch'],
			'NL_BATCHES'		=> $lotti,
			'NL_INTERVAL'		=> manager::seconds_to_time($dati['interval']),
			'NL_DURATION'		=> $this->format_duration($durata),
			'NL_ETA'			=> $durata ? $this->user->format_date(time() + $durata) : $this->language->lang('NL_ETA_IMMEDIATE'),
			'NL_SUBJECT'		=> htmlspecialchars($dati['subject'], ENT_COMPAT, 'UTF-8'),
			'NL_FORMAT'			=> $this->format_label((int) $dati['format']),
			'NL_LANG'			=> isset($lingue[$dati['lang']]) ? $lingue[$dati['lang']] : $this->language->lang('NL_LANG_ALL'),
			'NL_SCHEDULE'		=> $dati['schedule'] ? $this->user->format_date($dati['schedule']) : $this->language->lang('NL_SCHEDULE_NOW'),
			'CAMPAIGN_ID'		=> (int) $campaign_id,
			'U_ACTION'			=> $this->u_action,
			'U_BACK'			=> $this->u_action . '&amp;action=edit&amp;campaign_id=' . (int) $campaign_id,
		));
	}

	/**
	 * Riempie la coda e fa partire il primo lotto
	 *
	 * @param int $campaign_id
	 */
	protected function start_campaign($campaign_id)
	{
		$campagna = $this->manager->get_campaign($campaign_id);

		if (!$campagna)
		{
			trigger_error($this->language->lang('NL_CAMPAIGN_NOT_FOUND') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		if ((int) $campagna['campaign_status'] !== manager::STATUS_DRAFT)
		{
			trigger_error($this->language->lang('NL_ALREADY_STARTED') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$totale = $this->manager->fill_queue($campaign_id, $campagna);

		if ($totale === 0)
		{
			trigger_error($this->language->lang('NL_ERROR_NO_RECIPIENTS') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$this->manager->update_campaign($campaign_id, array(
			'campaign_status'	=> manager::STATUS_RUNNING,
			'campaign_started'	=> 0,
			'campaign_last_run'	=> 0,
			'campaign_finished'	=> 0,
		));

		$this->manager->log_admin('LOG_NEWSLETTER_SENT', array((string) $campagna['campaign_subject'], $totale));

		$u_logs = $this->logs_url() . '&amp;action=view&amp;campaign_id=' . (int) $campaign_id;

		// Un invio programmato aspetta la sua ora: far partire subito il primo
		// lotto vanificherebbe la programmazione
		if ((int) $campagna['campaign_schedule'] > time())
		{
			trigger_error($this->language->lang('NL_SCHEDULED', $totale, $this->user->format_date((int) $campagna['campaign_schedule'])) . adm_back_link($u_logs));
		}

		$esito = $this->manager->process($campaign_id, true);

		trigger_error($this->language->lang('NL_STARTED', $totale, (int) $esito['sent'], (int) $esito['failed'], (int) $esito['pending']) . adm_back_link($u_logs));
	}

	/**
	 * Variabili del modulo di composizione
	 *
	 * @param array  $dati
	 * @param int    $campaign_id
	 * @param array  $errori
	 * @param string $anteprima
	 */
	protected function assign_compose_vars(array $dati, $campaign_id, array $errori, $anteprima, array $avvisi = array())
	{
		foreach ($this->manager->get_groups() as $gruppo)
		{
			$this->template->assign_block_vars('gruppi', array(
				'GROUP_ID'		=> $gruppo['group_id'],
				'GROUP_NAME'	=> $gruppo['group_name'],
				'MEMBERS'		=> $gruppo['members'],
				'S_SELECTED'	=> in_array($gruppo['group_id'], $dati['groups'], true),
			));
		}

		foreach (array(1, 2, 3, 4, 5) as $livello)
		{
			$this->template->assign_block_vars('priorita', array(
				'VALUE'			=> $livello,
				'NAME'			=> $this->language->lang('NL_PRIORITY_' . $livello),
				'S_SELECTED'	=> ((int) $dati['priority'] === $livello),
			));
		}

		foreach (array('high', 'normal', 'low') as $valore)
		{
			$this->template->assign_block_vars('importanza', array(
				'VALUE'			=> $valore,
				'NAME'			=> $this->language->lang('NL_IMPORTANCE_' . strtoupper($valore)),
				'S_SELECTED'	=> ($dati['importance'] === $valore),
			));
		}

		foreach (array('', 'personal', 'private', 'company-confidential') as $valore)
		{
			$this->template->assign_block_vars('riservatezza', array(
				'VALUE'			=> $valore,
				'NAME'			=> $this->language->lang('NL_SENSITIVITY_' . ($valore === '' ? 'NONE' : strtoupper(str_replace('-', '_', $valore)))),
				'S_SELECTED'	=> ($dati['sensitivity'] === $valore),
			));
		}

		foreach ($this->manager->get_lists() as $notiziario)
		{
			$this->template->assign_block_vars('notiziari', array(
				'VALUE'			=> (int) $notiziario['list_id'],
				'NAME'			=> (string) $notiziario['list_name'],
				// I gruppi proposti viaggiano con l'opzione: cosi cambiando
				// notiziario la selezione dei gruppi si adegua senza ricaricare
				'GROUPS'		=> (string) $notiziario['list_groups'],
				'S_SELECTED'	=> ((int) $dati['list'] === (int) $notiziario['list_id']),
			));
		}

		$this->template->assign_block_vars('lingue', array(
			'VALUE'			=> '',
			'NAME'			=> $this->language->lang('NL_LANG_ALL'),
			'S_SELECTED'	=> ($dati['lang'] === ''),
		));

		foreach ($this->manager->get_languages() as $iso => $nome)
		{
			$this->template->assign_block_vars('lingue', array(
				'VALUE'			=> $iso,
				'NAME'			=> $nome,
				'S_SELECTED'	=> ($dati['lang'] === $iso),
			));
		}

		// Le faccine servono solo in modalita BBCode, ma vengono preparate
		// comunque: sono una interrogazione sola, tenuta in cache da phpBB, e
		// caricarle a richiesta vorrebbe dire una seconda pagina da servire
		if (!empty($this->config['newsletter_bbcode_smilies']))
		{
			global $phpbb_root_path;

			$cartella = $phpbb_root_path . $this->config['smilies_path'] . '/';

			foreach ($this->manager->get_smilies() as $faccina)
			{
				$this->template->assign_block_vars('faccine', array(
					'CODE'		=> htmlspecialchars($faccina['code'], ENT_QUOTES, 'UTF-8'),
					'EMOTION'	=> htmlspecialchars($faccina['emotion'], ENT_COMPAT, 'UTF-8'),
					'SRC'		=> $cartella . rawurlencode($faccina['url']),
				));
			}
		}

		$batch_libero = $this->assign_presets('lotti', $this->batch_presets(), $dati['batch'], array($this, 'batch_label'));
		$interval_libero = $this->assign_presets('intervalli', $this->interval_presets(), $dati['interval'], array($this, 'interval_label'));

		foreach ($errori as $errore)
		{
			$this->template->assign_block_vars('errori', array('MESSAGE' => $errore));
		}

		foreach ($avvisi as $avviso)
		{
			$this->template->assign_block_vars('avvisi', array('MESSAGE' => $avviso));
		}

		$schedule = '';

		if ($dati['schedule'] > 0)
		{
			// Il campo datetime-local vuole la forma "2026-08-23T09:00" e non
			// accetta ne fusi ne secondi
			$schedule = $this->user->format_date($dati['schedule'], 'Y-m-d\TH:i', false);
		}

		$this->template->assign_vars(array_merge($this->identity_vars(), $this->banner_vars($dati), array(
			'NL_SUBJECT'		=> htmlspecialchars($dati['subject'], ENT_COMPAT, 'UTF-8'),
			'NL_BODY'			=> htmlspecialchars($dati['body'], ENT_COMPAT, 'UTF-8'),
			'NL_CSS'			=> htmlspecialchars($dati['css'], ENT_COMPAT, 'UTF-8'),
			// Il foglio che entra in gioco lasciando vuoto il campo: e quello
			// delle impostazioni se c'e, altrimenti quello incorporato
			'NL_PREDEF_CSS'		=> htmlspecialchars(
				(trim((string) $this->config_text->get('newsletter_css')) !== '')
					? (string) $this->config_text->get('newsletter_css')
					: $this->html->default_css(),
				ENT_COMPAT, 'UTF-8'),
			'S_CSS_FROM_SETTINGS'	=> (trim((string) $this->config_text->get('newsletter_css')) !== ''),
			'NL_TOPICS'			=> htmlspecialchars($dati['topics'], ENT_COMPAT, 'UTF-8'),
			'S_FORMAT_TEXT'		=> ((int) $dati['format'] === manager::FORMAT_TEXT),
			'S_FORMAT_BBCODE'	=> ((int) $dati['format'] === manager::FORMAT_BBCODE),
			'S_FORMAT_HTML'		=> ((int) $dati['format'] === manager::FORMAT_HTML),
			// Banner e foglio di stile valgono per entrambi i formati ricchi
			'S_FORMAT_RICH'		=> ((int) $dati['format'] !== manager::FORMAT_TEXT),
			'S_SUBS'			=> !empty($dati['subs']),
			'S_LISTS_ON'		=> $this->manager->lists_available(),
			// Queste due servono qui, dove sta la casella "Pubblica
			// nell'archivio": erano assegnate soltanto nella pagina di
			// conferma, e nel modulo la casella risultava sempre spenta e
			// mai spuntata
			'S_ARCHIVE_ON'		=> ($this->manager->archive_available() && (int) $this->config['newsletter_archive_visibility'] > 0),
			'S_PUBLIC'			=> !empty($dati['public']),
			'NL_FROM_NAME'		=> htmlspecialchars($dati['from_name'], ENT_COMPAT, 'UTF-8'),
			'NL_FROM_EMAIL'		=> htmlspecialchars($dati['from_email'], ENT_COMPAT, 'UTF-8'),
			'NL_REPLY_TO'		=> htmlspecialchars($dati['reply_to'], ENT_COMPAT, 'UTF-8'),
			'NL_BATCH'			=> (int) $dati['batch'],
			'NL_INTERVAL'		=> manager::seconds_to_time($dati['interval']),
			'S_BATCH_CUSTOM'	=> $batch_libero,
			'S_INTERVAL_CUSTOM'	=> $interval_libero,
			'NL_SCHEDULE'		=> $schedule,
			'NL_TEST_EMAIL'		=> htmlspecialchars((string) $this->config['newsletter_test_email'], ENT_COMPAT, 'UTF-8'),
			// Gli iscritti di QUESTO notiziario, non di tutti: e il numero che
			// dice quante persone in piu raggiunge la casella accanto
			'NL_SUBSCRIBERS'	=> $this->manager->count_subscribers('', (int) $dati['list']),
			'NL_PREVIEW'		=> $anteprima,
			'S_PREVIEW'			=> ($anteprima !== ''),
			'S_HAS_ERRORS'		=> !empty($errori),
			// Il ripristino temporaneo del browser va evitato quando si sta
			// riprendendo una bozza: sovrascriverebbe i valori salvati con quelli
			// di un messaggio diverso lasciato a meta
			'S_NL_RESTORE'		=> ((int) $campaign_id === 0),
			'S_RESPECT_OPTOUT'	=> !empty($this->config['newsletter_respect_optout']),
			'S_ENABLED'			=> !empty($this->config['newsletter_enabled']),
			'CAMPAIGN_ID'		=> (int) $campaign_id,
			'U_ACTION'			=> $this->u_action,
			'U_SETTINGS'		=> $this->settings_url(),
			'U_LOGS'			=> $this->logs_url(),
		)));
	}

	/* =====================================================================
	 * Registro
	 * ================================================================== */

	/**
	 * Elenco delle campagne e dettaglio dei recapiti
	 */
	protected function logs()
	{
		$action = $this->request->variable('action', '');
		$campaign_id = $this->request->variable('campaign_id', 0);

		add_form_key('newsletter_logs');

		switch ($action)
		{
			case 'delete':
				$this->delete_log($campaign_id);
			break;

			case 'delete_all':
				$this->delete_all_logs();
			break;

			case 'pause':
			case 'resume':
			case 'cancel':
			case 'run':
			case 'requeue':
			case 'approve':
			case 'reject':
			case 'publish':
			case 'unpublish':
				$this->campaign_action($action, $campaign_id);
			break;

			case 'view':
				$this->log_detail($campaign_id);
			return;
		}

		$this->tpl_name = 'acp_newsletter_logs';

		$pagination = $this->container->get('pagination');

		$start = $this->request->variable('start', 0);
		$per_page = 25;
		$totale = $this->manager->count_campaigns();

		foreach ($this->manager->get_campaigns($start, $per_page) as $campagna)
		{
			$id = (int) $campagna['campaign_id'];
			$stats = $this->manager->get_stats($id);

			$percentuale = $stats['total'] ? (int) round((($stats['sent'] + $stats['failed']) / $stats['total']) * 100) : 0;

			$this->template->assign_block_vars('campagne', array(
				'CAMPAIGN_ID'	=> $id,
				'SUBJECT'		=> htmlspecialchars((string) $campagna['campaign_subject'], ENT_COMPAT, 'UTF-8'),
				'AUTHOR'		=> (string) $campagna['campaign_author_name'],
				'STATUS_CODE'	=> (int) $campagna['campaign_status'],
				'CREATED'		=> $this->user->format_date((int) $campagna['campaign_created']),
				'FORMAT'		=> $this->format_label((int) $campagna['campaign_format']),
				'STATUS'		=> $this->status_label((int) $campagna['campaign_status']),
				'TOTAL'			=> $stats['total'],
				'SENT'			=> $stats['sent'],
				'PENDING'		=> $stats['pending'],
				'FAILED'		=> $stats['failed'],
				'PERCENT'		=> $percentuale,
				'GROUPS'		=> $this->recipients_label($campagna),
				'LIST_NAME'		=> isset($campagna['campaign_list_id']) ? $this->manager->list_name((int) $campagna['campaign_list_id']) : '',
				'S_RUNNING'		=> ((int) $campagna['campaign_status'] === manager::STATUS_RUNNING),
				'S_PAUSED'		=> ((int) $campagna['campaign_status'] === manager::STATUS_PAUSED),
				'S_DRAFT'		=> ((int) $campagna['campaign_status'] === manager::STATUS_DRAFT),
				'S_PENDING'		=> ((int) $campagna['campaign_status'] === manager::STATUS_PENDING),
				'STALLED'		=> $this->stall_label($campagna),
				'U_APPROVE'		=> $this->action_url('approve', $id),
				'U_REJECT'		=> $this->action_url('reject', $id),
				'U_VIEW'		=> $this->u_action . '&amp;action=view&amp;campaign_id=' . $id,
				'S_PUBLIC'		=> !empty($campagna['campaign_public']),
				'VIEWS'			=> isset($campagna['campaign_views']) ? (int) $campagna['campaign_views'] : 0,
				'U_EDIT'		=> $this->compose_url() . '&amp;action=edit&amp;campaign_id=' . $id,
				'U_DELETE'		=> $this->u_action . '&amp;action=delete&amp;campaign_id=' . $id,
				'U_PAUSE'		=> $this->action_url('pause', $id),
				'U_RESUME'		=> $this->action_url('resume', $id),
				'U_CANCEL'		=> $this->action_url('cancel', $id),
				'U_RUN'			=> $this->action_url('run', $id),
			));
		}

		$pagination->generate_template_pagination($this->u_action, 'pagination', 'start', $totale, $per_page, $start);

		$this->template->assign_vars(array_merge($this->identity_vars(), array(
			'TOTAL_CAMPAIGNS'	=> $this->language->lang('NL_TOTAL_CAMPAIGNS', $totale),
			'NL_PENDING'		=> $this->manager->count_pending(),
			'U_ACTION'			=> $this->u_action,
			'U_DELETE_ALL'		=> $this->u_action . '&amp;action=delete_all',
			'U_COMPOSE'			=> $this->compose_url(),
		)));
	}

	/**
	 * Dettaglio di una campagna, destinatario per destinatario
	 *
	 * @param int $campaign_id
	 */
	protected function log_detail($campaign_id)
	{
		$this->tpl_name = 'acp_newsletter_log';
		$this->page_title = 'ACP_NEWSLETTER_LOGS';

		$campagna = $this->manager->get_campaign($campaign_id);

		if (!$campagna)
		{
			trigger_error($this->language->lang('NL_CAMPAIGN_NOT_FOUND') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$pagination = $this->container->get('pagination');

		$filtro = $this->request->variable('filtro', -1);
		$filtro = in_array($filtro, array(manager::QUEUE_PENDING, manager::QUEUE_SENT, manager::QUEUE_FAILED), true) ? $filtro : -1;

		$start = $this->request->variable('start', 0);
		$per_page = 50;

		$totale_filtrato = $this->manager->count_queue_rows($campaign_id, $filtro);

		foreach ($this->manager->get_queue_rows($campaign_id, $filtro, $start, $per_page) as $riga)
		{
			$stato = (int) $riga['queue_status'];

			$this->template->assign_block_vars('destinatari', array(
				'USERNAME'	=> (string) $riga['username'],
				'EMAIL'		=> htmlspecialchars((string) $riga['user_email'], ENT_COMPAT, 'UTF-8'),
				'STATUS'	=> $this->queue_status_label($stato),
				'S_SENT'	=> ($stato === manager::QUEUE_SENT),
				'S_FAILED'	=> ($stato === manager::QUEUE_FAILED),
				'S_PENDING'	=> ($stato === manager::QUEUE_PENDING),
				'ATTEMPTS'	=> (int) $riga['queue_attempts'],
				'TIME'		=> $riga['queue_time'] ? $this->user->format_date((int) $riga['queue_time']) : '',
				'ERROR'		=> $riga['queue_error'] ? htmlspecialchars($this->error_text((string) $riga['queue_error']), ENT_COMPAT, 'UTF-8') : '',
			));
		}

		$base = $this->u_action . '&amp;action=view&amp;campaign_id=' . (int) $campaign_id . (($filtro >= 0) ? '&amp;filtro=' . $filtro : '');
		$pagination->generate_template_pagination($base, 'pagination', 'start', $totale_filtrato, $per_page, $start);

		$stats = $this->manager->get_stats($campaign_id);
		$stato = (int) $campagna['campaign_status'];

		$filtri = array(
			-1						=> 'NL_FILTER_ALL',
			manager::QUEUE_SENT		=> 'NL_FILTER_SENT',
			manager::QUEUE_PENDING	=> 'NL_FILTER_PENDING',
			manager::QUEUE_FAILED	=> 'NL_FILTER_FAILED',
		);

		foreach ($filtri as $valore => $chiave)
		{
			$this->template->assign_block_vars('filtri', array(
				'NAME'			=> $this->language->lang($chiave),
				'S_SELECTED'	=> ($filtro === $valore),
				'U_FILTER'		=> $this->u_action . '&amp;action=view&amp;campaign_id=' . (int) $campaign_id . (($valore >= 0) ? '&amp;filtro=' . $valore : ''),
			));
		}

		$this->template->assign_vars(array_merge($this->identity_vars(), array(
			'NL_SUBJECT'	=> htmlspecialchars((string) $campagna['campaign_subject'], ENT_COMPAT, 'UTF-8'),
			'NL_STATUS'		=> $this->status_label($stato),
			'NL_FORMAT'		=> $this->format_label((int) $campagna['campaign_format']),
			'NL_PRIORITY'	=> $this->language->lang('NL_PRIORITY_' . (int) $campagna['campaign_priority']),
			'NL_IMPORTANCE'	=> $this->language->lang('NL_IMPORTANCE_' . strtoupper((string) $campagna['campaign_importance'])),
			'NL_GROUPS'		=> $this->recipients_label($campagna),
			'NL_LIST'		=> isset($campagna['campaign_list_id']) ? $this->manager->list_name((int) $campagna['campaign_list_id']) : '',
			'NL_AUTHOR'		=> (string) $campagna['campaign_author_name'],
			'NL_CREATED'	=> $this->user->format_date((int) $campagna['campaign_created']),
			'NL_STARTED'	=> $campagna['campaign_started'] ? $this->user->format_date((int) $campagna['campaign_started']) : '',
			'NL_FINISHED'	=> $campagna['campaign_finished'] ? $this->user->format_date((int) $campagna['campaign_finished']) : '',
			'NL_BATCH'		=> (int) $campagna['campaign_batch'],
			'NL_INTERVAL'	=> manager::seconds_to_time((int) $campagna['campaign_interval']),
			'NL_NEXT_RUN'	=> ($stato === manager::STATUS_RUNNING && $campagna['campaign_last_run'])
				? $this->user->format_date((int) $campagna['campaign_last_run'] + (int) $campagna['campaign_interval'])
				: '',
			'NL_TOTAL'		=> $stats['total'],
			'NL_SENT'		=> $stats['sent'],
			'NL_PENDING'	=> $stats['pending'],
			'NL_FAILED'		=> $stats['failed'],
			'NL_PERCENT'	=> $stats['total'] ? (int) round((($stats['sent'] + $stats['failed']) / $stats['total']) * 100) : 0,
			'S_RUNNING'		=> ($stato === manager::STATUS_RUNNING),
			'S_PAUSED'		=> ($stato === manager::STATUS_PAUSED),
			'S_HAS_FAILED'	=> ($stats['failed'] > 0),
			'NL_PAUSE_REASON'	=> isset($campagna['campaign_pause_reason']) ? (string) $campagna['campaign_pause_reason'] : '',
			'NL_STALLED'	=> $this->stall_label($campagna),
			// Lo stato compare anche fra i conteggi: e li che si guarda per
			// capire come sta andando un invio, e trovarci quattro numeri senza
			// sapere se sta ancora procedendo non dice granche
			'NL_STATE_CLASS'	=> $this->state_class((int) $campagna['campaign_status']),
			'S_DONE'		=> ($stato === manager::STATUS_DONE),
			'S_ARCHIVE_ON'	=> ($this->manager->archive_available() && (int) $this->config['newsletter_archive_visibility'] > 0),
			'S_PUBLIC'		=> !empty($campagna['campaign_public']),
			'NL_VIEWS'		=> isset($campagna['campaign_views']) ? (int) $campagna['campaign_views'] : 0,
			'U_PUBLISH'		=> $this->action_url('publish', $campaign_id),
			'U_UNPUBLISH'	=> $this->action_url('unpublish', $campaign_id),
			'U_ARCHIVE_VIEW'	=> generate_board_url() . '/app.' . $this->php_ext . '/newsletter/' . (int) $campaign_id,
			'U_ACTION'		=> $this->u_action,
			'U_BACK'		=> $this->u_action,
			'U_PAUSE'		=> $this->action_url('pause', $campaign_id),
			'U_RESUME'		=> $this->action_url('resume', $campaign_id),
			'U_CANCEL'		=> $this->action_url('cancel', $campaign_id),
			'U_RUN'			=> $this->action_url('run', $campaign_id),
			'U_REQUEUE'		=> $this->action_url('requeue', $campaign_id),
			'U_DELETE'		=> $this->u_action . '&amp;action=delete&amp;campaign_id=' . (int) $campaign_id,
		)));
	}

	/**
	 * Descrizione leggibile dei destinatari di una campagna
	 *
	 * @param array $campagna
	 * @return string
	 */
	protected function recipients_label(array $campagna)
	{
		$pezzi = $this->manager->get_group_names($this->manager->split_ids((string) $campagna['campaign_groups']));

		if (!empty($campagna['campaign_subs']))
		{
			$pezzi[] = $this->language->lang('NL_SUBSCRIBERS_LABEL');
		}

		return implode(', ', $pezzi);
	}

	/**
	 * Pausa, ripresa, annullamento, lotto immediato, rimessa in coda
	 *
	 * @param string $action
	 * @param int    $campaign_id
	 */
	protected function campaign_action($action, $campaign_id)
	{
		if (!check_link_hash($this->request->variable('hash', ''), 'nl_acp'))
		{
			trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$campagna = $this->manager->get_campaign($campaign_id);

		if (!$campagna)
		{
			trigger_error($this->language->lang('NL_CAMPAIGN_NOT_FOUND') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$ritorno = $this->u_action . '&amp;action=view&amp;campaign_id=' . (int) $campaign_id;
		$oggetto = (string) $campagna['campaign_subject'];

		switch ($action)
		{
			case 'pause':
				$this->manager->set_status($campaign_id, manager::STATUS_PAUSED);
				$this->manager->log_admin('LOG_NEWSLETTER_PAUSED', array($oggetto));
				trigger_error($this->language->lang('NL_PAUSED') . adm_back_link($ritorno));
			break;

			case 'resume':
				$ripresa = array(
					'campaign_status'	=> manager::STATUS_RUNNING,
					'campaign_finished'	=> 0,
				);

				// Riprendendo si riparte da zero: la serie che aveva fatto
				// scattare la pausa apparteneva al guasto di prima, e tenerla
				// farebbe fermare di nuovo l'invio al primo intoppo
				if ($this->manager->failsafe_available())
				{
					$ripresa['campaign_fail_streak'] = 0;
					$ripresa['campaign_pause_reason'] = '';
				}

				$this->manager->update_campaign($campaign_id, $ripresa);
				$this->manager->log_admin('LOG_NEWSLETTER_RESUMED', array($oggetto));
				trigger_error($this->language->lang('NL_RESUMED') . adm_back_link($ritorno));
			break;

			case 'cancel':
				$this->manager->set_status($campaign_id, manager::STATUS_CANCELLED);
				$this->manager->log_admin('LOG_NEWSLETTER_CANCELLED', array($oggetto));
				trigger_error($this->language->lang('NL_CANCELLED') . adm_back_link($ritorno));
			break;

			case 'requeue':
				$quante = $this->manager->requeue_failed($campaign_id);
				trigger_error($this->language->lang('NL_REQUEUED', $quante) . adm_back_link($ritorno));
			break;

			case 'approve':
				$quanti = $this->manager->approve_campaign($campaign_id);

				if ($quanti === 0)
				{
					trigger_error($this->language->lang('NL_ERROR_NO_RECIPIENTS') . adm_back_link($ritorno), E_USER_WARNING);
				}

				$this->manager->log_admin('LOG_NEWSLETTER_APPROVED', array($oggetto, (string) $campagna['campaign_author_name']));
				trigger_error($this->language->lang('NL_APPROVED', $quanti) . adm_back_link($ritorno));
			break;

			case 'reject':
				$this->manager->set_status($campaign_id, manager::STATUS_CANCELLED);
				$this->manager->log_admin('LOG_NEWSLETTER_REJECTED', array($oggetto, (string) $campagna['campaign_author_name']));
				trigger_error($this->language->lang('NL_REJECTED') . adm_back_link($ritorno));
			break;

			case 'publish':
			case 'unpublish':
				if (!$this->manager->archive_available())
				{
					trigger_error($this->language->lang('NL_ARCHIVE_UNAVAILABLE') . adm_back_link($ritorno), E_USER_WARNING);
				}

				// Solo una campagna conclusa puo entrare nell'archivio: una
				// ancora in corso sarebbe leggibile da chi non l'ha ricevuta
				if ((int) $campagna['campaign_status'] !== manager::STATUS_DONE)
				{
					trigger_error($this->language->lang('NL_ARCHIVE_ONLY_DONE') . adm_back_link($ritorno), E_USER_WARNING);
				}

				$pubblica = ($action === 'publish');
				$this->manager->set_public($campaign_id, $pubblica);
				$this->manager->log_admin($pubblica ? 'LOG_NEWSLETTER_PUBLISHED' : 'LOG_NEWSLETTER_UNPUBLISHED', array($oggetto));

				trigger_error($this->language->lang($pubblica ? 'NL_PUBLISHED' : 'NL_UNPUBLISHED') . adm_back_link($ritorno));
			break;

			case 'run':
				$esito = $this->manager->process($campaign_id, true);

				if ($esito['sent'] === 0 && $esito['failed'] === 0)
				{
					trigger_error($this->language->lang($esito['reason'] ? $esito['reason'] : 'NL_NOTHING_TO_SEND') . adm_back_link($ritorno));
				}

				trigger_error($this->language->lang('NL_BATCH_DONE', (int) $esito['sent'], (int) $esito['failed'], (int) $esito['pending']) . adm_back_link($ritorno));
			break;
		}
	}

	/**
	 * @param int $campaign_id
	 */
	protected function delete_log($campaign_id)
	{
		$campagna = $this->manager->get_campaign($campaign_id);

		if (!$campagna)
		{
			trigger_error($this->language->lang('NL_CAMPAIGN_NOT_FOUND') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		if (confirm_box(true))
		{
			$this->manager->delete_campaign($campaign_id);
			$this->manager->log_admin('LOG_NEWSLETTER_LOG_DELETED', array((string) $campagna['campaign_subject']));

			trigger_error($this->language->lang('NL_LOG_DELETED') . adm_back_link($this->u_action));
		}

		$in_corso = in_array((int) $campagna['campaign_status'], array(manager::STATUS_RUNNING, manager::STATUS_PAUSED), true);

		confirm_box(false, $this->language->lang($in_corso ? 'NL_CONFIRM_DELETE_RUNNING' : 'NL_CONFIRM_DELETE_LOG'), build_hidden_fields(array(
			'action'		=> 'delete',
			'campaign_id'	=> (int) $campaign_id,
		)));
	}

	/**
	 * Svuotamento del registro
	 */
	protected function delete_all_logs()
	{
		if (confirm_box(true))
		{
			$quante = $this->manager->delete_all_campaigns(false);
			$this->manager->log_admin('LOG_NEWSLETTER_LOGS_CLEARED', array((int) $quante));

			trigger_error($this->language->lang('NL_ALL_LOGS_DELETED', $quante) . adm_back_link($this->u_action));
		}

		confirm_box(false, $this->language->lang('NL_CONFIRM_DELETE_ALL'), build_hidden_fields(array(
			'action' => 'delete_all',
		)));
	}

	/* =====================================================================
	 * Notiziari
	 * ================================================================== */

	/**
	 * Creazione, modifica, riordino ed eliminazione dei notiziari
	 */
	protected function lists()
	{
		$this->tpl_name = 'acp_newsletter_lists';

		if (!$this->manager->lists_available())
		{
			trigger_error($this->language->lang('NL_LISTS_UNAVAILABLE') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$form_key = 'newsletter_lists';
		add_form_key($form_key);

		$action = $this->request->variable('action', '');
		$list_id = $this->request->variable('list_id', 0);

		if ($this->request->is_set_post('submit_list'))
		{
			if (!check_form_key($form_key))
			{
				trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$this->save_list($list_id);
		}

		switch ($action)
		{
			case 'delete':
				$this->delete_list($list_id);
			break;

			case 'up':
			case 'down':
				if (!check_link_hash($this->request->variable('hash', ''), 'nl_acp'))
				{
					trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
				}

				$this->manager->move_list($list_id, ($action === 'up') ? -1 : 1);
				trigger_error($this->language->lang('NL_LIST_MOVED') . adm_back_link($this->u_action));
			break;
		}

		$conteggi = $this->manager->count_by_list();
		$liste = $this->manager->get_lists();
		$ultimo = count($liste) - 1;

		foreach ($liste as $indice => $lista)
		{
			$id = (int) $lista['list_id'];

			$this->template->assign_block_vars('notiziari', array(
				'LIST_ID'		=> $id,
				'NAME'			=> (string) $lista['list_name'],
				'DESCRIPTION'	=> (string) $lista['list_desc'],
				'SUBSCRIBERS'	=> isset($conteggi[$id]) ? $conteggi[$id] : 0,
				'S_ENABLED'		=> !empty($lista['list_enabled']),
				'S_DEFAULT'		=> !empty($lista['list_default']),
				'S_PUBLIC'		=> !empty($lista['list_public']),
				'S_FIRST'		=> ($indice === 0),
				'S_LAST'		=> ($indice === $ultimo),
				'U_EDIT'		=> $this->u_action . '&amp;action=edit&amp;list_id=' . $id,
				'U_DELETE'		=> $this->u_action . '&amp;action=delete&amp;list_id=' . $id,
				'U_UP'			=> $this->list_action_url('up', $id),
				'U_DOWN'		=> $this->list_action_url('down', $id),
			));
		}

		// Modulo di modifica, oppure vuoto per crearne uno nuovo
		$modifica = ($action === 'edit' && $list_id) ? $this->manager->get_list($list_id) : false;

		$gruppi_scelti = $modifica ? $this->manager->split_ids((string) $modifica['list_groups']) : array();

		foreach ($this->manager->get_groups() as $gruppo)
		{
			$this->template->assign_block_vars('gruppi', array(
				'GROUP_ID'		=> $gruppo['group_id'],
				'GROUP_NAME'	=> $gruppo['group_name'],
				'MEMBERS'		=> $gruppo['members'],
				'S_SELECTED'	=> in_array($gruppo['group_id'], $gruppi_scelti, true),
			));
		}

		$this->template->assign_vars(array_merge($this->identity_vars(), array(
			'S_EDITING'		=> (bool) $modifica,
			'NL_LIST_ID'	=> $modifica ? (int) $modifica['list_id'] : 0,
			'NL_LIST_NAME'	=> $modifica ? htmlspecialchars((string) $modifica['list_name'], ENT_COMPAT, 'UTF-8') : '',
			'NL_LIST_DESC'	=> $modifica ? htmlspecialchars((string) $modifica['list_desc'], ENT_COMPAT, 'UTF-8') : '',
			'S_LIST_ENABLED'=> $modifica ? !empty($modifica['list_enabled']) : true,
			'S_LIST_PUBLIC'	=> $modifica ? !empty($modifica['list_public']) : true,
			'S_LIST_DEFAULT'=> $modifica ? !empty($modifica['list_default']) : false,
			'U_ACTION'		=> $this->u_action,
			'U_NEW'			=> $this->u_action,
			'U_SUBS'		=> $this->subs_url(),
		)));
	}

	/**
	 * Salva un notiziario, nuovo o modificato
	 *
	 * @param int $list_id
	 */
	protected function save_list($list_id)
	{
		$nome = trim($this->decode($this->request->variable('nl_list_name', '', true)));

		if ($nome === '')
		{
			trigger_error($this->language->lang('NL_LIST_NO_NAME') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$dati = array(
			'list_name'		=> $nome,
			'list_desc'		=> $this->decode($this->request->variable('nl_list_desc', '', true)),
			'list_enabled'	=> $this->request->variable('nl_list_enabled', 0) ? 1 : 0,
			'list_public'	=> $this->request->variable('nl_list_public', 0) ? 1 : 0,
			'list_groups'	=> implode(',', array_filter(array_map('intval', $this->request->variable('nl_list_groups', array(0))))),
		);

		$esistente = $list_id ? $this->manager->get_list($list_id) : false;

		if ($esistente)
		{
			// Il predefinito resta acceso comunque: e quello a cui ricadono le
			// iscrizioni di tutti gli altri, e spegnerlo bloccherebbe le nuove
			// iscrizioni senza che si capisca perche
			if (!empty($esistente['list_default']))
			{
				$dati['list_enabled'] = 1;
			}

			$this->manager->update_list($list_id, $dati);
			$this->manager->log_admin('LOG_NEWSLETTER_LIST_EDITED', array($nome));

			trigger_error($this->language->lang('NL_LIST_SAVED') . adm_back_link($this->u_action));
		}

		$dati['list_default'] = 0;
		$dati['list_order'] = count($this->manager->get_lists());

		$this->manager->create_list($dati);
		$this->manager->log_admin('LOG_NEWSLETTER_LIST_CREATED', array($nome));

		trigger_error($this->language->lang('NL_LIST_CREATED') . adm_back_link($this->u_action));
	}

	/**
	 * Eliminazione di un notiziario, con conferma che dice cosa si perde
	 *
	 * @param int $list_id
	 */
	protected function delete_list($list_id)
	{
		$lista = $this->manager->get_list($list_id);

		if (!$lista)
		{
			trigger_error($this->language->lang('NL_LIST_NOT_FOUND') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		if (!empty($lista['list_default']))
		{
			trigger_error($this->language->lang('NL_LIST_NO_DELETE_DEFAULT') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		if (confirm_box(true))
		{
			$nome = (string) $lista['list_name'];

			$this->manager->delete_list($list_id);
			$this->manager->log_admin('LOG_NEWSLETTER_LIST_DELETED', array($nome));

			trigger_error($this->language->lang('NL_LIST_DELETED', $nome) . adm_back_link($this->u_action));
		}

		$conteggi = $this->manager->count_by_list();
		$iscritti = isset($conteggi[(int) $list_id]) ? $conteggi[(int) $list_id] : 0;

		confirm_box(false, $this->language->lang('NL_LIST_CONFIRM_DELETE', $lista['list_name'], $iscritti), build_hidden_fields(array(
			'action'	=> 'delete',
			'list_id'	=> (int) $list_id,
		)));
	}

	/**
	 * @param string $action
	 * @param int    $list_id
	 * @return string
	 */
	protected function list_action_url($action, $list_id)
	{
		return $this->u_action . '&amp;action=' . $action . '&amp;list_id=' . (int) $list_id . '&amp;hash=' . generate_link_hash('nl_acp');
	}

	/* =====================================================================
	 * Iscritti
	 * ================================================================== */

	/**
	 * Elenco degli iscritti alla newsletter.
	 *
	 * Mostra chi si e iscritto, con quale indirizzo e quando. La colonna
	 * dell'indirizzo distingue quello con cui l'iscrizione e stata fatta da
	 * quello attuale del profilo: quando differiscono, l'utente ha cambiato
	 * recapito dopo essersi iscritto, e i messaggi vanno al nuovo, non a
	 * quello registrato allora.
	 */
	protected function subs()
	{
		$this->tpl_name = 'acp_newsletter_subs';

		$action = $this->request->variable('action', '');
		$user_id = $this->request->variable('u', 0);

		if ($action === 'unsub' && $user_id)
		{
			$this->admin_unsubscribe($user_id, $this->request->variable('lista', -1));
		}

		$cerca = trim($this->decode($this->request->variable('cerca', '', true)));
		$lista = $this->request->variable('lista', -1);
		$ordine = $this->request->variable('ordine', 'sub_time');
		$verso = $this->request->variable('verso', 'DESC');
		$start = $this->request->variable('start', 0);
		$per_pagina = 25;

		$ordini = array('username', 'sub_email', 'sub_time');
		$ordine = in_array($ordine, $ordini, true) ? $ordine : 'sub_time';
		$verso = (strtoupper($verso) === 'ASC') ? 'ASC' : 'DESC';

		$totale = $this->manager->count_subscribers($cerca, $lista);
		$totale_assoluto = $this->manager->count_subscribers();

		foreach ($this->manager->get_subscribers($start, $per_pagina, $ordine, $verso, $cerca, $lista) as $riga)
		{
			$iscrizione = (string) $riga['sub_email'];
			$attuale = (string) $riga['user_email'];

			$this->template->assign_block_vars('iscritti', array(
				'USER_ID'			=> (int) $riga['user_id'],
				'USERNAME'			=> get_username_string('no_profile', (int) $riga['user_id'], (string) $riga['username'], (string) $riga['user_colour']),
				'U_PROFILE'			=> get_username_string('profile', (int) $riga['user_id'], (string) $riga['username'], (string) $riga['user_colour']),
				'EMAIL'				=> htmlspecialchars($iscrizione, ENT_COMPAT, 'UTF-8'),
				'CURRENT_EMAIL'		=> htmlspecialchars($attuale, ENT_COMPAT, 'UTF-8'),
				'S_EMAIL_CHANGED'	=> ($attuale !== '' && strcasecmp($iscrizione, $attuale) !== 0),
				'S_NO_EMAIL'		=> ($attuale === ''),
				// Iscritto ma con le email di massa rifiutate nel profilo: non
				// ricevera nulla, e senza questo avviso l'amministratore
				// continuerebbe a contarlo fra i destinatari
				'S_MASSEMAIL_OFF'	=> empty($riga['user_allow_massemail']),
				'SUB_DATE'			=> $this->format_full_date((int) $riga['sub_time']),
				'SUB_IP'			=> htmlspecialchars((string) $riga['sub_ip'], ENT_COMPAT, 'UTF-8'),
				'LIST_NAME'			=> isset($riga['list_name']) ? (string) $riga['list_name'] : '',
				'U_UNSUB'			=> $this->subs_url() . '&amp;action=unsub&amp;u=' . (int) $riga['user_id']
					. (($lista >= 0) ? '&amp;lista=' . (int) $lista : ''),
			));
		}

		// Le intestazioni cliccabili invertono il verso quando si ricliccano
		// sulla colonna gia attiva
		$base = $this->subs_url()
			. (($cerca !== '') ? '&amp;cerca=' . urlencode($cerca) : '')
			. (($lista >= 0) ? '&amp;lista=' . (int) $lista : '');

		// Filtro per notiziario, mostrato solo quando ce n'e piu di uno
		$notiziari = $this->manager->get_lists();

		if (count($notiziari) > 1)
		{
			$conteggi = $this->manager->count_by_list();

			$this->template->assign_block_vars('filtri_lista', array(
				'NAME'			=> $this->language->lang('NL_LIST_FILTER_ALL'),
				'COUNT'			=> $totale_assoluto,
				'S_SELECTED'	=> ($lista < 0),
				'U_FILTER'		=> $this->subs_url() . (($cerca !== '') ? '&amp;cerca=' . urlencode($cerca) : ''),
			));

			foreach ($notiziari as $notiziario)
			{
				$id = (int) $notiziario['list_id'];

				$this->template->assign_block_vars('filtri_lista', array(
					'NAME'			=> (string) $notiziario['list_name'],
					'COUNT'			=> isset($conteggi[$id]) ? $conteggi[$id] : 0,
					'S_SELECTED'	=> ($lista === $id),
					'U_FILTER'		=> $this->subs_url() . '&amp;lista=' . $id . (($cerca !== '') ? '&amp;cerca=' . urlencode($cerca) : ''),
				));
			}
		}

		foreach ($ordini as $colonna)
		{
			$attiva = ($ordine === $colonna);
			$prossimo = ($attiva && $verso === 'ASC') ? 'DESC' : 'ASC';

			$this->template->assign_vars(array(
				'U_SORT_' . strtoupper($colonna)	=> $base . '&amp;ordine=' . $colonna . '&amp;verso=' . $prossimo,
				'S_SORTED_' . strtoupper($colonna)	=> $attiva,
			));
		}

		$this->container->get('pagination')->generate_template_pagination(
			$base . '&amp;ordine=' . $ordine . '&amp;verso=' . $verso,
			'pagination',
			'start',
			$totale,
			$per_pagina,
			$start
		);

		$this->template->assign_vars(array_merge($this->identity_vars(), array(
			'NL_TOTAL_SUBS'		=> $totale_assoluto,
			'NL_UNIQUE_SUBS'	=> $this->manager->count_unique_subscribers(),
			'S_NL_MULTI'		=> $this->manager->lists_available(),
			'NL_FOUND_SUBS'		=> $totale,
			'NL_SEARCH'			=> htmlspecialchars($cerca, ENT_COMPAT, 'UTF-8'),
			'S_SEARCHING'		=> ($cerca !== '' || $lista >= 0),
			'U_LISTS'			=> $this->switch_mode('lists'),
			'S_SORT_ASC'		=> ($verso === 'ASC'),
			'S_SUBS_ENABLED'	=> !empty($this->config['newsletter_subs_enabled']),
			'U_ACTION'			=> $this->subs_url(),
			'U_CLEAR_SEARCH'	=> $this->subs_url(),
			'U_SETTINGS'		=> $this->settings_url(),
		)));
	}

	/**
	 * Annulla l'iscrizione di un singolo utente.
	 *
	 * A differenza della disiscrizione che parte dall'utente, questa non tocca
	 * la casella "Ricevi email dall'amministratore" del profilo: quella
	 * esprime la volonta dell'interessato, e non spetta a un amministratore
	 * cambiarla per suo conto. Qui si toglie soltanto dall'elenco della
	 * newsletter.
	 *
	 * @param int $user_id
	 */
	protected function admin_unsubscribe($user_id, $list_id = -1)
	{
		$dati = $this->manager->get_user_row($user_id);

		if (!$dati)
		{
			trigger_error($this->language->lang('NL_SUB_NOT_FOUND') . adm_back_link($this->subs_url()), E_USER_WARNING);
		}

		$indirizzo = trim((string) $dati['user_email']);

		if (confirm_box(true))
		{
			// Guardando un solo notiziario si toglie da quello; guardando tutti,
			// da tutti. Togliere da ogni notiziario mentre se ne guarda uno
			// sarebbe una sorpresa spiacevole e non annullabile
			$this->manager->unsubscribe($user_id, false, $this->user->ip, (int) $this->user->data['user_id'], $list_id);
			$this->manager->log_admin('LOG_NEWSLETTER_ADMIN_UNSUB', array((string) $dati['username']));

			$avvisato = false;

			if ($indirizzo !== '' && $this->request->variable('nl_notify', 0))
			{
				// Un testo suo, non quello dell'addio: chi non ha premuto
				// nessun pulsante ha bisogno di sapere che cosa e successo,
				// altrimenti l'unica reazione possibile e scrivere per chiedere
				$avvisato = $this->manager->send_service_email($dati, 'NL_MAIL_REMOVED_SUBJECT', 'NL_MAIL_REMOVED_BODY');
			}

			$messaggio = $avvisato
				? $this->language->lang('NL_UNSUB_DONE_NOTIFIED', $dati['username'], $indirizzo)
				: $this->language->lang('NL_UNSUB_DONE', $dati['username']);

			trigger_error($messaggio . adm_back_link($this->subs_url()));
		}

		$nome_lista = ($list_id > 0) ? $this->manager->list_name($list_id) : '';

		// La domanda dice da quale notiziario si esce: "togliere Mario dalla
		// newsletter" e ambiguo quando i notiziari sono tre
		$domanda = ($nome_lista !== '')
			? $this->language->lang('NL_CONFIRM_UNSUB_LIST', $dati['username'], $nome_lista)
			: $this->language->lang('NL_CONFIRM_UNSUB', $dati['username']);

		$this->template->assign_vars(array_merge($this->identity_vars(), array(
			'S_NL_HAS_EMAIL'		=> ($indirizzo !== ''),
			'S_NL_NOTIFY_DEFAULT'	=> !empty($this->config['newsletter_removed_email']),
			'NL_NOTIFY_EMAIL'		=> htmlspecialchars($indirizzo, ENT_COMPAT, 'UTF-8'),
		)));

		// Il modello di conferma e sostituito da uno nostro: quello di phpBB
		// non ha modo di ospitare un campo in piu, e la scelta se avvisare o no
		// va presa nello stesso momento della rimozione, non prima in una
		// impostazione generale
		confirm_box(
			false,
			$domanda,
			build_hidden_fields(array(
				'action'	=> 'unsub',
				'u'			=> (int) $user_id,
				'lista'		=> (int) $list_id,
			)),
			'confirm_body_newsletter.html'
		);
	}

	/**
	 * Data e ora per esteso.
	 *
	 * Due chiamate invece di una perche in un formato di phpBB ogni lettera di
	 * "alle ore" andrebbe protetta con la barra rovescia - la a indica
	 * mattina o pomeriggio, la l il giorno della settimana, la o l'anno ISO -
	 * e ne uscirebbe una stringa illeggibile. Tenendo la congiunzione come
	 * chiave di lingua, la traduzione inglese puo scrivere "at" e la frase
	 * resta corretta in entrambe le lingue.
	 *
	 * @param int $timestamp
	 * @return string
	 */
	protected function format_full_date($timestamp)
	{
		if ($timestamp <= 0)
		{
			return '';
		}

		return $this->user->format_date($timestamp, 'd F Y')
			. ' ' . $this->language->lang('NL_AT_HOUR') . ' '
			. $this->user->format_date($timestamp, 'H:i:s');
	}

	/* =====================================================================
	 * Impostazioni
	 * ================================================================== */

	/**
	 * Pagina delle impostazioni generali
	 */
	protected function settings()
	{
		$form_key = 'newsletter_settings';
		add_form_key($form_key);

		// La prova di invio precede il salvataggio e non lo esegue: chi vuole
		// solo verificare la posta non deve trovarsi riscritte tutte le altre
		// impostazioni per un pulsante premuto per sbaglio
		if ($this->request->is_set_post('submit_diag'))
		{
			if (!check_form_key($form_key))
			{
				trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$this->run_diagnostics();
		}

		if ($this->request->is_set_post('submit'))
		{
			if (!check_form_key($form_key))
			{
				trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			// Ogni voce viene scritta solo se il modulo l'ha davvero inviata.
			// Senza questo controllo, salvare le impostazioni con un modulo piu
			// vecchio del codice - la pagina caricata prima di un aggiornamento,
			// o un campo aggiunto da una migrazione appena passata - azzererebbe
			// in silenzio le opzioni che quel modulo non conosceva
			$this->save_flag('newsletter_enabled');
			$this->save_flag('newsletter_respect_optout');
			$this->save_flag('newsletter_skip_banned');
			$this->save_flag('newsletter_check_dns');
			$this->save_flag('newsletter_strip_emoji');
			$this->save_flag('newsletter_copy_sender');
			$this->save_flag('newsletter_subs_enabled');
			$this->save_flag('newsletter_welcome_email');
			$this->save_flag('newsletter_goodbye_email');
			$this->save_flag('newsletter_removed_email');
			$this->save_flag('newsletter_archive_link_top');
			$this->save_flag('newsletter_archive_navbar');
			$this->save_flag('newsletter_bbcode_smilies');
			$this->save_flag('newsletter_bbcode_urls');
			$this->save_flag('newsletter_send_approval');
			$this->save_number('newsletter_send_limit', 0, 50, 2);
			$this->save_number('newsletter_fail_streak', 0, 200, 10);
			$this->save_number('newsletter_stall_grace', 300, 86400, 3600);
			$this->save_flag('newsletter_report_email');

			if ($this->request->is_set_post('newsletter_send_groups_present'))
			{
				// Le caselle non spuntate non vengono inviate dal browser: senza
				// un campo che dica "questa parte del modulo c'era", togliere
				// tutte le spunte sarebbe indistinguibile dal non avere la sezione
				$this->config->set('newsletter_send_groups', implode(',', array_filter(array_map('intval',
					$this->request->variable('newsletter_send_groups', array(0))))));
				$this->config->set('newsletter_send_lists', implode(',', array_filter(array_map('intval',
					$this->request->variable('newsletter_send_lists', array(0))))));
			}

			$this->save_number('newsletter_warn_threshold', 1, 100000, 50);
			$this->save_number('newsletter_max_attempts', 1, 5, 2);
			$this->save_number('newsletter_time_budget', 5, 300, 45);
			$this->save_number('newsletter_keep_days', 0, 3650, 0);
			$this->save_number('newsletter_archive_visibility', 0, 3, 1);

			if ($this->request->is_set_post('newsletter_archive_groups'))
			{
				$this->config->set('newsletter_archive_groups', implode(',', array_filter(array_map('intval',
					$this->request->variable('newsletter_archive_groups', array(0))))));
			}
			$this->save_number('newsletter_archive_per_page', 5, 100, 20);

			$this->save_text('newsletter_from_name', true);
			$this->save_text('newsletter_from_email');
			$this->save_text('newsletter_reply_to');
			$this->save_text('newsletter_banner_link');

			if ($this->request->is_set_post('newsletter_batch_size_preset') || $this->request->is_set_post('newsletter_batch_size'))
			{
				$this->config->set('newsletter_batch_size', $this->read_batch('newsletter_batch_size'));
			}

			if ($this->request->is_set_post('newsletter_interval_preset') || $this->request->is_set_post('newsletter_interval'))
			{
				$this->config->set('newsletter_interval', $this->read_interval('newsletter_interval'));
			}

			// I quattro limiti del banner sono legati fra loro: il minimo deve
			// restare sotto il massimo, quindi si leggono insieme
			if ($this->request->is_set_post('newsletter_banner_min_height'))
			{
				$min_h = max(20, min(1000, $this->request->variable('newsletter_banner_min_height', 200)));
				$this->config->set('newsletter_banner_min_height', $min_h);
				$this->config->set('newsletter_banner_max_height', max($min_h, min(1000, $this->request->variable('newsletter_banner_max_height', 260))));
			}

			if ($this->request->is_set_post('newsletter_banner_min_width'))
			{
				$min_w = max(50, min(5000, $this->request->variable('newsletter_banner_min_width', 600)));
				$this->config->set('newsletter_banner_min_width', $min_w);
				$this->config->set('newsletter_banner_max_width', max($min_w, min(5000, $this->request->variable('newsletter_banner_max_width', 2600))));
			}

			$testi_lunghi = array();

			if ($this->request->is_set_post('newsletter_footer_text'))
			{
				$testi_lunghi['newsletter_footer_text'] = $this->decode($this->request->variable('newsletter_footer_text', '', true));
			}

			if ($this->request->is_set_post('newsletter_footer_html'))
			{
				$testi_lunghi['newsletter_footer_html'] = $this->html->sanitize($this->decode($this->request->variable('newsletter_footer_html', '', true)));
			}

			if ($this->request->is_set_post('newsletter_css'))
			{
				$testi_lunghi['newsletter_css'] = $this->html->sanitize_css($this->decode($this->request->variable('newsletter_css', '', true)));
			}

			if ($this->request->is_set_post('newsletter_intro'))
			{
				$testi_lunghi['newsletter_intro'] = $this->html->sanitize($this->decode($this->request->variable('newsletter_intro', '', true)));
			}

			if (!empty($testi_lunghi))
			{
				$this->config_text->set_array($testi_lunghi);
			}

			$this->manager->log_admin('LOG_NEWSLETTER_SETTINGS');

			trigger_error($this->language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
		}

		$testi = $this->config_text->get_array(array('newsletter_footer_text', 'newsletter_footer_html', 'newsletter_css', 'newsletter_intro'));

		$batch_libero = $this->assign_presets('lotti', $this->batch_presets(), (int) $this->config['newsletter_batch_size'], array($this, 'batch_label'));
		$interval_libero = $this->assign_presets('intervalli', $this->interval_presets(), (int) $this->config['newsletter_interval'], array($this, 'interval_label'));

		$this->template->assign_vars($this->delivery_diagnostics());

		$ammessi = $this->manager->split_ids((string) $this->default_for('newsletter_archive_groups', ''));
		$scrittori = $this->manager->split_ids((string) $this->default_for('newsletter_send_groups', ''));
		$notiziari_ammessi = $this->manager->split_ids((string) $this->default_for('newsletter_send_lists', ''));

		foreach ($this->manager->get_lists() as $notiziario)
		{
			$this->template->assign_block_vars('notiziari_invio', array(
				'VALUE'			=> (int) $notiziario['list_id'],
				'NAME'			=> (string) $notiziario['list_name'],
				'S_SELECTED'	=> in_array((int) $notiziario['list_id'], $notiziari_ammessi, true),
			));
		}

		foreach ($this->manager->get_groups() as $gruppo)
		{
			$this->template->assign_block_vars('gruppi_archivio', array(
				'GROUP_ID'		=> $gruppo['group_id'],
				'GROUP_NAME'	=> $gruppo['group_name'],
				'S_SELECTED'	=> in_array($gruppo['group_id'], $ammessi, true),
			));

			$this->template->assign_block_vars('gruppi_invio', array(
				'GROUP_ID'		=> $gruppo['group_id'],
				'GROUP_NAME'	=> $gruppo['group_name'],
				'MEMBERS'		=> $gruppo['members'],
				'S_SELECTED'	=> in_array($gruppo['group_id'], $scrittori, true),
			));
		}

		$this->template->assign_vars(array_merge($this->identity_vars(), $this->banner_vars(), array(
			'NEWSLETTER_ENABLED'		=> !empty($this->config['newsletter_enabled']),
			'NEWSLETTER_BATCH_SIZE'		=> (int) $this->config['newsletter_batch_size'],
			'NEWSLETTER_INTERVAL'		=> manager::seconds_to_time((int) $this->config['newsletter_interval']),
			'S_BATCH_CUSTOM'			=> $batch_libero,
			'S_INTERVAL_CUSTOM'			=> $interval_libero,
			'NEWSLETTER_WARN_THRESHOLD'	=> (int) $this->config['newsletter_warn_threshold'],
			'NEWSLETTER_MAX_ATTEMPTS'	=> (int) $this->config['newsletter_max_attempts'],
			'NEWSLETTER_TIME_BUDGET'	=> (int) $this->config['newsletter_time_budget'],
			'NEWSLETTER_RESPECT_OPTOUT'	=> !empty($this->config['newsletter_respect_optout']),
			'NEWSLETTER_SKIP_BANNED'	=> !empty($this->config['newsletter_skip_banned']),
			'NEWSLETTER_CHECK_DNS'		=> !empty($this->config['newsletter_check_dns']),
			'NEWSLETTER_STRIP_EMOJI'	=> !empty($this->config['newsletter_strip_emoji']),
			'NEWSLETTER_COPY_SENDER'	=> !empty($this->config['newsletter_copy_sender']),
			'NEWSLETTER_KEEP_DAYS'		=> (int) $this->config['newsletter_keep_days'],
			'NEWSLETTER_SUBS_ENABLED'	=> !empty($this->config['newsletter_subs_enabled']),
			'NEWSLETTER_WELCOME_EMAIL'	=> !empty($this->config['newsletter_welcome_email']),
			'NEWSLETTER_GOODBYE_EMAIL'	=> !empty($this->config['newsletter_goodbye_email']),
			'NEWSLETTER_REMOVED_EMAIL'	=> !empty($this->config['newsletter_removed_email']),
			'NEWSLETTER_ARCHIVE_VIS'	=> (int) $this->config['newsletter_archive_visibility'],
			'NEWSLETTER_ARCHIVE_PER_PAGE'	=> (int) $this->config['newsletter_archive_per_page'],
			'NEWSLETTER_ARCHIVE_LINK_TOP'	=> !empty($this->config['newsletter_archive_link_top']),
			'NEWSLETTER_ARCHIVE_NAVBAR'	=> !empty($this->config['newsletter_archive_navbar']),
			'NEWSLETTER_ARCHIVE_COUNT'	=> $this->manager->count_archive(),
			'S_ARCHIVE_GROUPS'			=> ((int) $this->config['newsletter_archive_visibility'] === 3),
			'NEWSLETTER_SEND_APPROVAL'	=> ($this->manager->lists_available() ? !empty($this->config['newsletter_send_approval']) : true),
			'NEWSLETTER_SEND_LIMIT'		=> isset($this->config['newsletter_send_limit']) ? (int) $this->config['newsletter_send_limit'] : 2,
			'S_SEND_AVAILABLE'			=> isset($this->config['newsletter_send_groups']),
			'S_FAILSAFE_AVAILABLE'		=> $this->manager->failsafe_available(),
			'NEWSLETTER_FAIL_STREAK'	=> $this->manager->failsafe_available() ? (int) $this->config['newsletter_fail_streak'] : 10,
			'NEWSLETTER_STALL_GRACE'	=> $this->manager->failsafe_available() ? (int) round($this->config['newsletter_stall_grace'] / 60) : 60,
			'NEWSLETTER_REPORT_EMAIL'	=> $this->manager->failsafe_available() ? !empty($this->config['newsletter_report_email']) : true,
			'NEWSLETTER_SEND_COUNT'		=> $this->manager->count_pending(),
			'NEWSLETTER_BBCODE_SMILIES'	=> !empty($this->config['newsletter_bbcode_smilies']),
			'NEWSLETTER_BBCODE_URLS'	=> !empty($this->config['newsletter_bbcode_urls']),
			'NEWSLETTER_BANNER_MIN_H'	=> $this->banner->min_height(),
			'NEWSLETTER_BANNER_MAX_H'	=> $this->banner->max_height(),
			'NEWSLETTER_BANNER_MIN_W'	=> $this->banner->min_width(),
			'NEWSLETTER_BANNER_MAX_W'	=> $this->banner->max_width(),
			'NEWSLETTER_BANNER_LINK'	=> htmlspecialchars((string) $this->config['newsletter_banner_link'], ENT_COMPAT, 'UTF-8'),

			// I testi che entrano in gioco lasciando vuoto il campo: mostrarli
			// rende concreto un "viene usato il predefinito" che altrimenti
			// obbliga a inviare un messaggio per scoprire che cosa contiene
			'NL_PREDEF_FOOTER_TEXT'		=> htmlspecialchars($this->language->lang('NL_DEFAULT_FOOTER_TEXT'), ENT_COMPAT, 'UTF-8'),
			'NL_PREDEF_FOOTER_HTML'		=> htmlspecialchars($this->language->lang('NL_DEFAULT_FOOTER_HTML'), ENT_COMPAT, 'UTF-8'),
			'NL_PREDEF_CSS'				=> htmlspecialchars($this->html->default_css(), ENT_COMPAT, 'UTF-8'),
			'NL_PREDEF_INTRO'			=> htmlspecialchars($this->language->lang('NL_DEFAULT_INTRO'), ENT_COMPAT, 'UTF-8'),
			'NEWSLETTER_FROM_NAME'		=> htmlspecialchars((string) $this->config['newsletter_from_name'], ENT_COMPAT, 'UTF-8'),
			'NEWSLETTER_FROM_EMAIL'		=> htmlspecialchars((string) $this->config['newsletter_from_email'], ENT_COMPAT, 'UTF-8'),
			'NEWSLETTER_REPLY_TO'		=> htmlspecialchars((string) $this->config['newsletter_reply_to'], ENT_COMPAT, 'UTF-8'),
			'NEWSLETTER_FOOTER_TEXT'	=> htmlspecialchars((string) $testi['newsletter_footer_text'], ENT_COMPAT, 'UTF-8'),
			'NEWSLETTER_FOOTER_HTML'	=> htmlspecialchars((string) $testi['newsletter_footer_html'], ENT_COMPAT, 'UTF-8'),
			'NEWSLETTER_CSS'			=> htmlspecialchars((string) $testi['newsletter_css'], ENT_COMPAT, 'UTF-8'),
			'NEWSLETTER_INTRO'			=> htmlspecialchars((string) $testi['newsletter_intro'], ENT_COMPAT, 'UTF-8'),
			'NEWSLETTER_SUBSCRIBERS'	=> $this->manager->count_unique_subscribers(),
			'NEWSLETTER_LAST_RUN'		=> $this->config['newsletter_last_run'] ? $this->user->format_date((int) $this->config['newsletter_last_run']) : $this->language->lang('NL_NEVER'),
			'S_SMTP'					=> !empty($this->config['smtp_delivery']),
			'S_EMAIL_ENABLED'			=> !empty($this->config['email_enable']),
			'U_ACTION'					=> $this->u_action,
			'U_BOARD_EMAIL_SETTINGS'	=> $this->admin_url('i=acp_board&amp;mode=email'),
			'U_COMPOSE'					=> $this->compose_url(),
		)));
	}

	/* =====================================================================
	 * Supporto
	 * ================================================================== */

	/**
	 * Esegue la prova di invio e prepara il resoconto
	 */
	protected function run_diagnostics()
	{
		$destinatario = trim($this->request->variable('nl_diag_email', '', true));

		if ($destinatario === '')
		{
			$destinatario = trim((string) $this->user->data['user_email']);
		}

		$destinatario = html_entity_decode($destinatario, ENT_COMPAT, 'UTF-8');

		/** @var \salvocortesiano\newsletter\core\diagnostics $diag */
		$diag = $this->container->get('salvocortesiano.newsletter.diagnostics');

		$passi = $diag->run($destinatario);
		$riuscito = true;

		foreach ($passi as $passo)
		{
			if ($passo['status'] === \salvocortesiano\newsletter\core\diagnostics::FAIL)
			{
				$riuscito = false;
			}

			$this->template->assign_block_vars('diagnostica', array(
				'LABEL'		=> $passo['label'],
				'STATUS'	=> $passo['status'],
				// Le risposte del server arrivano da fuori e finiscono in una
				// pagina del pannello: vanno codificate, non c'e ragione per
				// cui debbano poter contenere marcatura
				'DETAIL'	=> htmlspecialchars($passo['detail'], ENT_COMPAT, 'UTF-8'),
			));
		}

		$this->template->assign_vars(array(
			'S_NL_DIAG_RUN'		=> true,
			'S_NL_DIAG_OK'		=> $riuscito,
			'NL_DIAG_TARGET'	=> htmlspecialchars($destinatario, ENT_COMPAT, 'UTF-8'),
		));

		$this->config->set('newsletter_test_email', $destinatario);

		// L'esito resta scritto: alla visita successiva la pagina puo dire come
		// e andata l'ultima prova invece di ripetere un avviso su una
		// configurazione che si e gia vista funzionare
		$this->config->set('newsletter_diag_ok', $riuscito ? 1 : 0);
		$this->config->set('newsletter_diag_time', time());
	}

	/**
	 * Scrive una opzione si/no, ma solo se il modulo l'ha inviata
	 *
	 * @param string $nome
	 */
	protected function save_flag($nome)
	{
		if ($this->request->is_set_post($nome))
		{
			$this->config->set($nome, $this->request->variable($nome, 0) ? 1 : 0);
		}
	}

	/**
	 * Scrive un numero entro i suoi limiti, se il modulo l'ha inviato
	 *
	 * @param string $nome
	 * @param int    $min
	 * @param int    $max
	 * @param int    $predefinito
	 */
	protected function save_number($nome, $min, $max, $predefinito)
	{
		if ($this->request->is_set_post($nome))
		{
			$this->config->set($nome, max($min, min($max, $this->request->variable($nome, $predefinito))));
		}
	}

	/**
	 * Scrive un testo breve, se il modulo l'ha inviato
	 *
	 * @param string $nome
	 * @param bool   $multibyte
	 */
	protected function save_text($nome, $multibyte = false)
	{
		if ($this->request->is_set_post($nome))
		{
			$this->config->set($nome, $this->request->variable($nome, '', $multibyte));
		}
	}

	/**
	 * Stato della configurazione di posta del forum.
	 *
	 * La newsletter non apre una connessione propria: passa le stesse funzioni
	 * di phpBB, che usano le impostazioni email del pannello. Quando un invio
	 * non parte, nove volte su dieci il problema e li, e senza questo riquadro
	 * l'amministratore lo cerca nell'estensione.
	 *
	 * @return array
	 */
	protected function delivery_diagnostics()
	{
		$smtp = !empty($this->config['smtp_delivery']);
		$host = trim((string) $this->config['smtp_host']);
		$porta = (int) $this->config['smtp_port'];

		// phpBB non negozia da solo la cifratura: la ricava dal prefisso del
		// nome del server. Una porta 465 o 587 senza prefisso e quasi sempre
		// la causa di "Impossibile ottenere il codice di risposta"
		$cifrate = array(465 => 'ssl://', 587 => 'tls://', 25 => '');
		$prefisso_atteso = isset($cifrate[$porta]) ? $cifrate[$porta] : '';
		$ha_prefisso = (stripos($host, 'ssl://') === 0 || stripos($host, 'tls://') === 0);

		// Esito dell'ultima prova, se ne e stata fatta una
		$quando = isset($this->config['newsletter_diag_time']) ? (int) $this->config['newsletter_diag_time'] : 0;
		$riuscita = $quando > 0 && !empty($this->config['newsletter_diag_ok']);

		$stato = ($quando === 0) ? 'unknown' : ($riuscita ? 'ok' : 'fail');

		// Il prefisso mancante non ha lo stesso peso sulle due porte. Sulla 465
		// il server pretende la cifratura fin dal primo byte e senza prefisso
		// non parte nulla: e un errore. Sulla 587 la connessione in chiaro
		// funziona e il messaggio arriva, ma STARTTLS non viene attivato e le
		// credenziali viaggiano leggibili: e una nota sulla riservatezza, non
		// un guasto. Confonderle significa allarmare per una cosa che funziona
		$avviso = ($smtp && $host !== '' && $prefisso_atteso !== '' && !$ha_prefisso);
		$grave = ($avviso && $porta === 465 && !$riuscita);

		return array(
			'S_NL_SMTP'				=> $smtp,
			'S_NL_EMAIL_ON'			=> !empty($this->config['email_enable']),
			'S_NL_TLS_WARNING'		=> ($avviso && $grave),
			'S_NL_TLS_INFO'			=> ($avviso && !$grave),
			'S_NL_DIAG_STATE'		=> $stato,
			'NL_DIAG_LAST_TIME'		=> $quando ? $this->user->format_date($quando) : '',
			'NL_DIAG_HOST'			=> ($host !== '') ? htmlspecialchars($host, ENT_COMPAT, 'UTF-8') : $this->language->lang('NL_DIAG_NOT_SET'),
			'NL_DIAG_PORT_VALUE'	=> $porta ? $porta : $this->language->lang('NL_DIAG_NOT_SET'),
			'NL_DIAG_AUTH_VALUE'	=> trim((string) $this->config['smtp_username']) !== ''
				? htmlspecialchars((string) $this->config['smtp_username'], ENT_COMPAT, 'UTF-8')
				: $this->language->lang('NL_DIAG_NOT_SET'),
			'NL_DIAG_BOARD_ADDR'	=> htmlspecialchars((string) $this->config['board_email'], ENT_COMPAT, 'UTF-8'),
			'NL_DIAG_EMAIL_VALUE'	=> htmlspecialchars(
				((string) $this->config['newsletter_test_email'] !== '')
					? (string) $this->config['newsletter_test_email']
					: (string) $this->user->data['user_email'],
				ENT_COMPAT, 'UTF-8'),
			'NL_DIAG_SUGGESTED'		=> htmlspecialchars($prefisso_atteso . $host, ENT_COMPAT, 'UTF-8'),
			'NL_DIAG_CURRENT'		=> htmlspecialchars($host, ENT_COMPAT, 'UTF-8'),
			'NL_DIAG_PORT_LABEL'	=> $porta,
		);
	}

	/**
	 * Riga della campagna ricostruita dai campi del modulo.
	 *
	 * Serve per l'anteprima e per l'invio di prova, quando la campagna non e
	 * ancora salvata: mailer e manager lavorano su una riga di tabella, e
	 * riceverne una equivalente evita di duplicarne la logica.
	 *
	 * @param array $dati
	 * @return array
	 */
	protected function to_campaign_row(array $dati)
	{
		$corpo = $dati['body'];

		if ((int) $dati['format'] === manager::FORMAT_BBCODE)
		{
			$scarta = array();
			$corpo = $this->manager->bbcode()->to_storage($corpo, $scarta);
		}

		return array(
			'campaign_id'			=> 0,
			'campaign_subject'		=> $dati['subject'],
			'campaign_body'			=> $corpo,
			'campaign_css'			=> $dati['css'],
			'campaign_format'		=> (int) $dati['format'],
			'campaign_banner'		=> (int) $dati['banner'],
			'campaign_public'		=> (int) $dati['public'],
			'campaign_list_id'		=> (int) $dati['list'],
			'campaign_topics'		=> $dati['topics'],
			'campaign_priority'		=> (int) $dati['priority'],
			'campaign_importance'	=> $dati['importance'],
			'campaign_sensitivity'	=> $dati['sensitivity'],
			'campaign_from_name'	=> $dati['from_name'],
			'campaign_from_email'	=> $dati['from_email'],
			'campaign_reply_to'		=> $dati['reply_to'],
		);
	}

	/**
	 * L'amministratore visto come destinatario, per anteprime e prove
	 *
	 * @return array
	 */
	protected function self_recipient()
	{
		return array(
			'user_id'		=> (int) $this->user->data['user_id'],
			'username'		=> (string) $this->user->data['username'],
			'user_email'	=> (string) $this->user->data['user_email'],
			'user_lang'		=> (string) $this->user->data['user_lang'],
		);
	}

	/**
	 * Traduce i codici di errore interni, lasciando passare i messaggi che il
	 * server di posta ha restituito parola per parola
	 *
	 * @param string $errore
	 * @return string
	 */
	protected function error_text($errore)
	{
		if (strpos($errore, 'NL_ERR_') === 0)
		{
			return $this->language->lang($errore);
		}

		return $errore;
	}

	/**
	 * Etichetta di una voce nella tendina dei lotti
	 *
	 * @param int $valore
	 * @return string
	 */
	public function batch_label($valore)
	{
		return $this->language->lang('NL_BATCH_OPTION', (int) $valore);
	}

	/**
	 * Etichetta di una voce nella tendina degli intervalli.
	 *
	 * Accanto alla durata leggibile compare la forma hh:mm:ss, perche e quella
	 * che si ritrova nel campo libero: senza, passando da "30 minuti" al valore
	 * personalizzato non sarebbe evidente che cosa scrivere.
	 *
	 * @param int $secondi
	 * @return string
	 */
	public function interval_label($secondi)
	{
		return $this->format_duration($secondi) . ' (' . manager::seconds_to_time($secondi) . ')';
	}

	/**
	 * Etichetta che segnala una campagna ferma, vuota se procede
	 *
	 * @param array $campagna
	 * @return string
	 */
	protected function stall_label(array $campagna)
	{
		$ritardo = $this->manager->stall_seconds($campagna);

		if ($ritardo <= 0)
		{
			return '';
		}

		return $this->language->lang('NL_STALLED', $this->format_duration($ritardo));
	}

	/**
	 * Classe grafica dello stato, per la casella fra i conteggi
	 *
	 * @param int $stato
	 * @return string
	 */
	protected function state_class($stato)
	{
		switch ((int) $stato)
		{
			case manager::STATUS_RUNNING:
				return 'nl-state-running';

			case manager::STATUS_PAUSED:
				return 'nl-state-paused';

			case manager::STATUS_DONE:
				return 'nl-state-done';

			case manager::STATUS_CANCELLED:
				return 'nl-state-cancelled';

			case manager::STATUS_PENDING:
				return 'nl-state-pending';

			default:
				return 'nl-state-draft';
		}
	}

	/**
	 * Nome leggibile di un formato
	 *
	 * @param int $formato
	 * @return string
	 */
	protected function format_label($formato)
	{
		switch ((int) $formato)
		{
			case manager::FORMAT_HTML:
				return $this->language->lang('NL_FORMAT_HTML');

			case manager::FORMAT_BBCODE:
				return $this->language->lang('NL_FORMAT_BBCODE');

			default:
				return $this->language->lang('NL_FORMAT_TEXT');
		}
	}

	/**
	 * @param int $status
	 * @return string
	 */
	protected function status_label($status)
	{
		$chiavi = array(
			manager::STATUS_DRAFT		=> 'NL_STATUS_DRAFT',
			manager::STATUS_RUNNING		=> 'NL_STATUS_RUNNING',
			manager::STATUS_PAUSED		=> 'NL_STATUS_PAUSED',
			manager::STATUS_DONE		=> 'NL_STATUS_DONE',
			manager::STATUS_CANCELLED	=> 'NL_STATUS_CANCELLED',
			manager::STATUS_PENDING		=> 'NL_STATUS_PENDING',
		);

		return $this->language->lang(isset($chiavi[$status]) ? $chiavi[$status] : 'NL_STATUS_DRAFT');
	}

	/**
	 * @param int $status
	 * @return string
	 */
	protected function queue_status_label($status)
	{
		switch ($status)
		{
			case manager::QUEUE_SENT:
				return $this->language->lang('NL_QUEUE_SENT');

			case manager::QUEUE_FAILED:
				return $this->language->lang('NL_QUEUE_FAILED');

			default:
				return $this->language->lang('NL_QUEUE_PENDING');
		}
	}

	/**
	 * Durata leggibile a partire dai secondi
	 *
	 * @param int $secondi
	 * @return string
	 */
	protected function format_duration($secondi)
	{
		$secondi = max(0, (int) $secondi);

		if ($secondi === 0)
		{
			return $this->language->lang('NL_DURATION_NONE');
		}

		$giorni = (int) floor($secondi / 86400);
		$ore = (int) floor(($secondi % 86400) / 3600);
		$minuti = (int) floor(($secondi % 3600) / 60);

		$pezzi = array();

		if ($giorni)
		{
			$pezzi[] = $this->language->lang('NL_DAYS', $giorni);
		}

		if ($ore)
		{
			$pezzi[] = $this->language->lang('NL_HOURS', $ore);
		}

		if ($minuti && !$giorni)
		{
			$pezzi[] = $this->language->lang('NL_MINUTES', $minuti);
		}

		return empty($pezzi) ? $this->language->lang('NL_MINUTES', 1) : implode(' ', $pezzi);
	}

	/**
	 * Indirizzo di una azione protetta da codice di controllo
	 *
	 * @param string $action
	 * @param int    $campaign_id
	 * @return string
	 */
	protected function action_url($action, $campaign_id)
	{
		return $this->logs_url() . '&amp;action=' . $action . '&amp;campaign_id=' . (int) $campaign_id . '&amp;hash=' . generate_link_hash('nl_acp');
	}

	/**
	 * @param string $parametri
	 * @return string
	 */
	protected function admin_url($parametri)
	{
		global $phpbb_admin_path;

		return append_sid($phpbb_admin_path . 'index.' . $this->php_ext, $parametri);
	}

	/**
	 * @return string
	 */
	protected function compose_url()
	{
		return $this->switch_mode('compose');
	}

	/**
	 * @return string
	 */
	protected function logs_url()
	{
		return $this->switch_mode('logs');
	}

	/**
	 * @return string
	 */
	protected function subs_url()
	{
		return $this->switch_mode('subs');
	}

	/**
	 * @return string
	 */
	protected function settings_url()
	{
		return $this->switch_mode('settings');
	}

	/**
	 * Stesso modulo, modalita diversa.
	 *
	 * u_action contiene gia identificativo del modulo e sessione: riscriverne
	 * la sola modalita e piu sicuro che ricostruire l'indirizzo da zero,
	 * perche l'identificativo numerico del modulo cambia da forum a forum.
	 *
	 * @param string $mode
	 * @return string
	 */
	protected function switch_mode($mode)
	{
		return preg_replace('#mode=[a-z_]+#', 'mode=' . $mode, $this->u_action);
	}

	/**
	 * Versione, phpBB e PHP, letti dai metadati dell'estensione.
	 *
	 * Non sono decorazione: dicono a colpo d'occhio se l'installazione
	 * soddisfa i requisiti. I requisiti stessi vengono letti dal composer.json
	 * invece di essere ripetuti qui, cosi restano allineati.
	 *
	 * @return array
	 */
	protected function identity_vars()
	{
		global $phpbb_root_path;

		$versione = '';
		$php_richiesto = '';
		$phpbb_richiesto = '';

		try
		{
			$metadati = $this->container->get('ext.manager')
				->create_extension_metadata_manager('salvocortesiano/newsletter')
				->get_metadata();

			$versione = isset($metadati['version']) ? (string) $metadati['version'] : '';
			$php_richiesto = isset($metadati['require']['php']) ? (string) $metadati['require']['php'] : '';
			$phpbb_richiesto = isset($metadati['extra']['soft-require']['phpbb/phpbb'])
				? (string) $metadati['extra']['soft-require']['phpbb/phpbb']
				: '';
		}
		catch (\Exception $e)
		{
			// Metadati illeggibili: i distintivi mostrano cio che si sa
			// comunque, e la pagina resta perfettamente utilizzabile
		}

		return array(
			// Un INCLUDECSS con namespace non andrebbe bene: cercherebbe il
			// file nella cartella styles/ dell'estensione, non in adm/style/
			// Il numero finale costringe il browser a rileggere il foglio dopo
			// un aggiornamento dell'estensione: senza, continuerebbe a usare la
			// copia che ha in memoria e le regole nuove non si vedrebbero. Si
			// usa la data del file e non la versione perche cambia anche fra
			// due modifiche dello stesso rilascio
			'U_NL_ACP_CSS'		=> $phpbb_root_path . 'ext/salvocortesiano/newsletter/adm/style/newsletter_acp.css?v='
				. (int) @filemtime($phpbb_root_path . 'ext/salvocortesiano/newsletter/adm/style/newsletter_acp.css'),
			'NL_VERSION'		=> $versione,
			'NL_PHP_VERSION'	=> PHP_VERSION,
			'NL_PHP_REQUIRED'	=> $php_richiesto,
			'S_NL_PHP_OK'		=> ($php_richiesto === '' || $this->soddisfa(PHP_VERSION, $php_richiesto)),
			'NL_PHPBB_VERSION'	=> (string) $this->config['version'],
			'NL_PHPBB_REQUIRED'	=> $phpbb_richiesto,
			'S_NL_PHPBB_OK'		=> ($phpbb_richiesto === '' || $this->soddisfa((string) $this->config['version'], $phpbb_richiesto)),
		);
	}

	/**
	 * Una versione soddisfa un vincolo del tipo ">=7.1" o ">=3.3.0,<4.0.0"?
	 *
	 * Si interpreta la forma usata nei metadati delle estensioni, senza tirare
	 * dentro il risolutore di dipendenze di Composer: qui serve solo accendere
	 * un pallino verde o rosso.
	 *
	 * @param string $versione
	 * @param string $vincolo
	 * @return bool
	 */
	protected function soddisfa($versione, $vincolo)
	{
		foreach (explode(',', $vincolo) as $pezzo)
		{
			// Il suffisso di stabilita (@dev, @stable) fa parte della sintassi
			// di Composer ma non della versione: senza toglierlo il vincolo
			// reale "<4.0.0@dev" non verrebbe riconosciuto
			$pezzo = preg_replace('/@[a-z]+$/i', '', trim($pezzo));

			if ($pezzo === '')
			{
				continue;
			}

			if (!preg_match('/^(>=|<=|>|<|==|=|\^|~)?\s*([0-9][0-9a-zA-Z.\-]*)$/', $pezzo, $trovato))
			{
				continue;
			}

			$operatore = ($trovato[1] === '' || $trovato[1] === '=' || $trovato[1] === '^' || $trovato[1] === '~') ? '>=' : $trovato[1];

			if (!version_compare($versione, $trovato[2], $operatore))
			{
				return false;
			}
		}

		return true;
	}
}
