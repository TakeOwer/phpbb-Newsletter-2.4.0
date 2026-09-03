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
 * \salvocortesiano\newsletter\acp\newsletter_module -> ..\acp\newsletter_info
 *
 */

namespace salvocortesiano\newsletter\acp;

class newsletter_info
{
	public function module()
	{
		return array(
			'filename'	=> '\salvocortesiano\newsletter\acp\newsletter_module',
			'title'		=> 'ACP_NEWSLETTER',
			'modes'		=> array(
				'compose'	=> array(
					'title'	=> 'ACP_NEWSLETTER_COMPOSE',
					'auth'	=> 'ext_salvocortesiano/newsletter && acl_a_newsletter',
					'cat'	=> array('ACP_NEWSLETTER'),
				),
				'lists'		=> array(
					'title'	=> 'ACP_NEWSLETTER_LISTS',
					'auth'	=> 'ext_salvocortesiano/newsletter && acl_a_newsletter',
					'cat'	=> array('ACP_NEWSLETTER'),
				),
				'subs'		=> array(
					'title'	=> 'ACP_NEWSLETTER_SUBS',
					'auth'	=> 'ext_salvocortesiano/newsletter && acl_a_newsletter',
					'cat'	=> array('ACP_NEWSLETTER'),
				),
				'logs'		=> array(
					'title'	=> 'ACP_NEWSLETTER_LOGS',
					'auth'	=> 'ext_salvocortesiano/newsletter && acl_a_newsletter',
					'cat'	=> array('ACP_NEWSLETTER'),
				),
				'test'		=> array(
					'title'	=> 'ACP_NEWSLETTER_TEST',
					'auth'	=> 'ext_salvocortesiano/newsletter && acl_a_newsletter',
					'cat'	=> array('ACP_NEWSLETTER'),
				),
				'settings'	=> array(
					'title'	=> 'ACP_NEWSLETTER_SETTINGS',
					'auth'	=> 'ext_salvocortesiano/newsletter && acl_a_newsletter',
					'cat'	=> array('ACP_NEWSLETTER'),
				),
			),
		);
	}
}
