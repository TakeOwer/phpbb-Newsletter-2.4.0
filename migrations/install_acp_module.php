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

class install_acp_module extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\newsletter\migrations\v1_install');
	}

	/**
	 * Categoria e voci di menu nel pannello di amministrazione
	 */
	public function update_data()
	{
		return array(
			array('module.add', array(
				'acp',
				'ACP_CAT_DOT_MODS',
				'ACP_NEWSLETTER',
			)),
			array('module.add', array(
				'acp',
				'ACP_NEWSLETTER',
				array(
					'module_basename'	=> '\salvocortesiano\newsletter\acp\newsletter_module',
					'modes'				=> array('compose', 'logs', 'settings'),
				),
			)),
		);
	}

	/**
	 * Rimozione dei moduli.
	 *
	 * Senza questo metodo, disinstallando l'estensione le voci resterebbero nel
	 * database e la reinstallazione fallirebbe con l'eccezione MODULE_EXISTS.
	 * L'ordine conta: prima le voci figlie, poi la categoria.
	 */
	public function revert_data()
	{
		return array(
			array('module.remove', array(
				'acp',
				'ACP_NEWSLETTER',
				array(
					'module_basename'	=> '\salvocortesiano\newsletter\acp\newsletter_module',
					'modes'				=> array('compose', 'logs', 'settings'),
				),
			)),
			array('module.remove', array(
				'acp',
				'ACP_CAT_DOT_MODS',
				'ACP_NEWSLETTER',
			)),
		);
	}
}
