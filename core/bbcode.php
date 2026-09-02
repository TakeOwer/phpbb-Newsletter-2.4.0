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
 * Conversione del BBCode, affidata al motore del forum.
 *
 * Non si riscrive un convertitore: phpBB ne ha gia uno, conosce tutti i tag
 * che gli amministratori hanno definito, gestisce liste annidate e citazioni
 * dentro citazioni, e segue le impostazioni del forum su faccine e
 * riconoscimento automatico degli indirizzi. Un convertitore scritto a mano
 * con espressioni regolari sarebbe piu corto e sbaglierebbe su tutti e tre
 * questi punti.
 *
 * Resta una cosa da fare noi: l'HTML che ne esce e pensato per una pagina del
 * forum, quindi porta percorsi relativi per faccine e immagini. Dentro un
 * messaggio di posta un percorso relativo non ha nulla a cui riferirsi.
 */
class bbcode
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\textformatter\parser_interface */
	protected $parser;

	/** @var \phpbb\textformatter\renderer_interface */
	protected $renderer;

	/** @var \phpbb\textformatter\utils_interface */
	protected $utils;

	/** @var \phpbb\language\language */
	protected $language;

	/**
	 * Constructor
	 */
	public function __construct(
		\phpbb\config\config $config,
		\phpbb\textformatter\parser_interface $parser,
		\phpbb\textformatter\renderer_interface $renderer,
		\phpbb\textformatter\utils_interface $utils,
		\phpbb\language\language $language
	)
	{
		$this->config = $config;
		$this->parser = $parser;
		$this->renderer = $renderer;
		$this->utils = $utils;
		$this->language = $language;
	}

	/**
	 * Prepara il testo per il salvataggio.
	 *
	 * @param string $testo
	 * @param array  $errori Riempito con i messaggi del motore, se ce ne sono
	 * @return string Testo nella forma conservata dal database
	 */
	public function to_storage($testo, array &$errori = array())
	{
		$errori = array();

		$testo = (string) $testo;

		if (trim($testo) === '')
		{
			return '';
		}

		$this->parser->enable_bbcodes();
		$this->parser->enable_magic_url(!empty($this->config['newsletter_bbcode_urls']));
		$this->parser->enable_smilies(!empty($this->config['newsletter_bbcode_smilies']));

		$risultato = $this->parser->parse($testo);

		foreach ($this->parser->get_errors() as $errore)
		{
			// Gli errori arrivano come chiave di lingua piu argomenti, nella
			// forma che usa il motore per i messaggi del forum
			$errori[] = is_array($errore)
				? call_user_func_array(array($this->language, 'lang'), $errore)
				: $this->language->lang($errore);
		}

		return $risultato;
	}

	/**
	 * Converte in HTML il testo conservato
	 *
	 * @param string $testo
	 * @return string
	 */
	public function to_html($testo)
	{
		$testo = (string) $testo;

		if (trim($testo) === '')
		{
			return '';
		}

		// Nessuna censura sulle parole: in un messaggio di posta la sostituzione
		// non serve a niente, perche il destinatario non ha impostazioni da
		// applicare e vedrebbe comunque gli asterischi di qualcun altro
		$this->renderer->set_viewcensors(true);
		$this->renderer->set_viewflash(false);
		$this->renderer->set_viewimg(true);
		$this->renderer->set_viewsmilies(!empty($this->config['newsletter_bbcode_smilies']));

		return $this->renderer->render($testo);
	}

	/**
	 * Riporta il testo alla forma scrivibile, per riprendere una bozza
	 *
	 * @param string $testo
	 * @return string
	 */
	public function to_edit($testo)
	{
		$testo = (string) $testo;

		if (trim($testo) === '')
		{
			return '';
		}

		return $this->utils->unparse($testo);
	}
}
