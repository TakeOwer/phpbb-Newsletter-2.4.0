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
 * Esito dell'ultima prova di invio.
 *
 * Conservarlo permette di sostituire un avviso costruito su una supposizione
 * con la constatazione di un fatto: dopo una prova riuscita non ha piu senso
 * mettere in guardia su una configurazione che si e appena vista funzionare.
 */
class add_diag_state extends \phpbb\db\migration\migration
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
		return isset($this->config['newsletter_diag_time']);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			// 0 non riuscita, 1 riuscita. Ha senso solo se newsletter_diag_time
			// e diverso da zero, cioe se una prova e stata eseguita davvero
			array('config.add', array('newsletter_diag_ok', 0)),
			array('config.add', array('newsletter_diag_time', 0)),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('newsletter_diag_ok')),
			array('config.remove', array('newsletter_diag_time')),
		);
	}
}
