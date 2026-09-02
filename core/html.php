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
 * Trasformazioni su testo e HTML.
 *
 * Nessuna dipendenza: la classe riceve stringhe e restituisce stringhe. Questo
 * la rende usabile allo stesso modo dal modulo di amministrazione, che deve
 * mostrare un'anteprima dentro la pagina del pannello, e dal componente che
 * spedisce, che deve produrre il corpo del messaggio.
 */
class html
{
	/**
	 * Foglio di stile usato quando non ne e stato impostato nessuno.
	 *
	 * Regole volutamente essenziali e tutte supportate dai lettori di posta:
	 * niente flexbox, niente grid, niente proprieta che Outlook ignora. Il
	 * risultato non e vistoso, ma arriva leggibile dappertutto, che e cio che
	 * conta in un messaggio di posta.
	 *
	 * @return string
	 */
	public function default_css()
	{
		return 'body { margin: 0; padding: 0; background: #f4f4f4; }' . "\n"
			. 'p { margin: 0 0 14px 0; line-height: 1.6; }' . "\n"
			. 'h1 { margin: 0 0 16px 0; font-size: 22px; line-height: 1.3; color: #1c1c1c; }' . "\n"
			. 'h2 { margin: 24px 0 12px 0; font-size: 18px; line-height: 1.3; color: #1c1c1c; }' . "\n"
			. 'h3 { margin: 20px 0 10px 0; font-size: 15px; color: #1c1c1c; }' . "\n"
			. 'a { color: #0f5d9d; }' . "\n"
			. 'ul { margin: 0 0 14px 0; padding-left: 22px; }' . "\n"
			. 'li { margin-bottom: 6px; line-height: 1.5; }' . "\n"
			. 'hr { border: 0; border-top: 1px solid #dddddd; margin: 22px 0; }' . "\n"
			. 'img { max-width: 100%; height: auto; }' . "\n"
			. 'blockquote { margin: 0 0 14px 0; padding: 10px 14px; background: #f0f0f0; border-left: 3px solid #cccccc; }' . "\n"
			// Il BBCode produce citazioni e blocchi di codice con le classi di
			// phpBB: senza queste regole arriverebbero come testo indistinto
			. 'blockquote cite { display: block; margin-bottom: 6px; font-style: normal; font-weight: bold; font-size: 12px; color: #666666; }' . "\n"
			. '.codebox { margin: 0 0 14px 0; padding: 10px 12px; background: #f4f4f4; border: 1px solid #dddddd; font-size: 12px; }' . "\n"
			. '.codebox p { margin: 0 0 6px 0; font-weight: bold; font-size: 11px; color: #666666; }' . "\n"
			. '.codebox code { display: block; font-family: Consolas, Monaco, monospace; white-space: pre-wrap; }' . "\n"
			. 'img.smilies { vertical-align: middle; border: 0; }' . "\n"
			. '.nl-topics { margin-top: 24px; padding-top: 14px; border-top: 1px solid #dddddd; }' . "\n"
			. '.nl-footer { margin-top: 26px; padding-top: 14px; border-top: 1px solid #dddddd; font-size: 12px; color: #777777; }' . "\n"
			. '.nl-footer a { color: #777777; }';
	}

