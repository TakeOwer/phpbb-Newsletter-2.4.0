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
 * Prova di invio con resoconto passo per passo.
 *
 * "Il messaggio non e partito" non aiuta nessuno: il guasto puo stare nel
 * nome del server, nella porta, nella cifratura, nelle credenziali o nel
 * destinatario, e ognuno di questi si corregge in un posto diverso. Qui la
 * connessione viene rifatta a mano, un passo alla volta, riportando quello che
 * il server di posta risponde davvero.
 *
 * La conversazione serve solo a diagnosticare: il messaggio vero parte poi
 * dalle funzioni di phpBB, come sempre.
 */
class diagnostics
{
	/** Stati di un passo */
	const OK = 'ok';
	const FAIL = 'fail';
	const WARN = 'warn';
	const SKIP = 'skip';

	/** Secondi concessi alla connessione e a ogni lettura */
	const TIMEOUT = 8;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \salvocortesiano\newsletter\core\manager */
	protected $manager;

	/** @var array */
	protected $passi = array();

	/**
	 * Constructor
	 */
	public function __construct(
		\phpbb\config\config $config,
		\phpbb\language\language $language,
		\salvocortesiano\newsletter\core\manager $manager
	)
	{
		$this->config = $config;
		$this->language = $language;
		$this->manager = $manager;
	}

	/**
	 * Esegue la prova completa
	 *
	 * @param string $destinatario
	 * @return array Elenco dei passi eseguiti
	 */
	public function run($destinatario)
	{
		$this->passi = array();

		$this->check_board_email();

		if (!empty($this->config['smtp_delivery']))
		{
			$this->check_smtp();
		}
		else
		{
			$this->step('NL_DIAG_STEP_METHOD', self::WARN, $this->language->lang('NL_DIAG_PHP_MAIL'));
		}

		$this->send_real($destinatario);

		return $this->passi;
	}

	/**
	 * Controlli che non richiedono la rete
	 */
	protected function check_board_email()
	{
		if (empty($this->config['email_enable']))
		{
			$this->step('NL_DIAG_STEP_ENABLED', self::FAIL, $this->language->lang('NL_DIAG_EMAIL_OFF'));
		}
		else
		{
			$this->step('NL_DIAG_STEP_ENABLED', self::OK, $this->language->lang('NL_DIAG_ON'));
		}

		$mittente = trim((string) $this->config['board_email']);

		if ($mittente === '' || !filter_var($mittente, FILTER_VALIDATE_EMAIL))
		{
			$this->step('NL_DIAG_STEP_SENDER', self::FAIL, $this->language->lang('NL_DIAG_SENDER_BAD', $mittente));
		}
		else
		{
			$this->step('NL_DIAG_STEP_SENDER', self::OK, $mittente);
		}
	}

	/**
	 * Conversazione con il server SMTP
	 */
	protected function check_smtp()
	{
		$host = trim((string) $this->config['smtp_host']);
		$porta = (int) $this->config['smtp_port'];

		if ($host === '')
		{
			$this->step('NL_DIAG_STEP_HOST', self::FAIL, $this->language->lang('NL_DIAG_NO_HOST'));
			return;
		}

		$this->step('NL_DIAG_STEP_METHOD', self::OK, 'SMTP ' . $host . ':' . $porta);

		$prefisso = '';

		if (preg_match('#^(ssl|tls)://#i', $host, $trovato))
		{
			$prefisso = strtolower($trovato[1]) . '://';
		}

		$nudo = preg_replace('#^(ssl|tls)://#i', '', $host);

		// Il nome del server deve esistere nel DNS: senza, ogni altra prova
		// fallirebbe per un motivo che non ha nulla a che vedere con la posta
		if (function_exists('gethostbyname'))
		{
			$ip = gethostbyname($nudo);

			if ($ip === $nudo && !filter_var($nudo, FILTER_VALIDATE_IP))
			{
				$this->step('NL_DIAG_STEP_DNS', self::FAIL, $this->language->lang('NL_DIAG_DNS_FAIL', $nudo));
				return;
			}

			$this->step('NL_DIAG_STEP_DNS', self::OK, $nudo . ' → ' . $ip);
		}

		if ($prefisso !== '' && !extension_loaded('openssl'))
		{
			$this->step('NL_DIAG_STEP_OPENSSL', self::FAIL, $this->language->lang('NL_DIAG_NO_OPENSSL'));
			return;
		}

		$esito = $this->probe($host, $porta);
		$this->report_probe('', $esito);

		// Se la connessione cosi com'e configurata non arriva a nulla e la
		// porta e una di quelle cifrate senza prefisso, si riprova con il
		// prefisso giusto. E la differenza fra "non funziona" e "non funziona,
		// ma ecco cosa scrivere perche funzioni"
		$suggerito = array(465 => 'ssl://', 587 => 'tls://');

		if (!$esito['handshake'] && $prefisso === '' && isset($suggerito[$porta]) && extension_loaded('openssl'))
		{
			$alternativo = $suggerito[$porta] . $nudo;
			$prova = $this->probe($alternativo, $porta);

			if ($prova['handshake'])
			{
				$this->step('NL_DIAG_STEP_ALTERNATIVE', self::OK, $this->language->lang('NL_DIAG_ALTERNATIVE_WORKS', $alternativo));
			}
			else
			{
				$this->report_probe('NL_DIAG_ALT_PREFIX', $prova, $alternativo);
			}
		}
	}

