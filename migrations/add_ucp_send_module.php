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
 * Voce "Scrivi" nel pannello di controllo utente
 */
class add_ucp_send_module extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array(
			'\salvocortesiano\newsletter\migrations\install_ucp_module',
			'\salvocortesiano\newsletter\migrations\add_ucp_send',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		$sql = 'SELECT module_id
			FROM ' . MODULES_TABLE . "
			WHERE module_class = 'ucp'
				AND module_basename = '\\\\salvocortesiano\\\\newsletter\\\\ucp\\\\send_module'";
		$result = $this->db->sql_query($sql);
		$riga = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return !empty($riga);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			array('module.add', array(
				'ucp',
				'UCP_NEWSLETTER',
				array(
					'module_basename'	=> '\salvocortesiano\newsletter\ucp\send_module',
					'modes'				=> array('send'),
				),
			)),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return array(
			array('module.remove', array(
				'ucp',
				'UCP_NEWSLETTER',
				array(
					'module_basename'	=> '\salvocortesiano\newsletter\ucp\send_module',
					'modes'				=> array('send'),
				),
			)),
		);
	}
}
