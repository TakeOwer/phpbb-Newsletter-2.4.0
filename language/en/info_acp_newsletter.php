<?php
/**
 *
 * Newsletter [English]
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
	'ACP_NEWSLETTER'					=> 'Newsletter',

	'ACP_NEWSLETTER_COMPOSE'			=> 'Write a newsletter',
	'ACP_NEWSLETTER_COMPOSE_EXPLAIN'	=> 'Write the message, choose who receives it and how fast it goes out. Nothing is sent until you confirm on the next page, where you will see how many recipients there are and how long the sending will take.',

	'ACP_NEWSLETTER_LISTS'				=> 'Newsletters',
	'ACP_NEWSLETTER_LISTS_EXPLAIN'		=> 'A board can have several separate newsletters — a monthly digest and technical announcements, say — and each member chooses which ones to receive. Every message you write belongs to one newsletter, and reaches only those who chose it.',

	'ACP_NEWSLETTER_SUBS'				=> 'Subscribers',
	'ACP_NEWSLETTER_SUBS_EXPLAIN'		=> 'Everyone who subscribed to the newsletter from their user control panel, with the address they used and when they subscribed. From here you can remove someone from the list; they can always subscribe again themselves.',

	'ACP_NEWSLETTER_LOGS'				=> 'Log',
	'ACP_NEWSLETTER_LOGS_EXPLAIN'		=> 'Every newsletter, with how many messages were delivered, how many are still waiting and how many failed. Open one to see the recipients one by one and the reason behind each failure.',

	'ACP_NEWSLETTER_SETTINGS'			=> 'Settings',
	'ACP_NEWSLETTER_SETTINGS_EXPLAIN'	=> 'Defaults for new newsletters, subscription behaviour and the footer added to every message. Values set here can be adjusted for a single newsletter while writing it.',
));