	/**
	 * Apre una connessione e scambia i primi comandi
	 *
	 * @param string $host
	 * @param int    $porta
	 * @return array
	 */
	protected function probe($host, $porta)
	{
		$esito = array(
			'connected'	=> false,
			'handshake'	=> false,
			'errno'		=> 0,
			'errstr'	=> '',
			'greeting'	=> '',
			'ehlo'		=> '',
			'starttls'	=> false,
			'auth'		=> '',
			'ms'		=> 0,
		);

		$inizio = microtime(true);

		$errno = 0;
		$errstr = '';

		$socket = @fsockopen($host, $porta, $errno, $errstr, self::TIMEOUT);

		$esito['ms'] = (int) round((microtime(true) - $inizio) * 1000);

		if (!$socket)
		{
			$esito['errno'] = (int) $errno;
			$esito['errstr'] = (string) $errstr;

			return $esito;
		}

		$esito['connected'] = true;

		stream_set_timeout($socket, self::TIMEOUT);

		$saluto = $this->read_line($socket);
		$esito['greeting'] = $saluto;

		// Un saluto vuoto su una porta cifrata significa quasi sempre che il
		// server sta aspettando la stretta di mano TLS che noi non abbiamo
		// avviato: resta li in attesa finche scade il tempo
		if ($saluto === '' || strpos($saluto, '220') !== 0)
		{
			@fclose($socket);

			return $esito;
		}

		$esito['handshake'] = true;

		$nome = trim((string) $this->config['server_name']);
		@fwrite($socket, 'EHLO ' . ($nome !== '' ? $nome : 'localhost') . "\r\n");

		$risposta = $this->read_multiline($socket);
		$esito['ehlo'] = $risposta;
		$esito['starttls'] = (stripos($risposta, 'STARTTLS') !== false);

		if (preg_match('#AUTH[ =]([A-Z0-9 \-]+)#i', $risposta, $trovato))
		{
			$esito['auth'] = trim($trovato[1]);
		}

		@fwrite($socket, "QUIT\r\n");
		@fclose($socket);

		return $esito;
	}

