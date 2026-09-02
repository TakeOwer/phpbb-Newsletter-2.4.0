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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Composizione e consegna di un singolo messaggio.
 *
 * Il messenger di phpBB non viene usato per il corpo: costruisce da se le
 * intestazioni e dichiara sempre "Content-Type: text/plain", quindi non c'e
 * modo di inviare HTML senza ritrovarsi due intestazioni Content-Type in
 * conflitto - e quale delle due vinca dipende dal server di posta. La consegna
 * vera e propria pero resta affidata alle funzioni di phpBB (phpbb_mail e
 * smtpmail), cosi le impostazioni SMTP del pannello continuano a valere e non
 * serve un secondo client SMTP dentro l'estensione.
 */
class mailer
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\config\db_text */
	protected $config_text;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\controller\helper */
	protected $controller_helper;

	/** @var \salvocortesiano\newsletter\core\html */
	protected $html;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/** @var string Terminatore di riga usato in intestazioni e corpo */
	protected $eol;

	/** @var array Pie di pagina nelle due varianti, letti una volta sola */
	protected $footers = null;

	/**
	 * Constructor
	 */
	public function __construct(
		\phpbb\config\config $config,
		\phpbb\config\db_text $config_text,
		\phpbb\language\language $language,
		\phpbb\controller\helper $controller_helper,
		\salvocortesiano\newsletter\core\html $html,
		$root_path,
		$php_ext
	)
	{
		$this->config = $config;
		$this->config_text = $config_text;
		$this->language = $language;
		$this->controller_helper = $controller_helper;
		$this->html = $html;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;

		// Stessa scelta che fa il messenger di phpBB: la funzione mail() su
		// Windows parla direttamente SMTP, che pretende CRLF, mentre su Unix
		// passa a un programma locale che si aspetta LF soltanto
		$this->eol = (DIRECTORY_SEPARATOR === '\\') ? "\r\n" : "\n";
	}

	/**
	 * Invia il messaggio a un destinatario.
	 *
	 * @param array  $recipient Riga con user_id, username, user_email
	 * @param array  $campaign  Riga della campagna
	 * @param string $error     Motivo del fallimento, valorizzato se torna false
	 * @return bool
	 */
	public function send(array $recipient, array $campaign, &$error = '')
	{
		$error = '';

		$address = trim((string) $recipient['user_email']);

		if ($address === '')
		{
			$error = 'NL_ERR_NO_ADDRESS';
			return false;
		}

		if (!filter_var($address, FILTER_VALIDATE_EMAIL))
		{
			$error = 'NL_ERR_INVALID_ADDRESS';
			return false;
		}

		// Un dominio senza record MX ne A non accettera mai posta: e il caso
		// tipico dell'indirizzo di un sito chiuso da tempo. Il controllo costa
		// una interrogazione DNS per destinatario, percio e facoltativo
		if (!empty($this->config['newsletter_check_dns']) && !$this->domain_accepts_mail($address))
		{
			$error = 'NL_ERR_DEAD_DOMAIN';
			return false;
		}

		$is_html = !empty($campaign['campaign_format']);

		$subject = $this->personalise((string) $campaign['campaign_subject'], $recipient, false, (int) $campaign['campaign_id'], isset($campaign['campaign_list_id']) ? (int) $campaign['campaign_list_id'] : 0);
		$body = $this->build_body($campaign, $recipient, $is_html);

		if ($is_html)
		{
			$documento = $this->html->wrap_document($body, $subject, $this->campaign_css($campaign));
			$testo = $this->html->to_text($documento);

			$confine = '=_nl_' . md5(uniqid((string) mt_rand(), true));
			$content_type = 'multipart/alternative; boundary="' . $confine . '"';

			// La parte testuale viene per prima: i lettori mostrano l'ultima
			// parte che sanno interpretare, quindi l'HTML va in coda
			$message = '--' . $confine . $this->eol
				. 'Content-Type: text/plain; charset=UTF-8' . $this->eol
				. 'Content-Transfer-Encoding: base64' . $this->eol . $this->eol
				. $this->encode_body($testo) . $this->eol
				. '--' . $confine . $this->eol
				. 'Content-Type: text/html; charset=UTF-8' . $this->eol
				. 'Content-Transfer-Encoding: base64' . $this->eol . $this->eol
				. $this->encode_body($documento) . $this->eol
				. '--' . $confine . '--' . $this->eol;
		}
		else
		{
			$content_type = 'text/plain; charset=UTF-8';
			$message = $this->encode_body($body);
		}

		$headers = $this->build_headers($campaign, $recipient, $content_type);

		return $this->deliver($address, $subject, $message, $headers, $error);
	}

	/**
	 * Corpo del messaggio, personalizzato e completo di pie di pagina.
	 *
	 * @param array $campaign
	 * @param array $recipient
	 * @param bool  $is_html
	 * @return string
	 */
	public function build_body(array $campaign, array $recipient, $is_html)
	{
		$corpo = (string) $campaign['campaign_body'];

		if ($is_html)
		{
			$corpo = $this->html->sanitize($corpo);
		}

		// L'intestazione grafica precede tutto, compreso quanto ha scritto
		// l'amministratore: e il primo elemento che si vede aprendo il
		// messaggio, ed e li che serve
		if (!empty($campaign['campaign_banner_block']))
		{
			$corpo = $campaign['campaign_banner_block'] . "\n" . $corpo;
		}

		// Il richiamo alla versione consultabile sta ancora piu in alto, sopra
		// l'immagine: se la formattazione e rotta, quel collegamento deve
		// essere la prima cosa leggibile
		if (!empty($campaign['campaign_archive_block']))
		{
			$corpo = $campaign['campaign_archive_block'] . "\n" . $corpo;
		}

		// Gli argomenti in evidenza vengono preparati dal gestore e passati
		// nella riga della campagna: qui si sa comporre un messaggio, non
		// interrogare la tabella degli argomenti
		if (!empty($campaign['campaign_topics_block']))
		{
			$corpo .= ($is_html ? "\n" : "\n\n") . $campaign['campaign_topics_block'];
		}

		$corpo .= $this->footer($is_html);
		$corpo = $this->personalise($corpo, $recipient, $is_html, isset($campaign['campaign_id']) ? (int) $campaign['campaign_id'] : 0, isset($campaign['campaign_list_id']) ? (int) $campaign['campaign_list_id'] : 0);

		if ($is_html)
		{
			$corpo = $this->html->inline_css($corpo, $this->campaign_css($campaign));
			$corpo = $this->html->safe_links($corpo);
		}

		return $corpo;
	}

	/**
	 * Foglio di stile della campagna, con ripiego su quello generale
	 *
	 * @param array $campaign
	 * @return string
	 */
	protected function campaign_css(array $campaign)
	{
		$css = isset($campaign['campaign_css']) ? trim((string) $campaign['campaign_css']) : '';

		if ($css === '')
		{
			$css = trim((string) $this->config_text->get('newsletter_css'));
		}

		if ($css === '')
		{
			$css = $this->html->default_css();
		}

		return $this->html->sanitize_css($css);
	}

	/**
	 * Consegna vera e propria.
	 *
	 * @param string $address
	 * @param string $subject
	 * @param string $message
	 * @param array  $headers
	 * @param string $error
	 * @return bool
	 */
	protected function deliver($address, $subject, $message, array $headers, &$error)
	{
		$file_messenger = $this->root_path . 'includes/functions_messenger.' . $this->php_ext;

		if (file_exists($file_messenger))
		{
			include_once $file_messenger;
		}

		$err_msg = '';

		if (!empty($this->config['smtp_delivery']) && function_exists('smtpmail'))
		{
			// smtpmail vuole gli indirizzi nella forma usata dal messenger e
			// aggiunge da se le righe To e Subject, che percio non compaiono
			// fra le intestazioni che gli passiamo. Codifica anche l'oggetto:
			// darglielo gia codificato produrrebbe un =?UTF-8?B?...?= visibile
			$addresses = array('to' => array(array('email' => $address, 'name' => '')));

			$result = smtpmail($addresses, $subject, $message, $err_msg, $headers);
		}
		else if (function_exists('phpbb_mail'))
		{
			// phpbb_mail vuole le intestazioni come ARRAY e le unisce da se:
			// la prima riga del suo corpo e implode($eol, $headers), identica
			// in tutte le versioni di phpBB 3.x. Passargli la stringa gia
			// composta fa cadere l'invio con un TypeError dentro implode
			$result = phpbb_mail($address, $subject, $message, $headers, $this->eol, $err_msg);
		}
		else
		{
			// Ambiente in cui functions_messenger non e disponibile: si scende
			// alla funzione di PHP, codificando l'oggetto per conto nostro
			$parametri = !empty($this->config['email_force_sender']) ? '-f' . $this->config['board_email'] : '';

			$result = @mail($address, $this->encode_header($subject), $message, implode($this->eol, $headers), $parametri);
		}

		if (!$result)
		{
			// Il messaggio di errore di smtpmail arriva gia tradotto e puo
			// essere lunghissimo: nella colonna del registro ne sta un
			// estratto, che e comunque quanto serve per capire il rifiuto
			$err_msg = trim(strip_tags(str_replace(array('<br />', '<br>'), ' ', (string) $err_msg)));
			$error = ($err_msg !== '') ? $this->truncate($err_msg, 240) : 'NL_ERR_DELIVERY_FAILED';

			return false;
		}

		return true;
	}

	/**
	 * Intestazioni del messaggio.
	 *
	 * @param array  $campaign
	 * @param array  $recipient
	 * @param string $content_type
	 * @return array
	 */
	protected function build_headers(array $campaign, array $recipient, $content_type)
	{
		$from_email = $this->pick_address(
			isset($campaign['campaign_from_email']) ? $campaign['campaign_from_email'] : '',
			$this->config['newsletter_from_email'],
			$this->config['board_email']
		);

		$from_name = isset($campaign['campaign_from_name']) ? (string) $campaign['campaign_from_name'] : '';

		if ($from_name === '')
		{
			$from_name = (string) $this->config['newsletter_from_name'];
		}

		if ($from_name === '')
		{
			$from_name = html_entity_decode((string) $this->config['sitename'], ENT_COMPAT, 'UTF-8');
		}

		$reply_to = $this->pick_address(
			isset($campaign['campaign_reply_to']) ? $campaign['campaign_reply_to'] : '',
			$this->config['newsletter_reply_to'],
			$this->config['board_contact']
		);

		$priority = isset($campaign['campaign_priority']) ? (int) $campaign['campaign_priority'] : 3;
		$priority = ($priority < 1 || $priority > 5) ? 3 : $priority;

		$headers = array();

		$headers[] = 'From: ' . $this->format_address($from_email, $from_name);
		$headers[] = 'Reply-To: ' . $this->format_address($reply_to, $from_name);
		$headers[] = 'Return-Path: <' . $from_email . '>';
		$headers[] = 'Sender: <' . $from_email . '>';
		$headers[] = 'MIME-Version: 1.0';
		$headers[] = 'Content-Type: ' . $content_type;
		$headers[] = 'Content-Transfer-Encoding: ' . ((strpos($content_type, 'multipart/') === 0) ? '8bit' : 'base64');
		$headers[] = 'Date: ' . date('r');
		$headers[] = 'Message-ID: <' . md5(uniqid((string) mt_rand(), true)) . '@' . $this->server_name() . '>';
		$headers[] = 'X-Mailer: phpBB Newsletter';

		// Le tre intestazioni della priorita non sono intercambiabili: Outlook
		// legge X-Priority e X-MSMail-Priority, i client conformi a RFC 4021
		// leggono Importance. Dichiararle tutte e l'unico modo perche la
		// scelta dell'amministratore si veda ovunque
		$headers[] = 'X-Priority: ' . $priority . ' (' . $this->priority_label($priority) . ')';
		$headers[] = 'X-MSMail-Priority: ' . $this->ms_priority_label($priority);
		$headers[] = 'Priority: ' . ($priority <= 2 ? 'urgent' : ($priority >= 4 ? 'non-urgent' : 'normal'));

		$importance = isset($campaign['campaign_importance']) ? strtolower((string) $campaign['campaign_importance']) : 'normal';
		$importance = in_array($importance, array('high', 'normal', 'low'), true) ? $importance : 'normal';
		$headers[] = 'Importance: ' . ucfirst($importance);

		$sensitivity = isset($campaign['campaign_sensitivity']) ? strtolower((string) $campaign['campaign_sensitivity']) : '';

		if (in_array($sensitivity, array('personal', 'private', 'company-confidential'), true))
		{
			$headers[] = 'Sensitivity: ' . $sensitivity;
		}

		// Il collegamento di disiscrizione nell'intestazione non e decorativo:
		// i grandi fornitori lo trasformano in un pulsante, e chi lo usa non
		// segnala il messaggio come indesiderato. Il che protegge la
		// reputazione del dominio del forum
		$unsubscribe = $this->unsubscribe_url($recipient, isset($campaign['campaign_list_id']) ? (int) $campaign['campaign_list_id'] : 0);

		$headers[] = 'List-Unsubscribe: <' . $unsubscribe . '>, <mailto:' . $reply_to . '?subject=unsubscribe>';
		$headers[] = 'List-Id: <newsletter.' . $this->server_name() . '>';
		$headers[] = 'Auto-Submitted: auto-generated';
		$headers[] = 'Precedence: bulk';

		if (!empty($campaign['campaign_id']))
		{
			// Identifica la campagna nei registri del server di posta senza
			// esporre nulla di riservato
			$headers[] = 'X-Newsletter-Campaign: ' . (int) $campaign['campaign_id'];
		}

		return $headers;
	}

	/**
	 * Sostituisce i segnaposto nel testo.
	 *
	 * I nomi utente e il nome del forum sono conservati nel database gia
	 * codificati come entita HTML: in un messaggio HTML vanno lasciati come
	 * sono, in uno testuale vanno riportati ai caratteri veri, altrimenti un
	 * utente di nome "Rossi & Figli" si vedrebbe salutare come
	 * "Rossi &amp; Figli".
	 *
	 * @param string $text
	 * @param array  $recipient
	 * @param bool   $for_html
	 * @return string
	 */
	public function personalise($text, array $recipient, $for_html, $campaign_id = 0, $list_id = 0)
	{
		$username = isset($recipient['username']) ? (string) $recipient['username'] : '';
		$sitename = (string) $this->config['sitename'];

		if (!$for_html)
		{
			$username = html_entity_decode($username, ENT_COMPAT, 'UTF-8');
			$sitename = html_entity_decode($sitename, ENT_COMPAT, 'UTF-8');
		}

		$unsubscribe = $this->unsubscribe_url($recipient, $list_id);

		// Quando il numero non e pubblico il segnaposto porta alla pagina
		// principale del forum: meglio di un collegamento che risponde "non
		// disponibile", e meglio di un segnaposto lasciato scritto per esteso
		$archivio = !empty($campaign_id) ? $this->archive_url($campaign_id) : '';
		$archivio = ($archivio !== '') ? $archivio : $this->board_url();

		$sostituzioni = array(
			'{USERNAME}'		=> $username,
			'{EMAIL}'			=> isset($recipient['user_email']) ? (string) $recipient['user_email'] : '',
			'{USER_ID}'			=> isset($recipient['user_id']) ? (int) $recipient['user_id'] : 0,
			'{BOARD_NAME}'		=> $sitename,
			'{BOARD_URL}'		=> $this->board_url(),
			'{DATE}'			=> date('d/m/Y'),
			'{UNSUBSCRIBE_URL}'	=> $unsubscribe,
			'{ARCHIVE_URL}'		=> $archivio,
		);

		if ($for_html)
		{
			// Comodita: chi scrive in HTML vuole un collegamento gia fatto,
			// non un indirizzo da avvolgere a mano in un tag
			$sostituzioni['{UNSUBSCRIBE_LINK}'] = '<a href="' . htmlspecialchars($unsubscribe, ENT_COMPAT, 'UTF-8') . '">'
				. htmlspecialchars($this->language->lang('NL_UNSUBSCRIBE_LINK_TEXT'), ENT_COMPAT, 'UTF-8') . '</a>';

			$sostituzioni['{ARCHIVE_LINK}'] = '<a href="' . htmlspecialchars($archivio, ENT_COMPAT, 'UTF-8') . '">'
				. htmlspecialchars($this->language->lang('NL_ARCHIVE_LINK_TEXT'), ENT_COMPAT, 'UTF-8') . '</a>';
		}
		else
		{
			$sostituzioni['{UNSUBSCRIBE_LINK}'] = $unsubscribe;
			$sostituzioni['{ARCHIVE_LINK}'] = $archivio;
		}

		return str_replace(array_keys($sostituzioni), array_values($sostituzioni), (string) $text);
	}

	/**
	 * Pie di pagina configurato
	 *
	 * @param bool $is_html
	 * @return string
	 */
	protected function footer($is_html)
	{
		if ($this->footers === null)
		{
			$this->footers = $this->config_text->get_array(array('newsletter_footer_text', 'newsletter_footer_html'));
		}

		$footer = (string) $this->footers[$is_html ? 'newsletter_footer_html' : 'newsletter_footer_text'];

		// Campo vuoto significa "usa il predefinito", non "nessun pie di
		// pagina": un messaggio senza collegamento di disiscrizione e proprio
		// cio che fa finire un dominio nelle liste nere, e non puo essere il
		// comportamento di partenza di una installazione appena fatta
		if (trim($footer) === '')
		{
			$footer = $this->language->lang($is_html ? 'NL_DEFAULT_FOOTER_HTML' : 'NL_DEFAULT_FOOTER_TEXT');
		}

		if (trim($footer) === '')
		{
			return '';
		}

		// La sequenza "\n-- \n" e il separatore di firma previsto dagli
		// standard: i lettori la riconoscono e mostrano il pie in grigio
		return $is_html ? "\n" . $footer : "\n\n-- \n" . $footer;
	}

	/**
	 * Indirizzo di disiscrizione firmato, valido per un solo utente.
	 *
	 * La firma e un HMAC del numero utente con una chiave conservata nella
	 * configurazione. Cosi il collegamento funziona senza che l'utente sia
	 * collegato - ed e la sola cosa che conta, perche chi vuole andarsene non
	 * ha voglia di ricordare la parola d'ordine - e nessuno puo disiscrivere
	 * gli altri limitandosi a cambiare un numero nell'indirizzo.
	 *
	 * @param array $recipient
	 * @return string
	 */
	public function unsubscribe_url(array $recipient, $list_id = 0)
	{
		$user_id = isset($recipient['user_id']) ? (int) $recipient['user_id'] : 0;

		if ($user_id <= 0)
		{
			return $this->board_url() . '/ucp.' . $this->php_ext . '?i=ucp_prefs&mode=personal';
		}

		$list_id = (int) $list_id;

		// Con un notiziario indicato il collegamento toglie da quello soltanto;
		// senza, dalla newsletter per intero. Le due forme convivono, e la
		// seconda e la stessa che gira gia nelle caselle degli utenti
		if ($list_id > 0)
		{
			$token = self::list_token($this->secret(), $user_id, $list_id);
			$parametri = array('user_id' => $user_id, 'list_id' => $list_id, 'token' => $token);
			$rotta = 'salvocortesiano_newsletter_unsubscribe_list';
			$manuale = '/newsletter/unsubscribe/' . $user_id . '/' . $list_id . '/' . $token;
		}
		else
		{
			$token = self::token($this->secret(), $user_id);
			$parametri = array('user_id' => $user_id, 'token' => $token);
			$rotta = 'salvocortesiano_newsletter_unsubscribe';
			$manuale = '/newsletter/unsubscribe/' . $user_id . '/' . $token;
		}

		try
		{
			$url = $this->controller_helper->route($rotta, $parametri, false, false, UrlGeneratorInterface::ABSOLUTE_URL);
		}
		catch (\Exception $e)
		{
			// Il costruttore di rotte non e disponibile in ogni contesto:
			// meglio un indirizzo composto a mano che un invio interrotto
			$url = $this->board_url() . '/app.' . $this->php_ext . $manuale;
		}

		return self::strip_sid($url);
	}

	/**
	 * Indirizzo pubblico di un numero dell'archivio
	 *
	 * @param int $campaign_id
	 * @return string Vuoto se non costruibile
	 */
	public function archive_url($campaign_id)
	{
		$campaign_id = (int) $campaign_id;

		if ($campaign_id <= 0)
		{
			return '';
		}

		try
		{
			$url = $this->controller_helper->route(
				'salvocortesiano_newsletter_issue',
				array('campaign_id' => $campaign_id),
				false,
				false,
				UrlGeneratorInterface::ABSOLUTE_URL
			);
		}
		catch (\Exception $e)
		{
			// La forma storica resta valida e continua a rispondere: e il
			// ripiego giusto se il costruttore di rotte non e disponibile
			$url = $this->board_url() . '/app.' . $this->php_ext . '/newsletter/' . $campaign_id;
		}

		return self::strip_sid($url);
	}

	/**
	 * Firma di un collegamento di disiscrizione
	 *
	 * @param string $secret
	 * @param int    $user_id
	 * @return string
	 */
	public static function token($secret, $user_id)
	{
		return substr(hash_hmac('sha256', 'newsletter-unsubscribe-' . (int) $user_id, (string) $secret), 0, 32);
	}

	/**
	 * Firma per la disiscrizione da un solo notiziario.
	 *
	 * Il messaggio firmato porta anche il notiziario, altrimenti la stessa
	 * firma varrebbe per tutti e chi ha il collegamento di uno potrebbe
	 * togliersi anche dagli altri - o toglierci qualcun altro.
	 *
	 * La firma storica, quella senza notiziario, resta esattamente com'era:
	 * cambiarla avrebbe reso inutilizzabili i collegamenti dentro le email
	 * gia spedite.
	 *
	 * @param string $secret
	 * @param int    $user_id
	 * @param int    $list_id
	 * @return string
	 */
	public static function list_token($secret, $user_id, $list_id)
	{
		return substr(hash_hmac('sha256', 'newsletter-unsubscribe-' . (int) $user_id . '-' . (int) $list_id, (string) $secret), 0, 32);
	}

	/**
	 * Chiave di firma, generata alla prima richiesta
	 *
	 * @return string
	 */
	protected function secret()
	{
		$secret = (string) $this->config['newsletter_secret'];

		if ($secret === '')
		{
			$secret = bin2hex(random_bytes(24));
			$this->config->set('newsletter_secret', $secret);
		}

		return $secret;
	}

	/**
	 * Toglie l'identificativo di sessione da un indirizzo.
	 *
	 * Senza cookie phpBB lo aggiunge a ogni collegamento. In un messaggio di
	 * posta diventerebbe un dato di sessione distribuito a chiunque, e
	 * chiunque intercettasse il messaggio potrebbe usarlo per entrare.
	 *
	 * @param string $url
	 * @return string
	 */
	public static function strip_sid($url)
	{
		$url = preg_replace('#([?&])sid=[a-f0-9]+&?#i', '$1', (string) $url);

		return rtrim($url, '?&');
	}

	/**
	 * Corpo codificato in base64 su righe da 76 caratteri.
	 *
	 * La codifica non e una precauzione di troppo. phpbb_mail fa passare il
	 * messaggio da wordwrap() prima di consegnarlo, e smtpmail lo riscrive
	 * riga per riga: un corpo in chiaro con parole lunghe o caratteri a otto
	 * bit ne uscirebbe spezzato a meta di un tag. L'alfabeto base64 non
	 * contiene spazi ne il punto, quindi nessuna delle due funzioni ha
	 * appigli su cui intervenire.
	 *
	 * @param string $text
	 * @return string
	 */
	protected function encode_body($text)
	{
		return chunk_split(base64_encode($text), 76, $this->eol);
	}

	/**
	 * Codifica di una intestazione secondo RFC 2047, se necessaria
	 *
	 * @param string $text
	 * @return string
	 */
	protected function encode_header($text)
	{
		$text = (string) $text;

		if (preg_match('#^[\x20-\x7E]*$#', $text))
		{
			return $text;
		}

		return '=?UTF-8?B?' . base64_encode($text) . '?=';
	}

	/**
	 * Coppia nome e indirizzo pronta per una intestazione
	 *
	 * @param string $email
	 * @param string $name
	 * @return string
	 */
	protected function format_address($email, $name)
	{
		$name = trim((string) $name);

		if ($name === '')
		{
			return '<' . $email . '>';
		}

		$codificato = $this->encode_header($name);

		if ($codificato === $name)
		{
			// Le virgole e i due punti in un nome non codificato spezzerebbero
			// l'intestazione in due indirizzi distinti
			$codificato = '"' . str_replace(array('\\', '"'), array('\\\\', '\"'), $name) . '"';
		}

		return $codificato . ' <' . $email . '>';
	}

	/**
	 * Primo indirizzo valido fra quelli proposti
	 *
	 * @return string
	 */
	protected function pick_address()
	{
		foreach (func_get_args() as $candidato)
		{
			$candidato = trim((string) $candidato);

			if ($candidato !== '' && filter_var($candidato, FILTER_VALIDATE_EMAIL))
			{
				return $candidato;
			}
		}

		return 'noreply@' . $this->server_name();
	}

	/**
	 * Il dominio del destinatario accetta posta?
	 *
	 * @param string $address
	 * @return bool
	 */
	protected function domain_accepts_mail($address)
	{
		$dominio = substr(strrchr($address, '@'), 1);

		if ($dominio === false || $dominio === '')
		{
			return false;
		}

		if (!function_exists('checkdnsrr'))
		{
			return true;
		}

		// Un dominio senza MX ma con un record A riceve comunque posta, per la
		// regola di ripiego di RFC 5321
		return checkdnsrr($dominio, 'MX') || checkdnsrr($dominio, 'A');
	}

	/**
	 * @return string
	 */
	public function board_url()
	{
		if (function_exists('generate_board_url'))
		{
			return generate_board_url();
		}

		return 'https://' . $this->server_name();
	}

	/**
	 * @return string
	 */
	protected function server_name()
	{
		$nome = trim((string) $this->config['server_name']);

		return ($nome !== '') ? $nome : 'localhost';
	}

	/**
	 * Taglia una stringa senza spezzare un carattere multibyte
	 *
	 * @param string $testo
	 * @param int    $lunghezza
	 * @return string
	 */
	protected function truncate($testo, $lunghezza)
	{
		if (function_exists('utf8_substr'))
		{
			return utf8_substr($testo, 0, $lunghezza);
		}

		return mb_substr($testo, 0, $lunghezza, 'UTF-8');
	}

	/**
	 * @param int $priority
	 * @return string
	 */
	protected function priority_label($priority)
	{
		$etichette = array(1 => 'Highest', 2 => 'High', 3 => 'Normal', 4 => 'Low', 5 => 'Lowest');

		return isset($etichette[$priority]) ? $etichette[$priority] : 'Normal';
	}

	/**
	 * @param int $priority
	 * @return string
	 */
	protected function ms_priority_label($priority)
	{
		if ($priority <= 2)
		{
			return 'High';
		}

		return ($priority >= 4) ? 'Low' : 'Normal';
	}
}
