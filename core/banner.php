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
 * Immagine di intestazione dei messaggi in HTML.
 *
 * Il file viene conservato in una sottocartella nostra dentro images/ e non
 * direttamente in images/: quella cartella contiene le risorse di phpBB e
 * degli stili, e mescolarci dentro file caricati dal pannello significa
 * rischiare di sovrascrivere qualcosa e, alla cancellazione, di togliere un
 * file che non era nostro. Con una cartella dedicata la rimozione non puo
 * toccare nulla che non abbiamo messo noi.
 */
class banner
{
	/** Cartella di destinazione, relativa alla radice del forum */
	const FOLDER = 'images/newsletter';

	/** Dimensione massima accettata, in byte */
	const MAX_SIZE = 1048576;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\files\factory */
	protected $files_factory;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var string */
	protected $root_path;

	/**
	 * Constructor
	 */
	public function __construct(
		\phpbb\config\config $config,
		\phpbb\files\factory $files_factory,
		\phpbb\language\language $language,
		$root_path
	)
	{
		$this->config = $config;
		$this->files_factory = $files_factory;
		$this->language = $language;
		$this->root_path = $root_path;
	}

	/**
	 * Nome del file registrato in configurazione.
	 *
	 * Passa sempre da basename(): il valore finisce in percorsi e indirizzi, e
	 * anche se lo scrive solo il caricamento, un database manomesso non deve
	 * poter far leggere o cancellare file fuori dalla nostra cartella.
	 *
	 * @return string
	 */
	public function get_filename()
	{
		$nome = trim((string) $this->config['newsletter_banner']);

		return ($nome !== '') ? basename($nome) : '';
	}

	/**
	 * @return bool
	 */
	public function exists()
	{
		$nome = $this->get_filename();

		return ($nome !== '' && file_exists($this->path()));
	}

	/**
	 * Percorso sul disco
	 *
	 * @return string
	 */
	public function path()
	{
		return $this->root_path . self::FOLDER . '/' . $this->get_filename();
	}

	/**
	 * Indirizzo assoluto, quello che finisce nel messaggio di posta.
	 *
	 * Deve essere assoluto: un percorso relativo dentro una email non ha nulla
	 * a cui riferirsi e l'immagine non comparirebbe da nessuna parte.
	 *
	 * @return string
	 */
	public function url()
	{
		if (!$this->exists())
		{
			return '';
		}

		$base = function_exists('generate_board_url') ? generate_board_url() : '';

		return $base . '/' . self::FOLDER . '/' . rawurlencode($this->get_filename());
	}

	/**
	 * Indirizzo utilizzabile dentro il pannello di amministrazione
	 *
	 * @return string
	 */
	public function preview_url()
	{
		if (!$this->exists())
		{
			return '';
		}

		// Il numero finale costringe il browser a rileggere il file dopo una
		// sostituzione, invece di mostrare quello vecchio tenuto in memoria
		return $this->root_path . self::FOLDER . '/' . rawurlencode($this->get_filename())
			. '?v=' . (int) @filemtime($this->path());
	}

	/**
	 * Larghezza e altezza dell'immagine
	 *
	 * @return array
	 */
	public function dimensions()
	{
		if (!$this->exists() || !function_exists('getimagesize'))
		{
			return array(0, 0);
		}

		$misure = @getimagesize($this->path());

		return $misure ? array((int) $misure[0], (int) $misure[1]) : array(0, 0);
	}

	/**
	 * Peso del file in kilobyte
	 *
	 * @return int
	 */
	public function filesize()
	{
		return $this->exists() ? (int) round(@filesize($this->path()) / 1024) : 0;
	}

	/**
	 * Indirizzo a cui porta il banner quando viene toccato
	 *
	 * @return string
	 */
	public function link()
	{
		$link = trim((string) $this->config['newsletter_banner_link']);

		if ($link !== '')
		{
			return $link;
		}

		return function_exists('generate_board_url') ? generate_board_url() : '';
	}

