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
	'LOG_NEWSLETTER_SENT'			=> '<strong>Newsletter messa in coda</strong><br />» %1$s<br />Destinatari: %2$d',
	'LOG_NEWSLETTER_COMPLETED'		=> '<strong>Newsletter completata</strong><br />» %1$s<br />Recapitate: %2$d, fallite: %3$d',
	'LOG_NEWSLETTER_DRAFT'			=> '<strong>Bozza di newsletter salvata</strong><br />» %s',
	'LOG_NEWSLETTER_PAUSED'			=> '<strong>Newsletter messa in pausa</strong><br />» %s',
	'LOG_NEWSLETTER_RESUMED'		=> '<strong>Newsletter ripresa</strong><br />» %s',
	'LOG_NEWSLETTER_CANCELLED'		=> '<strong>Newsletter annullata</strong><br />» %s',
	'LOG_NEWSLETTER_LOG_DELETED'	=> '<strong>Voce del registro newsletter cancellata</strong><br />» %s',
	'LOG_NEWSLETTER_LOGS_CLEARED'	=> '<strong>Registro newsletter svuotato</strong><br />» %d voci rimosse',
	'LOG_NEWSLETTER_BANNER_UPLOADED'	=> '<strong>Immagine di intestazione della newsletter caricata</strong><br />» %s',
	'LOG_NEWSLETTER_BANNER_DELETED'	=> '<strong>Immagine di intestazione della newsletter eliminata</strong>',
	'LOG_NEWSLETTER_DEFAULTS'		=> '<strong>Predefiniti della composizione newsletter salvati</strong>',
	'LOG_NEWSLETTER_PUBLISHED'		=> '<strong>Newsletter pubblicata nell\'archivio</strong><br />» %s',
	'LOG_NEWSLETTER_UNPUBLISHED'	=> '<strong>Newsletter ritirata dall\'archivio</strong><br />» %s',
	'LOG_NEWSLETTER_LIST_CREATED'	=> '<strong>Notiziario creato</strong><br />» %s',
	'LOG_NEWSLETTER_LIST_EDITED'	=> '<strong>Notiziario modificato</strong><br />» %s',
	'LOG_NEWSLETTER_LIST_DELETED'	=> '<strong>Notiziario eliminato</strong><br />» %s',
	'LOG_NEWSLETTER_USER_QUEUED'	=> '<strong>Newsletter scritta dal pannello utente, in attesa di approvazione</strong><br />» %s',
	'LOG_NEWSLETTER_USER_SENT'		=> '<strong>Newsletter inviata dal pannello utente</strong><br />» %s',
	'LOG_NEWSLETTER_APPROVED'		=> '<strong>Newsletter approvata</strong><br />» %1$s, scritta da %2$s',
	'LOG_NEWSLETTER_REJECTED'		=> '<strong>Newsletter rifiutata</strong><br />» %1$s, scritta da %2$s',
	'LOG_NEWSLETTER_USER_QUEUED'	=> '<strong>Newsletter scritta e messa in attesa di approvazione</strong><br />» %s',
	'LOG_NEWSLETTER_USER_SENT'		=> '<strong>Newsletter scritta e inviata</strong><br />» %s',
	'LOG_NEWSLETTER_APPROVED'		=> '<strong>Newsletter approvata</strong><br />» %1$s, scritta da %2$s',
	'LOG_NEWSLETTER_REJECTED'		=> '<strong>Newsletter rifiutata</strong><br />» %1$s, scritta da %2$s',
	'LOG_NEWSLETTER_AUTO_PAUSED'	=> '<strong>Invio fermato da solo per troppi fallimenti</strong><br />» %1$s, dopo %2$d fallimenti consecutivi',
	'LOG_NEWSLETTER_SETTINGS'		=> '<strong>Impostazioni della newsletter aggiornate</strong>',
	'LOG_NEWSLETTER_ADMIN_UNSUB'	=> '<strong>Utente tolto dalla newsletter</strong><br />» %s',
	'LOG_NEWSLETTER_SUBSCRIBED'		=> '<strong>Iscrizione alla newsletter</strong>',
	'LOG_NEWSLETTER_UNSUBSCRIBED'	=> '<strong>Cancellazione dalla newsletter</strong>',
));
