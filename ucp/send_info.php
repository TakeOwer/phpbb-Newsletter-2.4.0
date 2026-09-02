<?php
/**
 *
 * Newsletter. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 salvocortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\newsletter\ucp;

class send_info
{
	public function module()
	{
		return array(
			'filename'	=> '\salvocortesiano\newsletter\ucp\send_module',
			'title'		=> 'UCP_NEWSLETTER_SEND',
			'modes'		=> array(
				'send'	=> array(
					'title'	=> 'UCP_NEWSLETTER_SEND',
					'auth'	=> 'ext_salvocortesiano/newsletter',
					'cat'	=> array('UCP_NEWSLETTER'),
				),
			),
		);
	}
}
