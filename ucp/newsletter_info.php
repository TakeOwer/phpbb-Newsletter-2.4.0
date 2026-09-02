<?php
/**
 *
 * Newsletter. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 salvocortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * Il nome di questo file e della classe non sono liberi: phpBB ricava la classe
 * "info" dal basename del modulo sostituendo il suffisso _module con _info.
 *
 */

namespace salvocortesiano\newsletter\ucp;

class newsletter_info
{
	public function module()
	{
		return array(
			'filename'	=> '\salvocortesiano\newsletter\ucp\newsletter_module',
			'title'		=> 'UCP_NEWSLETTER',
			'modes'		=> array(
				'manage'	=> array(
					'title'	=> 'UCP_NEWSLETTER_MANAGE',
					'auth'	=> 'ext_salvocortesiano/newsletter',
					'cat'	=> array('UCP_NEWSLETTER'),
				),
			),
		);
	}
}
