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
 * Archivio pubblico dei numeri inviati.
 *
 * Non serve una tabella nuova: il corpo del messaggio e gia conservato nella
 * campagna, ed e proprio quello che l'archivio deve mostrare. Bastano una
 * colonna che dica se il numero e pubblico e la data in cui lo e diventato.
 */
class add_archive extends \phpbb\db\migration\migration
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
		return $this->db_tools->sql_column_exists($this->table_prefix . 'newsletter_campaigns', 'campaign_public');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return array(
			'add_columns' => array(
				$this->table_prefix . 'newsletter_campaigns' => array(
					'campaign_public'	=> array('BOOL', 0),
					'campaign_views'	=> array('UINT', 0),
				),
			),
			'add_index' => array(
				$this->table_prefix . 'newsletter_campaigns' => array(
					// L'indice regge l'elenco dell'archivio, che si interroga a
					// ogni visita alla pagina pubblica e non solo dall'ACP
					'nc_public'	=> array('campaign_public', 'campaign_status'),
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
			'drop_keys' => array(
				$this->table_prefix . 'newsletter_campaigns' => array('nc_public'),
			),
			'drop_columns' => array(
				$this->table_prefix . 'newsletter_campaigns' => array('campaign_public', 'campaign_views'),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			// 0 chiuso, 1 solo registrati, 2 anche ospiti
			array('config.add', array('newsletter_archive_visibility', 1)),
			array('config.add', array('newsletter_archive_per_page', 20)),
			// Valore proposto alla creazione di una nuova campagna
			array('config.add', array('newsletter_archive_default', 1)),
			// Il richiamo in cima al messaggio, per chi lo riceve rotto
			array('config.add', array('newsletter_archive_link_top', 1)),
			array('config.add', array('newsletter_archive_navbar', 1)),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('newsletter_archive_visibility')),
			array('config.remove', array('newsletter_archive_per_page')),
			array('config.remove', array('newsletter_archive_default')),
			array('config.remove', array('newsletter_archive_link_top')),
			array('config.remove', array('newsletter_archive_navbar')),
		);
	}
}
