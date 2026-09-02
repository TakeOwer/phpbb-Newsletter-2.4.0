<?php
/**
 *
 * Newsletter. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 salvocortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\newsletter\core;

/**
 * Chi puo consultare l'archivio.
 *
 * Sta in un servizio a se, e dipende solo dalla configurazione e dal database,
 * per una ragione precisa: questo controllo serve anche al listener, che phpBB
 * costruisce su OGNI pagina del forum e prima ancora che la sessione sia
 * pronta. Chiederlo al gestore delle campagne significherebbe tirarsi dietro
 * il componente di invio, il formattatore di testo e mezzo contenitore di
 * servizi a ogni richiesta - con il rischio, gia visto, di far cadere l'intero
 * pannello di amministrazione.
 */
class access
{
	/** Visibilita dell'archivio */
	const CLOSED		= 0;
	const REGISTERED	= 1;
	const EVERYONE		= 2;
	const GROUPS		= 3;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var array Gruppi di un utente, letti una volta per richiesta */
	protected $user_groups = array();

	/**
	 * Constructor
	 */
	public function __construct(\phpbb\config\config $config, \phpbb\db\driver\driver_interface $db)
	{
		$this->config = $config;
		$this->db = $db;
	}

	/**
	 * L'archivio esiste nel database?
	 *
	 * @return bool
	 */
	public function archive_available()
	{
		return isset($this->config['newsletter_archive_visibility']);
	}

	/**
	 * Chi sta guardando puo consultare l'archivio?
	 *
	 * Restituisce tre esiti invece di un si o no: un ospite davanti a un
	 * archivio riservato non va respinto con "non esiste", va portato alla
	 * pagina di accesso. Sono due situazioni diverse e meritano due risposte
	 * diverse.
	 *
	 * @param array $user_data Riga dell'utente corrente
	 * @param bool  $is_admin  Chi amministra la newsletter vede sempre
	 * @return string ok, login oppure no
	 */
	public function archive_access(array $user_data, $is_admin = false)
	{
		if (!$this->archive_available())
		{
			return 'no';
		}

		$visibilita = (int) $this->config['newsletter_archive_visibility'];

		if ($visibilita === self::CLOSED)
		{
			return 'no';
		}

		// Chi puo scrivere le newsletter puo leggerle: sarebbe assurdo
		// nascondere l'archivio a chi lo popola
		if ($is_admin)
		{
			return 'ok';
		}

		if ($visibilita === self::EVERYONE)
		{
			return 'ok';
		}

		$ospite = (!isset($user_data['user_id']) || $user_data['user_id'] == ANONYMOUS);

		if ($visibilita === self::REGISTERED)
		{
			return $ospite ? 'login' : 'ok';
		}

		if ($visibilita === self::GROUPS)
		{
			if ($ospite)
			{
				return 'login';
			}

			$ammessi = $this->split_ids((string) $this->config['newsletter_archive_groups']);

			// Nessun gruppo scelto significa che l'amministratore non ha ancora
			// deciso: meglio chiuso che aperto a tutti per distrazione
			return (!empty($ammessi) && $this->user_in_groups($user_data, $ammessi)) ? 'ok' : 'no';
		}

		return 'no';
	}

	/**
	 * L'utente appartiene a un gruppo abilitato a scrivere newsletter?
	 *
	 * @param array $user_data
	 * @return bool
	 */
	public function can_send(array $user_data)
	{
		if (!isset($this->config['newsletter_send_groups']))
		{
			return false;
		}

		$abilitati = $this->split_ids((string) $this->config['newsletter_send_groups']);

		// Nessun gruppo abilitato: la funzione resta spenta. Il caso contrario -
		// aperta a tutti perche la lista e vuota - sarebbe una svista che si
		// paga con il dominio del forum
		if (empty($abilitati))
		{
			return false;
		}

		return $this->user_in_groups($user_data, $abilitati);
	}

	/**
	 * Notiziari che chi scrive dal pannello utente puo usare
	 *
	 * @return array
	 */
	public function allowed_lists()
	{
		return isset($this->config['newsletter_send_lists'])
			? $this->split_ids((string) $this->config['newsletter_send_lists'])
			: array();
	}

	/**
	 * L'invio va approvato prima di partire?
	 *
	 * @return bool
	 */
	public function needs_approval()
	{
		return !isset($this->config['newsletter_send_approval'])
			|| !empty($this->config['newsletter_send_approval']);
	}

	/**
	 * L'utente appartiene ad almeno uno dei gruppi indicati?
	 *
	 * Il gruppo primario si legge dalla riga dell'utente, che e gia in memoria:
	 * nella maggior parte dei casi basta quello ed evita una interrogazione. Il
	 * resto viene chiesto al database una volta sola per richiesta.
	 *
	 * @param array $user_data
	 * @param array $group_ids
	 * @return bool
	 */
	public function user_in_groups(array $user_data, array $group_ids)
	{
		$group_ids = array_filter(array_map('intval', $group_ids));
		$user_id = isset($user_data['user_id']) ? (int) $user_data['user_id'] : 0;

		if (empty($group_ids) || $user_id <= 0)
		{
			return false;
		}

		if (isset($user_data['group_id']) && in_array((int) $user_data['group_id'], $group_ids, true))
		{
			return true;
		}

		if (!isset($this->user_groups[$user_id]))
		{
			$suoi = array();

			$sql = 'SELECT group_id
				FROM ' . USER_GROUP_TABLE . '
				WHERE user_id = ' . $user_id . '
					AND user_pending = 0';
			$result = $this->db->sql_query($sql);

			while ($riga = $this->db->sql_fetchrow($result))
			{
				$suoi[] = (int) $riga['group_id'];
			}
			$this->db->sql_freeresult($result);

			$this->user_groups[$user_id] = $suoi;
		}

		return (bool) array_intersect($group_ids, $this->user_groups[$user_id]);
	}

	/**
	 * @param string $elenco
	 * @return array
	 */
	protected function split_ids($elenco)
	{
		return array_values(array_filter(array_map('intval', explode(',', (string) $elenco))));
	}
}