	/**
	 * Blocco HTML da anteporre al corpo del messaggio.
	 *
	 * Lo stile e scritto direttamente sugli elementi e non affidato al foglio
	 * di stile: questo blocco deve funzionare anche se l'amministratore ha
	 * sostituito il foglio con uno tutto suo. L'attributo border a zero serve
	 * per i lettori che disegnano ancora una cornice attorno alle immagini
	 * dentro un collegamento.
	 *
	 * @return string
	 */
	public function html()
	{
		$indirizzo = $this->url();

		if ($indirizzo === '')
		{
			return '';
		}

		$titolo = html_entity_decode((string) $this->config['sitename'], ENT_COMPAT, 'UTF-8');
		$titolo = htmlspecialchars($titolo, ENT_COMPAT, 'UTF-8');

		$immagine = '<img src="' . htmlspecialchars($indirizzo, ENT_COMPAT, 'UTF-8') . '"'
			. ' alt="' . $titolo . '" title="' . $titolo . '"'
			. ' style="display: block; width: 100%; max-width: 700px; height: auto; border: 0; margin: 0 auto;" />';

		$link = $this->link();

		if ($link !== '')
		{
			$immagine = '<a href="' . htmlspecialchars($link, ENT_COMPAT, 'UTF-8') . '" style="text-decoration: none; border: 0;">'
				. $immagine . '</a>';
		}

		return '<div class="nl-banner" style="text-align: center; margin: 0 0 18px 0;">' . $immagine . '</div>';
	}

	/**
	 * Riceve un file dal modulo di composizione.
	 *
	 * La verifica e affidata al componente di caricamento di phpBB, non a
	 * controlli scritti a mano: quello sa gia che l'estensione del nome non
	 * dice nulla sul contenuto, e apre davvero il file per accertarsi che sia
	 * una immagine. Un controllo casalingo sarebbe piu corto e piu fragile.
	 *
	 * @param string $form_name Nome del campo del modulo
	 * @param string $error
	 * @return bool
	 */
	public function upload($form_name, &$error = '')
	{
		$error = '';

		$cartella = $this->root_path . self::FOLDER;

		if (!is_dir($cartella) && !@mkdir($cartella, 0755, true))
		{
			$error = $this->language->lang('NL_BANNER_NO_DIR', self::FOLDER);
			return false;
		}

		if (!is_writable($cartella))
		{
			$error = $this->language->lang('NL_BANNER_NO_WRITE', self::FOLDER);
			return false;
		}

		/** @var \phpbb\files\upload $upload */
		$upload = $this->files_factory->get('files.upload')
			->set_allowed_extensions(array('jpg', 'jpeg', 'png', 'gif'))
			->set_max_filesize(self::MAX_SIZE);

		$file = $upload->handle_upload('files.types.form', $form_name);

		if (!empty($file->error))
		{
			$error = implode(' ', $file->error);
			$file->remove();

			return false;
		}

		// Il nome scelto da chi carica non viene mai riusato: un nome unico
		// generato qui elimina in un colpo solo le collisioni fra file diversi
		// e i nomi costruiti apposta per uscire dalla cartella
		$file->clean_filename('unique_ext', 'nl_banner_');

		// Le misure si controllano ora, sul file temporaneo, e non dopo lo
		// spostamento: un banner alto come una fotografia riempirebbe l'intera
		// prima schermata del messaggio e spingerebbe il testo sotto la piega,
		// dove non lo legge nessuno. Meglio rifiutarlo prima che finisca nella
		// cartella pubblica
		if (!$this->check_dimensions($file->get('filename'), $error))
		{
			$file->remove();

			return false;
		}

		$precedente = $this->get_filename();

		$file->move_file(self::FOLDER, true);

		if (!empty($file->error))
		{
			$error = implode(' ', $file->error);
			$file->remove();

			return false;
		}

		$nuovo = basename((string) $file->get('realname'));

		// Il vecchio si toglie solo dopo che il nuovo e al suo posto: se il
		// caricamento fallisse a meta, restare senza banner sarebbe peggio che
		// tenersi quello di prima
		if ($precedente !== '' && $precedente !== $nuovo)
		{
			@unlink($this->root_path . self::FOLDER . '/' . $precedente);
		}

		$this->config->set('newsletter_banner', $nuovo);

		return true;
	}

	/**
	 * Cartella radice esplorabile, relativa alla radice del forum
	 */
	const BROWSE_ROOT = 'images';

	/** Voci mostrate al massimo in una cartella */
	const BROWSE_LIMIT = 500;

