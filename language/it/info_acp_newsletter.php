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
	'ACP_NEWSLETTER'					=> 'Newsletter',

	'ACP_NEWSLETTER_COMPOSE'			=> 'Scrivi una newsletter',
	'ACP_NEWSLETTER_COMPOSE_EXPLAIN'	=> 'Scrivi il messaggio, scegli chi lo riceve e con quale ritmo parte. Non viene inviato nulla finché non confermi nella pagina successiva, dove vedrai quanti sono i destinatari e quanto durerà l\'invio.',

	'ACP_NEWSLETTER_LISTS'				=> 'Notiziari',
	'ACP_NEWSLETTER_LISTS_EXPLAIN'		=> 'Un forum può avere più notiziari distinti — per esempio uno mensile e uno di annunci tecnici — e ogni utente sceglie a quali iscriversi. Ogni newsletter che scrivi appartiene a un notiziario, e arriva solo a chi ha scelto quello.',

	'ACP_NEWSLETTER_SUBS'				=> 'Iscritti',
	'ACP_NEWSLETTER_SUBS_EXPLAIN'		=> 'Tutti coloro che si sono iscritti alla newsletter dal proprio pannello di controllo, con l\'indirizzo usato e il momento dell\'iscrizione. Da qui puoi togliere qualcuno dall\'elenco; potrà sempre iscriversi di nuovo da solo.',

	'ACP_NEWSLETTER_LOGS'				=> 'Registro',
	'ACP_NEWSLETTER_LOGS_EXPLAIN'		=> 'Tutte le newsletter, con quante email sono state recapitate, quante sono ancora in attesa e quante sono fallite. Aprendone una vedi i destinatari uno per uno e il motivo di ogni fallimento.',

	'ACP_NEWSLETTER_SETTINGS'			=> 'Impostazioni',
	'ACP_NEWSLETTER_SETTINGS_EXPLAIN'	=> 'Valori predefiniti per le nuove newsletter, comportamento delle iscrizioni e piè di pagina aggiunto a ogni messaggio. Quanto imposti qui può essere modificato per la singola newsletter mentre la scrivi.',
));
