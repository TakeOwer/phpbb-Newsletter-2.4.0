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
 * Impostazione predefinita per l'avviso di rimozione.
 *
 * Governa soltanto la casella gia spuntata o meno nella richiesta di conferma:
 * la decisione finale resta di chi compie la rimozione, caso per caso.
 */
class add_removed_email_config extends \phpbb\db\migration\migration
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
		return isset($this->config['newsletter_removed_email']);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			array('config.add', array('newsletter_removed_email', 1)),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('newsletter_removed_email')),
		);
	}
}
