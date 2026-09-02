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
 * Terzo formato di composizione: il BBCode del forum.
 *
 * Il testo viene conservato nella forma che phpBB usa per i messaggi, insieme
 * ai tre valori di servizio che accompagnano sempre un testo memorizzato. Cosi
 * la conversione in HTML avviene al momento dell'invio e segue le impostazioni
 * del forum: se domani si aggiunge un tag o si cambia il set di faccine, le
 * bozze gia scritte ne tengono conto da sole.
 */
class add_bbcode extends \phpbb\db\migration\migration
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
		return $this->db_tools->sql_column_exists($this->table_prefix . 'newsletter_campaigns', 'campaign_bbcode_uid');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return array(
			'add_columns' => array(
				$this->table_prefix . 'newsletter_campaigns' => array(
					'campaign_bbcode_uid'		=> array('VCHAR:8', ''),
					'campaign_bbcode_bitfield'	=> array('VCHAR:255', ''),
					'campaign_bbcode_options'	=> array('UINT:11', 7),
				),
			),
			'change_columns' => array(
				$this->table_prefix . 'newsletter_campaigns' => array(
					// Da booleana a numerica: i formati sono diventati tre, e
					// una colonna dichiarata come si/no non e piu il tipo giusto
					// anche se in MySQL ci starebbe comunque
					'campaign_format'	=> array('TINT:2', 0),
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
				$this->table_prefix . 'newsletter_campaigns' => array(
					'campaign_bbcode_uid',
					'campaign_bbcode_bitfield',
					'campaign_bbcode_options',
				),
			),
			'change_columns' => array(
				$this->table_prefix . 'newsletter_campaigns' => array(
					'campaign_format'	=> array('BOOL', 0),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			array('config.add', array('newsletter_bbcode_smilies', 1)),
			array('config.add', array('newsletter_bbcode_urls', 1)),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('newsletter_bbcode_smilies')),
			array('config.remove', array('newsletter_bbcode_urls')),
		);
	}
}
