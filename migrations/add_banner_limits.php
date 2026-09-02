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
 * Limiti dimensionali dell'immagine di intestazione.
 *
 * Un banner e per definizione una striscia larga e bassa: una immagine
 * quadrata caricata per sbaglio occuperebbe l'intera prima schermata del
 * messaggio e il testo finirebbe sotto la piega, dove nessuno lo legge.
 *
 * I valori sono in configurazione e non scritti nel codice perche l'altezza
 * giusta dipende dal banner che ciascuno usa: un intervallo troppo stretto
 * rifiuterebbe immagini perfettamente valide per pochi punti di differenza.
 */
class add_banner_limits extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\newsletter\migrations\add_banner');
	}

	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config['newsletter_banner_max_height']);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			array('config.add', array('newsletter_banner_min_height', 200)),
			array('config.add', array('newsletter_banner_max_height', 260)),
			array('config.add', array('newsletter_banner_max_width', 2600)),
			array('config.add', array('newsletter_banner_min_width', 600)),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('newsletter_banner_min_height')),
			array('config.remove', array('newsletter_banner_max_height')),
			array('config.remove', array('newsletter_banner_max_width')),
			array('config.remove', array('newsletter_banner_min_width')),
		);
	}
}
