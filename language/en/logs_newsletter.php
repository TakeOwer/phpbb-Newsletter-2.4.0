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
	'LOG_NEWSLETTER_SENT'			=> '<strong>Newsletter queued</strong><br />» %1$s<br />Recipients: %2$d',
	'LOG_NEWSLETTER_COMPLETED'		=> '<strong>Newsletter completed</strong><br />» %1$s<br />Delivered: %2$d, failed: %3$d',
	'LOG_NEWSLETTER_DRAFT'			=> '<strong>Newsletter draft saved</strong><br />» %s',
	'LOG_NEWSLETTER_PAUSED'			=> '<strong>Newsletter paused</strong><br />» %s',
	'LOG_NEWSLETTER_RESUMED'		=> '<strong>Newsletter resumed</strong><br />» %s',
	'LOG_NEWSLETTER_CANCELLED'		=> '<strong>Newsletter cancelled</strong><br />» %s',
	'LOG_NEWSLETTER_LOG_DELETED'	=> '<strong>Newsletter log entry deleted</strong><br />» %s',
	'LOG_NEWSLETTER_LOGS_CLEARED'	=> '<strong>Newsletter log cleared</strong><br />» %d entries removed',
	'LOG_NEWSLETTER_BANNER_UPLOADED'	=> '<strong>Newsletter header image uploaded</strong><br />» %s',
	'LOG_NEWSLETTER_BANNER_DELETED'	=> '<strong>Newsletter header image removed</strong>',
	'LOG_NEWSLETTER_DEFAULTS'		=> '<strong>Newsletter compose defaults saved</strong>',
	'LOG_NEWSLETTER_PUBLISHED'		=> '<strong>Newsletter published in the archive</strong><br />» %s',
	'LOG_NEWSLETTER_UNPUBLISHED'	=> '<strong>Newsletter withdrawn from the archive</strong><br />» %s',
	'LOG_NEWSLETTER_LIST_CREATED'	=> '<strong>Newsletter created</strong><br />» %s',
	'LOG_NEWSLETTER_LIST_EDITED'	=> '<strong>Newsletter edited</strong><br />» %s',
	'LOG_NEWSLETTER_LIST_DELETED'	=> '<strong>Newsletter deleted</strong><br />» %s',
	'LOG_NEWSLETTER_USER_QUEUED'	=> '<strong>Newsletter written from the user panel, awaiting approval</strong><br />» %s',
	'LOG_NEWSLETTER_USER_SENT'		=> '<strong>Newsletter sent from the user panel</strong><br />» %s',
	'LOG_NEWSLETTER_APPROVED'		=> '<strong>Newsletter approved</strong><br />» %1$s, written by %2$s',
	'LOG_NEWSLETTER_REJECTED'		=> '<strong>Newsletter rejected</strong><br />» %1$s, written by %2$s',
	'LOG_NEWSLETTER_USER_QUEUED'	=> '<strong>Newsletter written and left waiting for approval</strong><br />» %s',
	'LOG_NEWSLETTER_USER_SENT'		=> '<strong>Newsletter written and sent</strong><br />» %s',
	'LOG_NEWSLETTER_APPROVED'		=> '<strong>Newsletter approved</strong><br />» %1$s, written by %2$s',
	'LOG_NEWSLETTER_REJECTED'		=> '<strong>Newsletter rejected</strong><br />» %1$s, written by %2$s',
	'LOG_NEWSLETTER_AUTO_PAUSED'	=> '<strong>Sending stopped itself after too many failures</strong><br />» %1$s, after %2$d failures in a row',
	'LOG_NEWSLETTER_SETTINGS'		=> '<strong>Newsletter settings updated</strong>',
	'LOG_NEWSLETTER_ADMIN_UNSUB'	=> '<strong>Member removed from the newsletter</strong><br />» %s',
	'LOG_NEWSLETTER_SUBSCRIBED'		=> '<strong>Subscribed to the newsletter</strong>',
	'LOG_NEWSLETTER_UNSUBSCRIBED'	=> '<strong>Unsubscribed from the newsletter</strong>',
));
