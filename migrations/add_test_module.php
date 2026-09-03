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
 * Voce "Verifica" nel pannello di amministrazione
 */
class add_test_module extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\newsletter\migrations\install_acp_module');
	}

	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		$sql = 'SELECT module_id
			FROM ' . MODULES_TABLE . "
			WHERE module_class = 'acp'
				AND module_basename = '\\\\salvocortesiano\\\\newsletter\\\\acp\\\\newsletter_module'
				AND module_mode = 'test'";
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
				'acp',
				'ACP_NEWSLETTER',
				array(
					'module_basename'	=> '\salvocortesiano\newsletter\acp\newsletter_module',
					'modes'				=> array('test'),
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
				'acp',
				'ACP_NEWSLETTER',
				array(
					'module_basename'	=> '\salvocortesiano\newsletter\acp\newsletter_module',
					'modes'				=> array('test'),
				),
			)),
		);
	}
}
