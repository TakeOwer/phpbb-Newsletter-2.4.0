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
 * Reti di sicurezza sull'invio.
 *
 * Un guasto del server di posta a meta di un invio lungo, senza queste, viene
 * macinato lotto dopo lotto: l'estensione continua a tentare, brucia tutti i
 * tentativi disponibili di ogni destinatario, e quello che era un disservizio
 * di cinque minuti diventa qualche migliaio di righe da rimettere in coda a
 * mano.
 */
class add_failsafe extends \phpbb\db\migration\migration
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
		return $this->db_tools->sql_column_exists($this->table_prefix . 'newsletter_campaigns', 'campaign_fail_streak');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return array(
			'add_columns' => array(
				$this->table_prefix . 'newsletter_campaigns' => array(
					// Fallimenti uno dopo l'altro, azzerati al primo successo.
					// Sta sulla campagna e non in memoria perche il conteggio
					// deve attraversare i lotti: dieci fallimenti spalmati su
					// tre lotti sono lo stesso guasto di dieci di fila
					'campaign_fail_streak'	=> array('UINT', 0),
					'campaign_pause_reason'	=> array('VCHAR:255', ''),
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
					'campaign_fail_streak',
					'campaign_pause_reason',
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
			// Fallimenti consecutivi oltre i quali l'invio si ferma da solo.
			// Zero disattiva la pausa automatica
			array('config.add', array('newsletter_fail_streak', 10)),
			// Resoconto all'autore a invio concluso
			array('config.add', array('newsletter_report_email', 1)),
			// Quanti secondi di ritardo oltre l'intervallo prima di segnalare
			// che una campagna non avanza piu
			array('config.add', array('newsletter_stall_grace', 3600)),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('newsletter_fail_streak')),
			array('config.remove', array('newsletter_report_email')),
			array('config.remove', array('newsletter_stall_grace')),
		);
	}
}
