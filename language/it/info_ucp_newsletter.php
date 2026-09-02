<?php
/**
 *
 * Newsletter [Italiano]
 *
 * @copyright (c) 2026 salvocortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(
	'UCP_NEWSLETTER_SEND'	=> 'Scrivi una newsletter',
	'UCP_NEWSLETTER'		=> 'Newsletter',
	'UCP_NEWSLETTER_MANAGE'	=> 'Iscrizione',
	'UCP_NEWSLETTER_SEND'	=> 'Scrivi una newsletter',
));
