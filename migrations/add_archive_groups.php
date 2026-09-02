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
 * Archivio riservato a gruppi scelti.
 *
 * Le tre visibilita di partenza - chiuso, registrati, chiunque - coprono i casi
 * comuni ma non quello di un archivio riservato allo staff o a un gruppo di
 * sostenitori, che su un forum e tutt'altro che raro.
 */
class add_archive_groups extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\newsletter\migrations\add_archive');
	}

	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config['newsletter_archive_groups']);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			array('config.add', array('newsletter_archive_groups', '')),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('newsletter_archive_groups')),
		);
	}
}