	/**
	 * Percorso reale di una sottocartella o di un file dentro images/.
	 *
	 * realpath risolve da solo i ".." e i collegamenti simbolici; il confronto
	 * che segue verifica che il risultato stia davvero dentro images/. Senza
	 * questi due passaggi un percorso costruito a mano potrebbe far leggere -
	 * o peggio copiare come banner - qualunque file del server.
	 *
	 * @param string $relativo
	 * @return string|false
	 */
	protected function safe_path($relativo)
	{
		$base = realpath($this->root_path . self::BROWSE_ROOT);

		if ($base === false)
		{
			return false;
		}

		$relativo = trim(str_replace('\\', '/', (string) $relativo), '/');
		$obiettivo = realpath($base . ($relativo !== '' ? '/' . $relativo : ''));

		if ($obiettivo === false)
		{
			return false;
		}

		if ($obiettivo !== $base && strpos($obiettivo, $base . DIRECTORY_SEPARATOR) !== 0)
		{
			return false;
		}

		return $obiettivo;
	}

	/**
	 * Contenuto di una cartella dentro images/.
	 *
	 * Cartelle e immagini separate, ordinate per nome. Le dimensioni delle
	 * immagini non vengono calcolate qui: su una cartella con centinaia di file
	 * significherebbe aprirli tutti, e il browser puo leggerle da solo quando
	 * ne mostra una in anteprima.
	 *
	 * @param string $relativo
	 * @return array|false
	 */
	public function browse($relativo = '')
	{
		$cartella = $this->safe_path($relativo);

		if ($cartella === false || !is_dir($cartella))
		{
			return false;
		}

		$base = realpath($this->root_path . self::BROWSE_ROOT);
		$dentro = ($cartella === $base) ? '' : ltrim(str_replace('\\', '/', substr($cartella, strlen($base))), '/');

		$voci = @scandir($cartella);

		if ($voci === false)
		{
			return false;
		}

		$cartelle = array();
		$file = array();
		$troncato = false;

		foreach ($voci as $voce)
		{
			if ($voce === '.' || $voce === '..' || $voce[0] === '.')
			{
				continue;
			}

			if (count($cartelle) + count($file) >= self::BROWSE_LIMIT)
			{
				$troncato = true;
				break;
			}

			$percorso = $cartella . DIRECTORY_SEPARATOR . $voce;
			$rel = ($dentro !== '' ? $dentro . '/' : '') . $voce;

			if (is_dir($percorso))
			{
				$cartelle[] = array('name' => $voce, 'path' => $rel);
				continue;
			}

			$estensione = strtolower(pathinfo($voce, PATHINFO_EXTENSION));

			if (!in_array($estensione, array('jpg', 'jpeg', 'png', 'gif'), true))
			{
				continue;
			}

			$file[] = array(
				'name'	=> $voce,
				'path'	=> $rel,
				'url'	=> $this->root_path . self::BROWSE_ROOT . '/' . str_replace('%2F', '/', rawurlencode($rel)),
				'size'	=> (int) round((int) @filesize($percorso) / 1024),
			);
		}

		usort($cartelle, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
		usort($file, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

		return array(
			'path'		=> $dentro,
			'dirs'		=> $cartelle,
			'files'		=> $file,
			'truncated'	=> $troncato,
		);
	}

	/**
	 * Adotta come banner una immagine gia presente in images/.
	 *
	 * Il file viene copiato nella nostra cartella invece di essere usato dove
	 * si trova. Costa lo spazio di una immagine, ma in cambio il pulsante
	 * "Elimina" continua a lavorare solo dentro casa nostra: puntando
	 * all'originale, una disinstallazione o una cancellazione distratta
	 * porterebbe via un file che appartiene al forum e non a noi.
	 *
	 * @param string $relativo
	 * @param string $error
	 * @return bool
	 */
	public function adopt($relativo, &$error = '')
	{
		$error = '';

		$sorgente = $this->safe_path($relativo);

		if ($sorgente === false || !is_file($sorgente))
		{
			$error = $this->language->lang('NL_BANNER_PICK_NOT_FOUND');
			return false;
		}

		$estensione = strtolower(pathinfo($sorgente, PATHINFO_EXTENSION));

		if (!in_array($estensione, array('jpg', 'jpeg', 'png', 'gif'), true))
		{
			$error = $this->language->lang('NL_BANNER_PICK_NOT_IMAGE');
			return false;
		}

		if (!$this->check_dimensions($sorgente, $error))
		{
			return false;
		}

		$cartella = $this->root_path . self::FOLDER;

		if (!is_dir($cartella) && !@mkdir($cartella, 0755, true))
		{
			$error = $this->language->lang('NL_BANNER_NO_DIR', self::FOLDER);
			return false;
		}

		if (!is_writable($cartella))
		{
			$error = $this->language->lang('NL_BANNER_NO_WRITE', self::FOLDER);
			return false;
		}

		$nuovo = 'nl_banner_' . md5(uniqid((string) mt_rand(), true)) . '.' . $estensione;

		if (!@copy($sorgente, $cartella . '/' . $nuovo))
		{
			$error = $this->language->lang('NL_BANNER_PICK_COPY_FAILED');
			return false;
		}

		@chmod($cartella . '/' . $nuovo, 0644);

		$precedente = $this->get_filename();

		if ($precedente !== '' && $precedente !== $nuovo)
		{
			@unlink($cartella . '/' . $precedente);
		}

		$this->config->set('newsletter_banner', $nuovo);

		return true;
	}

	/**
	 * L'immagine ha le proporzioni di un banner?
	 *
	 * @param string $percorso File da esaminare
	 * @param string $error
	 * @return bool
	 */
	protected function check_dimensions($percorso, &$error)
	{
		$misure = @getimagesize($percorso);

		if (!$misure || empty($misure[0]) || empty($misure[1]))
		{
			$error = $this->language->lang('NL_BANNER_NOT_IMAGE');
			return false;
		}

		$larghezza = (int) $misure[0];
		$altezza = (int) $misure[1];

		$min_h = $this->min_height();
		$max_h = $this->max_height();

		if ($altezza < $min_h || $altezza > $max_h)
		{
			$error = $this->language->lang('NL_BANNER_BAD_HEIGHT', $larghezza, $altezza, $min_h, $max_h);
			return false;
		}

		$min_w = $this->min_width();
		$max_w = $this->max_width();

		if ($larghezza < $min_w || $larghezza > $max_w)
		{
			$error = $this->language->lang('NL_BANNER_BAD_WIDTH', $larghezza, $altezza, $min_w, $max_w);
			return false;
		}

		return true;
	}

	/**
	 * Limiti dimensionali.
	 *
	 * Quando la voce di configurazione non c'e ancora - l'estensione e stata
	 * aggiornata sostituendo i file ma non e stata riabilitata, quindi la
	 * migrazione non e passata - si usa il valore predefinito invece di zero.
	 * Senza questo ripiego l'intervallo ammesso diventerebbe da 20 a 20 pixel e
	 * nessuna immagine potrebbe piu essere caricata.
	 *
	 * @return int
	 */
	public function min_height()
	{
		return $this->limit('newsletter_banner_min_height', 200);
	}

	/**
	 * @return int
	 */
	public function max_height()
	{
		return max($this->min_height(), $this->limit('newsletter_banner_max_height', 260));
	}

	/**
	 * @return int
	 */
	public function min_width()
	{
		return $this->limit('newsletter_banner_min_width', 600);
	}

	/**
	 * @return int
	 */
	public function max_width()
	{
		return max($this->min_width(), $this->limit('newsletter_banner_max_width', 2600));
	}

	/**
	 * @param string $chiave
	 * @param int    $predefinito
	 * @return int
	 */
	protected function limit($chiave, $predefinito)
	{
		$valore = isset($this->config[$chiave]) ? (int) $this->config[$chiave] : 0;

		return ($valore > 0) ? $valore : $predefinito;
	}

	/**
	 * Toglie il banner dal disco e dalla configurazione
	 *
	 * @return bool
	 */
	public function remove()
	{
		$nome = $this->get_filename();

		if ($nome === '')
		{
			return false;
		}

		$percorso = $this->root_path . self::FOLDER . '/' . $nome;

		if (file_exists($percorso))
		{
			@unlink($percorso);
		}

		// La configurazione si azzera comunque: se il file era gia sparito da
		// solo, lasciare il nome scritto significherebbe cercare in eterno una
		// immagine che non c'e
		$this->config->set('newsletter_banner', '');

		return true;
	}

	/**
	 * Dimensione massima, in kilobyte, per la spiegazione a schermo
	 *
	 * @return int
	 */
	public function max_size_kb()
	{
		return (int) (self::MAX_SIZE / 1024);
	}
}
