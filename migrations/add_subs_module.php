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
 * Aggiunge la scheda "Iscritti" al pannello di amministrazione.
 *
 * Sta in una migrazione a se e non nella install_acp_module perche quella e
 * gia stata eseguita sulle installazioni esistenti: phpBB non ripete una
 * migrazione conclusa, quindi modificarla lascerebbe la voce di menu assente
 * su ogni forum dove l'estensione era gia attiva.
 */
class add_subs_module extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\newsletter\migrations\install_acp_module');
	}

	/**
	 * La voce esiste gia?
	 *
	 * Evita l'eccezione MODULE_EXISTS quando la migrazione viene rieseguita, per
	 * esempio dopo una disinstallazione interrotta a meta.
	 *
	 * @return bool
	 */
	public function effectively_installed()
	{
		$sql = 'SELECT module_id
			FROM ' . MODULES_TABLE . "
			WHERE module_class = 'acp'
				AND module_basename = '\\\\salvocortesiano\\\\newsletter\\\\acp\\\\newsletter_module'
				AND module_mode = 'subs'";
		$result = $this->db->sql_query($sql);
		$riga = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return !empty($riga);
	}

	/**
	 * Aggiunta della voce
	 */
	public function update_data()
	{
		return array(
			array('module.add', array(
				'acp',
				'ACP_NEWSLETTER',
				array(
					'module_basename'	=> '\salvocortesiano\newsletter\acp\newsletter_module',
					'modes'				=> array('subs'),
				),
			)),
		);
	}

	/**
	 * Rimozione della voce
	 */
	public function revert_data()
	{
		return array(
			array('module.remove', array(
				'acp',
				'ACP_NEWSLETTER',
				array(
					'module_basename'	=> '\salvocortesiano\newsletter\acp\newsletter_module',
					'modes'				=> array('subs'),
				),
			)),
		);
	}
}
