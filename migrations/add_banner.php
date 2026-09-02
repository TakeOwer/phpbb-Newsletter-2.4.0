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
 * Immagine di intestazione per i messaggi in HTML.
 *
 * Il nome del file sta in configurazione e non nella campagna: il banner e uno
 * solo e vale per tutte le newsletter, mentre la colonna sulla campagna dice
 * soltanto se quel singolo messaggio deve portarlo. Cosi non lo si ricarica a
 * ogni invio, ma resta possibile mandare un messaggio senza.
 */
class add_banner extends \phpbb\db\migration\migration
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
		return $this->db_tools->sql_column_exists($this->table_prefix . 'newsletter_campaigns', 'campaign_banner');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return array(
			'add_columns' => array(
				$this->table_prefix . 'newsletter_campaigns' => array(
					'campaign_banner' => array('BOOL', 1),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_schema()
	{
		return array(
			'drop_columns' => array(
				$this->table_prefix . 'newsletter_campaigns' => array('campaign_banner'),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			array('config.add', array('newsletter_banner', '')),
			array('config.add', array('newsletter_banner_link', '')),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('newsletter_banner')),
			array('config.remove', array('newsletter_banner_link')),
		);
	}
}