	/**
	 * Traduce l'esito di una connessione in passi leggibili
	 *
	 * @param string $chiave_extra
	 * @param array  $esito
	 * @param string $etichetta
	 */
	protected function report_probe($chiave_extra, array $esito, $etichetta = '')
	{
		$prefisso = ($etichetta !== '') ? $etichetta . ' — ' : '';

		if (!$esito['connected'])
		{
			$this->step('NL_DIAG_STEP_CONNECT', self::FAIL, $prefisso . $this->language->lang(
				'NL_DIAG_CONNECT_FAIL',
				$esito['errno'],
				$esito['errstr'] !== '' ? $esito['errstr'] : $this->language->lang('NL_DIAG_NO_DETAIL'),
				$esito['ms']
			));

			return;
		}

		$this->step('NL_DIAG_STEP_CONNECT', self::OK, $prefisso . $this->language->lang('NL_DIAG_CONNECT_OK', $esito['ms']));

		if (!$esito['handshake'])
		{
			$dettaglio = ($esito['greeting'] === '')
				? $this->language->lang('NL_DIAG_NO_GREETING', self::TIMEOUT)
				: $this->language->lang('NL_DIAG_BAD_GREETING', $esito['greeting']);

			$this->step('NL_DIAG_STEP_GREETING', self::FAIL, $prefisso . $dettaglio);

			return;
		}

		$this->step('NL_DIAG_STEP_GREETING', self::OK, $prefisso . $esito['greeting']);

		if ($esito['ehlo'] !== '')
		{
			$this->step('NL_DIAG_STEP_EHLO', self::OK, $esito['ehlo']);
		}

		if ($esito['auth'] !== '')
		{
			$this->step('NL_DIAG_STEP_AUTH', self::OK, $esito['auth']);
		}
		else
		{
			$this->step('NL_DIAG_STEP_AUTH', self::WARN, $this->language->lang('NL_DIAG_NO_AUTH'));
		}

		if ($esito['starttls'])
		{
			$this->step('NL_DIAG_STEP_STARTTLS', self::OK, $this->language->lang('NL_DIAG_STARTTLS_YES'));
		}
	}

	/**
	 * Invio vero, attraverso lo stesso percorso di una newsletter
	 *
	 * @param string $destinatario
	 */
	protected function send_real($destinatario)
	{
		if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL))
		{
			$this->step('NL_DIAG_STEP_SEND', self::FAIL, $this->language->lang('NL_DIAG_BAD_RECIPIENT', $destinatario));
			return;
		}

		$campagna = array(
			'campaign_id'			=> 0,
			'campaign_subject'		=> $this->language->lang('NL_DIAG_MAIL_SUBJECT'),
			'campaign_body'			=> $this->language->lang('NL_DIAG_MAIL_BODY', date('d/m/Y H:i:s')),
			'campaign_format'		=> 0,
			'campaign_banner'		=> 0,
			'campaign_topics'		=> '',
			'campaign_priority'		=> 3,
			'campaign_importance'	=> 'normal',
			'campaign_sensitivity'	=> '',
		);

		$errore = '';
		$inizio = microtime(true);

		$riuscito = $this->manager->send_test($destinatario, $campagna, $errore);

		$ms = (int) round((microtime(true) - $inizio) * 1000);

		if ($riuscito)
		{
			$this->step('NL_DIAG_STEP_SEND', self::OK, $this->language->lang('NL_DIAG_SEND_OK', $destinatario, $ms));
		}
		else
		{
			$this->step('NL_DIAG_STEP_SEND', self::FAIL, $this->language->lang('NL_DIAG_SEND_FAIL', $errore, $ms));
		}
	}

	/**
	 * Una riga di risposta
	 *
	 * @param resource $socket
	 * @return string
	 */
	protected function read_line($socket)
	{
		$riga = @fgets($socket, 1024);

		if ($riga === false)
		{
			return '';
		}

		$stato = stream_get_meta_data($socket);

		// Senza questo controllo una lettura scaduta sarebbe indistinguibile
		// da una risposta vuota, e il resoconto direbbe la cosa sbagliata
		if (!empty($stato['timed_out']))
		{
			return '';
		}

		return trim($riga);
	}

	/**
	 * Risposta su piu righe, come quella di EHLO
	 *
	 * @param resource $socket
	 * @return string
	 */
	protected function read_multiline($socket)
	{
		$righe = array();

		for ($i = 0; $i < 20; $i++)
		{
			$riga = $this->read_line($socket);

			if ($riga === '')
			{
				break;
			}

			$righe[] = $riga;

			// Il trattino dopo il codice annuncia che seguono altre righe; uno
			// spazio chiude la risposta
			if (!preg_match('#^\d{3}-#', $riga))
			{
				break;
			}
		}

		return implode(' | ', $righe);
	}

	/**
	 * @param string $chiave
	 * @param string $stato
	 * @param string $dettaglio
	 */
	protected function step($chiave, $stato, $dettaglio)
	{
		$this->passi[] = array(
			'label'		=> $this->language->lang($chiave),
			'status'	=> $stato,
			'detail'	=> (string) $dettaglio,
		);
	}
}
