<?php
/**
 *
 * Newsletter. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 salvocortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\newsletter\migrations;

/**
 * Formato dell'ultimo messaggio composto.
 *
 * Chi manda newsletter in HTML le manda quasi sempre in HTML: ritrovare il
 * modulo su "testo semplice" a ogni ritorno nella pagina significa doverlo
 * cambiare ogni volta, e prima o poi dimenticarsene e spedire la marcatura
 * grezza ai destinatari.
 */
class add_default_format extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\newsletter\migrations\v1_install');
	}

	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config['newsletter_default_format']);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			array('config.add', array('newsletter_default_format', 0)),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('newsletter_default_format')),
		);
	}
}
