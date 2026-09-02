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

class v1_install extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config['newsletter_enabled']);
	}

	/**
	 * {@inheritdoc}
	 */
	static public function depends_on()
	{
		return array('\phpbb\db\migration\data\v330\v330');
	}

	/**
	 * Tabelle dell'estensione.
	 *
	 * Tre tabelle, ciascuna con un compito preciso. La campagna descrive che
	 * cosa si invia e a chi. La coda tiene una riga per destinatario: senza,
	 * un invio interrotto a meta non saprebbe da dove riprendere ne quale
	 * indirizzo ha gia ricevuto il messaggio. Le iscrizioni registrano chi ha
	 * chiesto esplicitamente di ricevere la newsletter dal pannello utente.
	 *
	 * Il corpo del messaggio sta una volta sola nella campagna e non viene
	 * copiato in ogni riga di coda: con quattromila iscritti e un messaggio da
	 * venti kilobyte la differenza fra le due scelte e di ottanta megabyte di
	 * tabella per un solo invio.
	 */
	public function update_schema()
	{
		return array(
			'add_tables' => array(
				$this->table_prefix . 'newsletter_campaigns' => array(
					'COLUMNS' => array(
						'campaign_id'			=> array('UINT', null, 'auto_increment'),
						'campaign_subject'		=> array('VCHAR_UNI:255', ''),
						'campaign_body'			=> array('MTEXT_UNI', ''),
						'campaign_css'			=> array('MTEXT_UNI', ''),
						// 0 = testo semplice, 1 = HTML
						'campaign_format'		=> array('BOOL', 0),
						// Elenco degli identificativi di gruppo separati da virgola
						'campaign_groups'		=> array('TEXT_UNI', ''),
						// Includere anche chi si e iscritto dal pannello utente
						'campaign_subs'			=> array('BOOL', 0),
						// Vuoto: tutte le lingue
						'campaign_lang'			=> array('VCHAR:30', ''),
						// Argomenti in evidenza, identificativi separati da virgola
						'campaign_topics'		=> array('VCHAR:255', ''),
						// Intestazione X-Priority: da 1 (massima) a 5 (minima)
						'campaign_priority'		=> array('TINT:2', 3),
						// Intestazione Importance: high, normal, low
						'campaign_importance'	=> array('VCHAR:10', 'normal'),
						// Intestazione Sensitivity: vuota, personal, private, company-confidential
						'campaign_sensitivity'	=> array('VCHAR:24', ''),
						'campaign_from_name'	=> array('VCHAR_UNI:255', ''),
						'campaign_from_email'	=> array('VCHAR:100', ''),
						'campaign_reply_to'		=> array('VCHAR:100', ''),
						// 0 bozza, 1 in corso, 2 in pausa, 3 completata, 4 annullata
						'campaign_status'		=> array('TINT:2', 0),
						'campaign_batch'		=> array('UINT', 25),
						'campaign_interval'		=> array('UINT', 600),
						'campaign_total'		=> array('UINT', 0),
						'campaign_sent'			=> array('UINT', 0),
						'campaign_failed'		=> array('UINT', 0),
						'campaign_created'		=> array('TIMESTAMP', 0),
						'campaign_schedule'		=> array('TIMESTAMP', 0),
						'campaign_started'		=> array('TIMESTAMP', 0),
						'campaign_last_run'		=> array('TIMESTAMP', 0),
						'campaign_finished'		=> array('TIMESTAMP', 0),
						'campaign_author'		=> array('UINT', 0),
						'campaign_author_name'	=> array('VCHAR_UNI:255', ''),
					),
					'PRIMARY_KEY'	=> 'campaign_id',
					'KEYS'			=> array(
						'nc_status'		=> array('INDEX', 'campaign_status'),
						'nc_created'	=> array('INDEX', 'campaign_created'),
					),
				),

				$this->table_prefix . 'newsletter_queue' => array(
					'COLUMNS' => array(
						'queue_id'			=> array('UINT', null, 'auto_increment'),
						'campaign_id'		=> array('UINT', 0),
						'user_id'			=> array('UINT', 0),
						'username'			=> array('VCHAR_UNI:255', ''),
						'user_email'		=> array('VCHAR:100', ''),
						'user_lang'			=> array('VCHAR:30', ''),
						// 0 in attesa, 1 recapitata, 2 fallita
						'queue_status'		=> array('TINT:2', 0),
						'queue_attempts'	=> array('TINT:2', 0),
						'queue_time'		=> array('TIMESTAMP', 0),
						'queue_error'		=> array('VCHAR_UNI:255', ''),
					),
					'PRIMARY_KEY'	=> 'queue_id',
					'KEYS'			=> array(
						// L'indice su (campagna, stato) e quello su cui poggia
						// ogni lotto: seleziona le righe ancora da inviare
						'nq_pending'	=> array('INDEX', array('campaign_id', 'queue_status')),
						'nq_unique'		=> array('UNIQUE', array('campaign_id', 'user_id')),
					),
				),

				$this->table_prefix . 'newsletter_subs' => array(
					'COLUMNS' => array(
						'user_id'		=> array('UINT', 0),
						'sub_email'		=> array('VCHAR:100', ''),
						'sub_time'		=> array('TIMESTAMP', 0),
						// Indirizzo da cui e partita l'iscrizione: se un domani
						// qualcuno contesta di non essersi mai iscritto, e
						// l'unica prova che si ha
						'sub_ip'		=> array('VCHAR:40', ''),
					),
					'PRIMARY_KEY'	=> 'user_id',
					'KEYS'			=> array(
						'ns_time'	=> array('INDEX', 'sub_time'),
					),
				),
			),
		);
	}

	/**
	 * Rimozione delle tabelle in disinstallazione
	 */
	public function revert_schema()
	{
		return array(
			'drop_tables' => array(
				$this->table_prefix . 'newsletter_campaigns',
				$this->table_prefix . 'newsletter_queue',
				$this->table_prefix . 'newsletter_subs',
			),
		);
	}

	/**
	 * Configurazione e permessi
	 */
	public function update_data()
	{
		return array(
			array('config.add', array('newsletter_enabled', 1)),

			// Valori predefiniti proposti nel modulo di composizione. Il lotto
			// da 25 sta sotto al limite orario dei fornitori piu restrittivi, e
			// dieci minuti di pausa fra un lotto e l'altro tengono la media ben
			// lontana da qualsiasi soglia
			array('config.add', array('newsletter_batch_size', 25)),
			array('config.add', array('newsletter_interval', 600)),

			// Oltre questo numero di destinatari la conferma mostra l'avviso
			// sui limiti dell'host
			array('config.add', array('newsletter_warn_threshold', 50)),

			array('config.add', array('newsletter_max_attempts', 2)),
			array('config.add', array('newsletter_respect_optout', 1)),
			array('config.add', array('newsletter_skip_banned', 1)),
			array('config.add', array('newsletter_check_dns', 0)),
			array('config.add', array('newsletter_strip_emoji', 0)),
			array('config.add', array('newsletter_copy_sender', 1)),
			array('config.add', array('newsletter_from_name', '')),
			array('config.add', array('newsletter_from_email', '')),
			array('config.add', array('newsletter_reply_to', '')),
			array('config.add', array('newsletter_test_email', '')),
			array('config.add', array('newsletter_keep_days', 0)),
			array('config.add', array('newsletter_last_run', 0)),
			array('config.add', array('newsletter_time_budget', 45)),

			// Iscrizioni dal pannello utente
			array('config.add', array('newsletter_subs_enabled', 1)),
			array('config.add', array('newsletter_welcome_email', 1)),
			array('config.add', array('newsletter_goodbye_email', 1)),

			// Chiave con cui si firmano i collegamenti di disiscrizione. Viene
			// generata alla prima richiesta: un valore fisso scritto qui
			// sarebbe identico su ogni installazione, e chiunque potrebbe
			// disiscrivere gli utenti altrui conoscendone il numero
			array('config.add', array('newsletter_secret', '')),

			// Il pie di pagina viene aggiunto a ogni messaggio: contiene il
			// riferimento alla disiscrizione, che in molti ordinamenti non e
			// facoltativo. Sta in config_text perche puo essere lungo
			array('config_text.add', array('newsletter_footer_text', '')),
			array('config_text.add', array('newsletter_footer_html', '')),
			array('config_text.add', array('newsletter_css', '')),
			array('config_text.add', array('newsletter_intro', '')),

			array('permission.add', array('a_newsletter', true)),
			array('permission.permission_set', array('ADMINISTRATORS', 'a_newsletter', 'group')),
		);
	}

	/**
	 * Pulizia completa in disinstallazione
	 */
	public function revert_data()
	{
		return array(
			array('permission.remove', array('a_newsletter')),

			array('config.remove', array('newsletter_enabled')),
			array('config.remove', array('newsletter_batch_size')),
			array('config.remove', array('newsletter_interval')),
			array('config.remove', array('newsletter_warn_threshold')),
			array('config.remove', array('newsletter_max_attempts')),
			array('config.remove', array('newsletter_respect_optout')),
			array('config.remove', array('newsletter_skip_banned')),
			array('config.remove', array('newsletter_check_dns')),
			array('config.remove', array('newsletter_strip_emoji')),
			array('config.remove', array('newsletter_copy_sender')),
			array('config.remove', array('newsletter_from_name')),
			array('config.remove', array('newsletter_from_email')),
			array('config.remove', array('newsletter_reply_to')),
			array('config.remove', array('newsletter_test_email')),
			array('config.remove', array('newsletter_keep_days')),
			array('config.remove', array('newsletter_last_run')),
			array('config.remove', array('newsletter_time_budget')),
			array('config.remove', array('newsletter_subs_enabled')),
			array('config.remove', array('newsletter_welcome_email')),
			array('config.remove', array('newsletter_goodbye_email')),
			array('config.remove', array('newsletter_secret')),

			array('config_text.remove', array('newsletter_footer_text')),
			array('config_text.remove', array('newsletter_footer_html')),
			array('config_text.remove', array('newsletter_css')),
			array('config_text.remove', array('newsletter_intro')),
		);
	}
}
