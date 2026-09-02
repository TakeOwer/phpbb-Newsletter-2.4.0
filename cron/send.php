<?php
/**
 *
 * Newsletter. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 salvocortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\newsletter\cron;

/**
 * Invio periodico dei lotti in coda.
 *
 * Il compito viene interpellato spesso, ma e la campagna a decidere se il suo
 * momento e arrivato: l'intervallo fra un lotto e l'altro appartiene alla
 * campagna, non al cron, perche due newsletter avviate nello stesso periodo
 * possono avere cadenze diverse.
 */
class send extends \phpbb\cron\task\base
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \salvocortesiano\newsletter\core\manager */
	protected $manager;

	/**
	 * Constructor
	 *
	 * @param \phpbb\config\config                       $config
	 * @param \salvocortesiano\newsletter\core\manager   $manager
	 */
	public function __construct(\phpbb\config\config $config, \salvocortesiano\newsletter\core\manager $manager)
	{
		$this->config = $config;
		$this->manager = $manager;
	}

	/**
	 * Nome del compito.
	 *
	 * Di norma phpBB lo ricava dall'identificativo del servizio quando compila
	 * il contenitore. Se pero quella compilazione resta incompleta - cosa che
	 * capita quando la cache viene rigenerata a meta, per esempio disattivando
	 * e riattivando l'estensione - il nome resta vuoto e la costruzione
	 * dell'indirizzo del cron fallisce. Dichiararlo qui rende il compito
	 * indipendente da quel passaggio.
	 *
	 * @return string
	 */
	public function get_name()
	{
		return 'salvocortesiano.newsletter.cron.send';
	}

	/**
	 * @return bool
	 */
	public function is_runnable()
	{
		return !empty($this->config['newsletter_enabled']);
	}

	/**
	 * Vero al massimo una volta al minuto.
	 *
	 * Rispondere sempre di si farebbe ripartire il compito a ogni ciclo, e su
	 * un forum frequentato significherebbe una interrogazione al database per
	 * ogni pagina visitata.
	 *
	 * @return bool
	 */
	public function should_run()
	{
		return (int) $this->config['newsletter_last_run'] < (time() - 60);
	}

	/**
	 * Esecuzione
	 */
	public function run()
	{
		// Anche quando non c'e nulla da inviare l'orario va aggiornato: senza,
		// should_run() risponderebbe di si a ogni visita e il compito
		// interrogherebbe la coda di continuo
		$this->config->set('newsletter_last_run', time(), false);

		$this->manager->process();
		$this->manager->prune();
	}
}
