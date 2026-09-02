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

class install_ucp_module extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\newsletter\migrations\v1_install');
	}

	/**
	 * Voce "Newsletter" nel pannello di controllo utente
	 */
	public function update_data()
	{
		return array(
			array('module.add', array(
				'ucp',
				false,
				'UCP_NEWSLETTER',
			)),
			array('module.add', array(
				'ucp',
				'UCP_NEWSLETTER',
				array(
					'module_basename'	=> '\salvocortesiano\newsletter\ucp\newsletter_module',
					'modes'				=> array('manage'),
				),
			)),
		);
	}

	/**
	 * Rimozione dei moduli.
	 *
	 * Senza questo metodo le voci resterebbero nel database dopo la
	 * disinstallazione e la reinstallazione fallirebbe con MODULE_EXISTS.
	 * L'ordine conta: prima la voce figlia, poi la categoria.
	 */
	public function revert_data()
	{
		return array(
			array('module.remove', array(
				'ucp',
				'UCP_NEWSLETTER',
				array(
					'module_basename'	=> '\salvocortesiano\newsletter\ucp\newsletter_module',
					'modes'				=> array('manage'),
				),
			)),
			array('module.remove', array(
				'ucp',
				false,
				'UCP_NEWSLETTER',
			)),
		);
	}
}
