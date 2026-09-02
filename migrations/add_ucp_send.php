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
 * Invio dal pannello utente, riservato a gruppi scelti.
 *
 * L'approvazione parte attiva. E la differenza fra delegare la scrittura e
 * delegare l'invio: con l'approvazione, un profilo compromesso puo al massimo
 * mettere in coda un testo che qualcuno leggera prima che parta.
 */
class add_ucp_send extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\newsletter\migrations\v2_lists');
	}

	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config['newsletter_send_groups']);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			// Gruppi abilitati a scrivere dal pannello utente
			array('config.add', array('newsletter_send_groups', '')),
			// Notiziari che possono usare
			array('config.add', array('newsletter_send_lists', '')),
			array('config.add', array('newsletter_send_approval', 1)),
			// Invii a testa nei sette giorni precedenti, 0 per nessun limite
			array('config.add', array('newsletter_send_limit', 2)),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('newsletter_send_groups')),
			array('config.remove', array('newsletter_send_lists')),
			array('config.remove', array('newsletter_send_approval')),
			array('config.remove', array('newsletter_send_limit')),
		);
	}
}