	/**
	 * Toglie dall'HTML tutto cio che puo eseguire qualcosa.
	 *
	 * Non serve a difendersi dall'amministratore, che ha gia le chiavi di
	 * casa. Serve per l'anteprima: quel frammento viene reso dentro una pagina
	 * del pannello, con la sessione di un fondatore aperta, e un frammento
	 * incollato da chissa dove non deve poter eseguire nulla in quel contesto.
	 * Nei messaggi di posta la stessa pulizia evita che i filtri antispam
	 * scartino il messaggio per la sola presenza di uno script.
	 *
	 * @param string $html
	 * @return string
	 */
	public function sanitize($html)
	{
		$html = (string) $html;

		if ($html === '')
		{
			return '';
		}

		$pericolosi = 'script|iframe|frame|frameset|object|embed|applet|form|button|input|textarea|select|option|meta|base|link';

		// Prima gli elementi con contenuto, poi quelli che si chiudono da soli:
		// invertendo l'ordine resterebbero orfani i tag di chiusura
		$html = preg_replace('#<(' . $pericolosi . ')\b[^>]*>.*?</\1\s*>#is', '', $html);
		$html = preg_replace('#<(' . $pericolosi . ')\b[^>]*/?>#is', '', $html);

		// Gestori di evento: onclick, onload, onerror e compagnia
		$html = preg_replace('#\s+on[a-z0-9_-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);

		// Indirizzi che eseguono codice invece di puntare a una risorsa
		$html = preg_replace('#\s+(href|src|action|formaction|background)\s*=\s*(["\'])\s*(?:javascript|vbscript|data\s*:\s*text/html)[^"\']*\2#i', '', $html);

		return $html;
	}

	/**
	 * Ripulisce un foglio di stile.
	 *
	 * @param string $css
	 * @return string
	 */
	public function sanitize_css($css)
	{
		$css = (string) $css;

		if ($css === '')
		{
			return '';
		}

		// Un </style> nel mezzo del foglio chiuderebbe l'elemento e farebbe
		// finire il resto nel corpo della pagina come marcatura viva
		$css = preg_replace('#</?style\b[^>]*>#i', '', $css);
		$css = preg_replace('#@import\s+[^;]+;?#i', '', $css);
		$css = preg_replace('#expression\s*\([^)]*\)#i', '', $css);
		$css = preg_replace('#url\s*\(\s*(["\']?)\s*(?:javascript|vbscript|data\s*:\s*text/html)[^)]*\)#i', 'none', $css);

		return $css;
	}

	/**
	 * Riscrive le regole di un foglio di stile dentro gli attributi style.
	 *
	 * Questo passaggio non e un abbellimento. Gmail, Outlook sul web e la
	 * maggior parte dei lettori di posta scartano il blocco <style> di un
	 * messaggio: un testo formattato con classi arriva senza alcuna
	 * formattazione. L'unico stile che sopravvive dappertutto e quello scritto
	 * direttamente sull'elemento, ed e cio che questo metodo produce.
	 *
	 * Il foglio originale viene comunque lasciato nel documento: i pochi
	 * lettori che lo leggono ne traggono le regole che qui non si sanno
	 * tradurre, come le media query.
	 *
	 * @param string $html
	 * @param string $css
	 * @return string
	 */
	public function inline_css($html, $css)
	{
		$html = (string) $html;
		$css = (string) $css;

		if ($html === '' || trim($css) === '' || !class_exists('\DOMDocument'))
		{
			return $html;
		}

		$regole = $this->extract_rules($css);

		if (empty($regole))
		{
			return $html;
		}

		$documento = new \DOMDocument('1.0', 'UTF-8');

		// libxml protesta per ogni tag che non conosce, e la marcatura dei
		// messaggi di posta ne e piena: gli errori vanno raccolti e ignorati,
		// non lasciati emergere come avvisi in cima alla pagina
		$precedente = libxml_use_internal_errors(true);

		$caricato = $documento->loadHTML(
			'<?xml encoding="UTF-8"><div id="nl-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors($precedente);

		if (!$caricato)
		{
			return $html;
		}

		$xpath = new \DOMXPath($documento);

		foreach ($regole as $selettore => $dichiarazioni)
		{
			$query = $this->selector_to_xpath($selettore);

			if ($query === '')
			{
				continue;
			}

			$nodi = $xpath->query($query);

			if (!$nodi)
			{
				continue;
			}

			foreach ($nodi as $nodo)
			{
				if (!$nodo instanceof \DOMElement || $nodo->getAttribute('id') === 'nl-root')
				{
					continue;
				}

				// Lo stile gia presente sull'elemento viene prima: e piu
				// specifico di una regola del foglio e deve restare tale
				$esistente = trim($nodo->getAttribute('style'));

				$nodo->setAttribute('style', trim($dichiarazioni . ($esistente !== '' ? ' ' . rtrim($esistente, ';') . ';' : '')));
			}
		}

		// getElementById funziona anche sui documenti HTML, ma dipende da come
		// libxml e stato compilato: XPath da lo stesso risultato ovunque
		$radici = $xpath->query('//*[@id="nl-root"]');

		if (!$radici || !$radici->length)
		{
			return $html;
		}

		$radice = $radici->item(0);

		$risultato = '';

		foreach ($radice->childNodes as $figlio)
		{
			$risultato .= $documento->saveHTML($figlio);
		}

		return $risultato;
	}

	/**
	 * Estrae le coppie selettore/dichiarazioni da un foglio di stile
	 *
	 * @param string $css
	 * @return array
	 */
	protected function extract_rules($css)
	{
		$regole = array();

		$css = preg_replace('#/\*.*?\*/#s', '', (string) $css);

		// Le regole annidate - @media e simili - non entrano nell'espressione
		// perche il loro contenuto ha graffe proprie. E corretto cosi: una
		// media query non ha senso come attributo style di un elemento
		if (!preg_match_all('#([^{}@]+)\{([^{}]+)\}#', $css, $trovate, PREG_SET_ORDER))
		{
			return $regole;
		}

		foreach ($trovate as $trovata)
		{
			$dichiarazioni = trim(preg_replace('/\s+/', ' ', $trovata[2]));

			if ($dichiarazioni === '')
			{
				continue;
			}

			$dichiarazioni = rtrim($dichiarazioni, ';') . ';';

			foreach (array_map('trim', explode(',', $trovata[1])) as $selettore)
			{
				if ($selettore === '')
				{
					continue;
				}

				$regole[$selettore] = isset($regole[$selettore])
					? $regole[$selettore] . ' ' . $dichiarazioni
					: $dichiarazioni;
			}
		}

		return $regole;
	}

	/**
	 * Traduce un selettore CSS semplice in una espressione XPath.
	 *
	 * Si gestiscono soltanto tag, classi, identificativi e discendenza. I
	 * selettori con combinatori o pseudo-classi vengono lasciati cadere invece
	 * di essere tradotti male: una regola non applicata si nota e si corregge,
	 * una regola applicata all'elemento sbagliato no.
	 *
	 * @param string $selettore
	 * @return string
	 */
	protected function selector_to_xpath($selettore)
	{
		$selettore = trim((string) $selettore);

		if ($selettore === '' || preg_match('#[>+~:\[\]*]#', $selettore))
		{
			return '';
		}

		$xpath = '//*[@id="nl-root"]';

		foreach (preg_split('/\s+/', $selettore) as $pezzo)
		{
			$pezzo = trim($pezzo);

			if ($pezzo === '')
			{
				continue;
			}

			$xpath .= '//' . $this->simple_selector_to_xpath($pezzo);
		}

		return $xpath;
	}

	/**
	 * @param string $pezzo
	 * @return string
	 */
	protected function simple_selector_to_xpath($pezzo)
	{
		$tag = '*';
		$condizioni = array();

		if (preg_match('/^([a-zA-Z][a-zA-Z0-9_-]*)/', $pezzo, $trovato))
		{
			$tag = strtolower($trovato[1]);
		}

		if (preg_match('/#([a-zA-Z0-9_-]+)/', $pezzo, $trovato))
		{
			$condizioni[] = '@id="' . $trovato[1] . '"';
		}

		if (preg_match_all('/\.([a-zA-Z0-9_-]+)/', $pezzo, $trovate))
		{
			foreach ($trovate[1] as $classe)
			{
				// Il confronto con gli spazi attorno evita che la classe
				// "titolo" corrisponda anche a "sottotitolo"
				$condizioni[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . $classe . ' ")';
			}
		}

		return $tag . (!empty($condizioni) ? '[' . implode(' and ', $condizioni) . ']' : '');
	}

	/**
	 * Aggiunge target e rel ai collegamenti
	 *
	 * @param string $html
	 * @return string
	 */
	public function safe_links($html)
	{
		$html = (string) $html;

		if ($html === '' || stripos($html, '<a') === false)
		{
			return $html;
		}

		return preg_replace_callback('#<a\b([^>]*)>#i', function ($trovato) {
			$attributi = $trovato[1];

			if (!preg_match('#\s(?:target|download)\s*=#i', $attributi))
			{
				$attributi .= ' target="_blank"';
			}

			if (!preg_match('#\srel\s*=#i', $attributi))
			{
				$attributi .= ' rel="noopener noreferrer"';
			}

			return '<a' . $attributi . '>';
		}, $html);
	}

	/**
	 * Trasforma in assoluti i percorsi relativi dell'HTML.
	 *
	 * L'HTML che il forum produce per una pagina porta percorsi relativi:
	 * "./images/smilies/icon_e_smile.gif", "./download/file.php?id=12". Dentro
	 * una pagina web funzionano perche il browser sa da dove viene il
	 * documento; dentro un messaggio di posta non c'e nessun documento di
	 * partenza, e quelle immagini semplicemente non compaiono.
	 *
	 * @param string $html
	 * @param string $board_url Indirizzo del forum, senza barra finale
	 * @return string
	 */
	public function absolutise($html, $board_url)
	{
		$html = (string) $html;
		$board_url = rtrim((string) $board_url, '/');

		if ($html === '' || $board_url === '')
		{
			return $html;
		}

		return preg_replace_callback(
			'#\s(src|href)\s*=\s*(["\'])([^"\']*)\2#i',
			function ($trovato) use ($board_url) {
				$attributo = $trovato[1];
				$virgoletta = $trovato[2];
				$percorso = trim($trovato[3]);

				// Gia assoluto, oppure uno schema che non punta a un file:
				// mailto, tel, ancore interne, immagini incorporate
				if ($percorso === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|\#)#i', $percorso))
				{
					return $trovato[0];
				}

				// I percorsi del forum cominciano quasi sempre con ./ o ../, a
				// volte mescolati fra loro come "./../images". Vanno tolti
				// tutti, non solo il primo, prima di attaccarli alla radice
				$percorso = preg_replace('#^(?:\.{1,2}/)+#', '', $percorso);

				return ' ' . $attributo . '=' . $virgoletta . $board_url . '/' . ltrim($percorso, '/') . $virgoletta;
			},
			$html
		);
	}

	/**
	 * Racchiude un frammento in un documento HTML completo.
	 *
	 * Un messaggio che comincia direttamente con un paragrafo viene
	 * interpretato da alcuni lettori con la codifica predefinita del sistema, e
	 * le lettere accentate diventano segni senza senso. La dichiarazione del
	 * set di caratteri va ripetuta qui anche quando la stessa informazione e
	 * gia nelle intestazioni MIME.
	 *
	 * @param string $body
	 * @param string $subject
	 * @param string $css
	 * @return string
	 */
	public function wrap_document($body, $subject, $css = '')
	{
		$body = (string) $body;

		if (stripos($body, '<html') !== false)
		{
			return $body;
		}

		$stile = (trim($css) !== '') ? '<style type="text/css">' . "\n" . $css . "\n" . '</style>' . "\n" : '';

		return '<!DOCTYPE html>' . "\n"
			. '<html><head>' . "\n"
			. '<meta charset="UTF-8" />' . "\n"
			. '<meta name="viewport" content="width=device-width, initial-scale=1.0" />' . "\n"
			. '<title>' . htmlspecialchars((string) $subject, ENT_COMPAT, 'UTF-8') . '</title>' . "\n"
			. $stile
			. '</head>' . "\n"
			. '<body style="margin:0; padding:16px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.5; color:#333333;">' . "\n"
			. $body . "\n"
			. '</body></html>';
	}

	/**
	 * Versione testuale di un messaggio HTML.
	 *
	 * Serve come parte alternativa del messaggio: senza, i lettori in modalita
	 * solo testo e diversi filtri antispam vedrebbero un corpo vuoto.
	 *
	 * @param string $html
	 * @return string
	 */
	public function to_text($html)
	{
		// Il contenuto di head - titolo compreso - non appartiene al messaggio
		$testo = preg_replace('#<head\b[^>]*>.*?</head\s*>#is', '', (string) $html);
		$testo = preg_replace('#<(script|style|title)[^>]*>.*?</\1>#is', '', $testo);
		$testo = preg_replace('#<br\s*/?>#i', "\n", $testo);
		$testo = preg_replace('#</(p|div|h[1-6]|li|tr|blockquote)>#i', "\n", $testo);
		$testo = preg_replace('#<li[^>]*>#i', '- ', $testo);
		// Una immagine, tolta la marcatura, non lascerebbe nulla: una faccina
		// scritta come :risata: sparirebbe dalla versione testuale invece di
		// tornare al suo codice. Il testo alternativo la riporta, ed e proprio
		// il codice di partenza per le faccine del forum
		$testo = preg_replace_callback('#<img\b[^>]*>#i', function ($trovato) {
			if (preg_match('#\balt\s*=\s*(["\'])(.*?)\1#i', $trovato[0], $alt))
			{
				$etichetta = trim($alt[2]);

				if ($etichetta !== '')
				{
					return $etichetta;
				}
			}

			return '';
		}, $testo);

		$testo = preg_replace('#<hr[^>]*>#i', "\n" . str_repeat('-', 40) . "\n", $testo);

		// Un collegamento perde ogni utilita se ne sopravvive solo il testo:
		// l'indirizzo viene riportato fra parentesi, come nei lettori testuali
		$testo = preg_replace_callback('#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function ($trovato) {
			$etichetta = trim(strip_tags($trovato[3]));
			$indirizzo = trim($trovato[2]);

			// Un collegamento che avvolge solo una immagine resta senza testo
			// una volta tolta la marcatura: riportarne l'indirizzo lascerebbe
			// un URL nudo in cima al messaggio, che nella versione testuale e
			// soltanto rumore
			if ($etichetta === '')
			{
				return '';
			}

			if ($etichetta === $indirizzo)
			{
				return $indirizzo;
			}

			return $etichetta . ' (' . $indirizzo . ')';
		}, $testo);

		$testo = strip_tags($testo);
		$testo = html_entity_decode($testo, ENT_QUOTES, 'UTF-8');
		$testo = preg_replace("#[ \t]+\n#", "\n", $testo);
		$testo = preg_replace("#\n{3,}#", "\n\n", $testo);

		return trim($testo);
	}

	/**
	 * Caratteri fuori dal piano multilingue di base.
	 *
	 * Su un MySQL ancora configurato con il vecchio utf8 a tre byte, una
	 * emoji nell'oggetto fa fallire l'inserimento e l'intera campagna non
	 * parte. In HTML diventa un'entita numerica, che i lettori mostrano
	 * correttamente; nel testo semplice non c'e equivalente e va tolta.
	 *
	 * @param string $testo
	 * @param bool   $html
	 * @return string
	 */
	public function strip_supplementary($testo, $html = false)
	{
		if ($html)
		{
			return preg_replace_callback('/[\x{10000}-\x{10FFFF}]/u', function ($trovato) {
				$byte = unpack('C*', $trovato[0]);

				$punto = (($byte[1] & 0x07) << 18)
					| (($byte[2] & 0x3F) << 12)
					| (($byte[3] & 0x3F) << 6)
					| ($byte[4] & 0x3F);

				return '&#' . $punto . ';';
			}, (string) $testo);
		}

		return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', (string) $testo);
	}
}
