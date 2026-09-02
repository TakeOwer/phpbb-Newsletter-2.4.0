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
 * Notiziari multipli.
 *
 * La tabella delle iscrizioni non viene alterata ma affiancata da una nuova, e
 * le righe vengono copiate. Cambiare la chiave primaria di una tabella
 * esistente e l'operazione che piu facilmente va storta - su SQLite phpBB deve
 * ricreare la tabella da zero - e qui in gioco ci sono le iscrizioni volontarie
 * degli utenti, che non si ricostruiscono.
 *
 * La vecchia tabella resta sul posto anche a copia riuscita. Occupa poco e vale
 * come rete di sicurezza: verra tolta da una migrazione successiva, quando
 * l'impianto nuovo avra qualche mese di funzionamento alle spalle.
 */
class v2_lists extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\salvocortesiano\newsletter\migrations\v1_install');
	}

	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config['newsletter_default_list']);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return array(
			'add_tables' => array(
				$this->table_prefix . 'newsletter_lists' => array(
					'COLUMNS' => array(
						'list_id'		=> array('UINT', null, 'auto_increment'),
						'list_name'		=> array('VCHAR_UNI:255', ''),
						'list_desc'		=> array('TEXT_UNI', ''),
						'list_order'	=> array('UINT', 0),
						// Un notiziario spento non accetta nuove iscrizioni ma
						// conserva quelle che ha: spegnerlo non e cancellarlo
						'list_enabled'	=> array('BOOL', 1),
						'list_default'	=> array('BOOL', 0),
						'list_public'	=> array('BOOL', 1),
						'list_groups'	=> array('TEXT_UNI', ''),
					),
					'PRIMARY_KEY'	=> 'list_id',
					'KEYS'			=> array(
						'nl_order'	=> array('INDEX', 'list_order'),
					),
				),

				$this->table_prefix . 'newsletter_list_subs' => array(
					'COLUMNS' => array(
						'user_id'	=> array('UINT', 0),
						'list_id'	=> array('UINT', 0),
						'sub_email'	=> array('VCHAR:100', ''),
						'sub_time'	=> array('TIMESTAMP', 0),
						'sub_ip'	=> array('VCHAR:40', ''),
					),
					'PRIMARY_KEY'	=> array('user_id', 'list_id'),
					'KEYS'			=> array(
						// Regge il conteggio degli iscritti a un notiziario e la
						// selezione dei destinatari, che sono le due
						// interrogazioni piu frequenti
						'nls_list'	=> array('INDEX', 'list_id'),
						'nls_time'	=> array('INDEX', 'sub_time'),
					),
				),
			),

			'add_columns' => array(
				$this->table_prefix . 'newsletter_campaigns' => array(
					'campaign_list_id' => array('UINT', 0),
				),
			),

			'add_index' => array(
				$this->table_prefix . 'newsletter_campaigns' => array(
					'nc_list' => array('campaign_list_id'),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_schema()
	{
		return array(
			'drop_keys' => array(
				$this->table_prefix . 'newsletter_campaigns' => array('nc_list'),
			),
			'drop_columns' => array(
				$this->table_prefix . 'newsletter_campaigns' => array('campaign_list_id'),
			),
			'drop_tables' => array(
				$this->table_prefix . 'newsletter_lists',
				$this->table_prefix . 'newsletter_list_subs',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			// L'ordine conta: prima esiste il notiziario, poi ci si possono
			// assegnare iscrizioni e campagne
			array('custom', array(array($this, 'crea_predefinito'))),
			array('custom', array(array($this, 'copia_iscrizioni'))),
			array('custom', array(array($this, 'assegna_campagne'))),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return array(
			array('config.remove', array('newsletter_default_list')),
		);
	}

	/**
	 * Crea il notiziario predefinito, intestato al forum.
	 *
	 * Prende il nome del forum perche e quello che gli utenti riconoscono: un
	 * "Notiziario predefinito" nella pagina delle iscrizioni non direbbe nulla
	 * a nessuno.
	 */
	public function crea_predefinito()
	{
		$tabella = $this->table_prefix . 'newsletter_lists';

		// Se un predefinito esiste gia - migrazione ripresa dopo una
		// interruzione - non se ne crea un secondo
		$sql = 'SELECT list_id FROM ' . $tabella . ' WHERE list_default = 1';
		$result = $this->db->sql_query($sql);
		$riga = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($riga)
		{
			$this->config->set('newsletter_default_list', (int) $riga['list_id']);
			return;
		}

		$nome = trim((string) $this->config['sitename']);
		$nome = ($nome !== '') ? $nome : 'Newsletter';

		$this->db->sql_query('INSERT INTO ' . $tabella . ' ' . $this->db->sql_build_array('INSERT', array(
			'list_name'		=> $nome,
			'list_desc'		=> '',
			'list_order'	=> 0,
			'list_enabled'	=> 1,
			'list_default'	=> 1,
			'list_public'	=> 1,
			'list_groups'	=> '',
		)));

		$this->config->set('newsletter_default_list', (int) $this->db->sql_nextid());
	}

	/**
	 * Copia le iscrizioni esistenti nel notiziario predefinito.
	 *
	 * A blocchi, e saltando le righe gia presenti: se la migrazione viene
	 * interrotta e ripresa non si scontra con la chiave primaria e non
	 * duplica nulla.
	 */
	public function copia_iscrizioni()
	{
		$vecchia = $this->table_prefix . 'newsletter_subs';
		$nuova = $this->table_prefix . 'newsletter_list_subs';

		if (!$this->db_tools->sql_table_exists($vecchia))
		{
			return;
		}

		$lista = (int) $this->config['newsletter_default_list'];

		if ($lista <= 0)
		{
			return;
		}

		$gia_copiati = array();

		$sql = 'SELECT user_id FROM ' . $nuova . ' WHERE list_id = ' . $lista;
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$gia_copiati[(int) $riga['user_id']] = true;
		}
		$this->db->sql_freeresult($result);

		$blocco = array();

		$sql = 'SELECT user_id, sub_email, sub_time, sub_ip FROM ' . $vecchia;
		$result = $this->db->sql_query($sql);

		while ($riga = $this->db->sql_fetchrow($result))
		{
			$user_id = (int) $riga['user_id'];

			if (isset($gia_copiati[$user_id]))
			{
				continue;
			}

			$blocco[] = array(
				'user_id'	=> $user_id,
				'list_id'	=> $lista,
				'sub_email'	=> (string) $riga['sub_email'],
				'sub_time'	=> (int) $riga['sub_time'],
				'sub_ip'	=> (string) $riga['sub_ip'],
			);

			if (count($blocco) >= 200)
			{
				$this->db->sql_multi_insert($nuova, $blocco);
				$blocco = array();
			}
		}
		$this->db->sql_freeresult($result);

		if (!empty($blocco))
		{
			$this->db->sql_multi_insert($nuova, $blocco);
		}
	}

	/**
	 * Assegna al notiziario predefinito le campagne gia esistenti
	 */
	public function assegna_campagne()
	{
		$lista = (int) $this->config['newsletter_default_list'];

		if ($lista <= 0)
		{
			return;
		}

		$this->db->sql_query('UPDATE ' . $this->table_prefix . 'newsletter_campaigns
			SET campaign_list_id = ' . $lista . '
			WHERE campaign_list_id = 0');
	}
}
