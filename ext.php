<?php
/**
 *
 * Newsletter. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 salvocortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\newsletter;

class ext extends \phpbb\extension\base
{
	/**
	 * L'estensione usa la sintassi Twig dei template e le API di phpBB 3.3.
	 * PHP 7.1 e richiesto per le funzioni di codifica MIME e per la sintassi
	 * usata nelle classi del pacchetto.
	 *
	 * @return bool
	 */
	public function is_enableable()
	{
		$config = $this->container->get('config');

		return phpbb_version_compare($config['version'], '3.3.0', '>=') && version_compare(PHP_VERSION, '7.1.0', '>=');
	}
}
