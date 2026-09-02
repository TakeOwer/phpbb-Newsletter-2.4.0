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
 * Valori con cui si apre il modulo di composizione.
 *
 * Chi manda newsletter tende a mandarle sempre allo stesso modo: agli stessi
 * gruppi, nello stesso formato, con la stessa cadenza. Ricompilare ogni volta
 * gli stessi campi non e solo scomodo, e un invito a sbagliarne uno.
 */
class add_compose_defaults extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\newsletter\migrations\add_default_format');
	}

	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config['newsletter_default_priority']);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			array('config.add', array('newsletter_default_groups', '')),
			array('config.add', array('newsletter_default_subs', 0)),
			array('config.add', array('newsletter_default_lang', '')),
			array('config.add', array('newsletter_default_priority', 3)),
			array('config.add', array('newsletter_default_importance', 'normal')),
			array('config.add', array('newsletter_default_sensitivity', '')),
			array('config.add', array('newsletter_default_banner', 1)),
			array('config_text.add', array('newsletter_default_css', '')),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('newsletter_default_groups')),
			array('config.remove', array('newsletter_default_subs')),
			array('config.remove', array('newsletter_default_lang')),
			array('config.remove', array('newsletter_default_priority')),
			array('config.remove', array('newsletter_default_importance')),
			array('config.remove', array('newsletter_default_sensitivity')),
			array('config.remove', array('newsletter_default_banner')),
			array('config_text.remove', array('newsletter_default_css')),
		);
	}
}
