<?php

/**
 * Portaliq Traffic Geo Refresh Command
 *
 * `occ portaliq:traffic:geo-refresh`: fetch the geography database from the
 * configured provider now, instead of waiting for the monthly job.
 *
 * @category Command
 * @package  OCA\Portaliq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
 */

declare(strict_types=1);

namespace OCA\Portaliq\Command;

use OCA\Portaliq\Service\Traffic\Geo\GeoRefreshService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Refresh the geography database on demand.
 *
 * Exit 0 when the database was refreshed AND when geography is disabled:
 * an operator who set the provider to none and runs this has nothing to
 * fix. Exit 1 when a download or the verification failed, with the
 * reason on stdout, so a cron wrapper notices.
 *
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
 */
class TrafficGeoRefresh extends Command {

	/**
	 * Constructor.
	 *
	 * @param GeoRefreshService $refresh Does the work.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly GeoRefreshService $refresh,
	) {
		parent::__construct();
	}//end __construct()


	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 */
	protected function configure(): void {
		$this->setName(name: 'portaliq:traffic:geo-refresh');
		$this->setDescription(description: 'Fetch the visitor geography database (DB-IP Lite or MaxMind) into the app data directory now');
	}//end configure()


	/**
	 * Run the refresh and report.
	 *
	 * @param InputInterface  $input  Unused; the command takes no arguments.
	 * @param OutputInterface $output Where the outcome goes.
	 *
	 * @return int 0 when refreshed or disabled, 1 when failed.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates
	 * the signature.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->refresh->refresh();
		$output->writeln('Provider: ' . $result['provider']);
		$output->writeln($result['message']);

		foreach ($this->describe(status: $this->refresh->status()) as $line) {
			$output->writeln($line);
		}

		if ($result['status'] === 'failed') {
			return 1;
		}

		return 0;
	}//end execute()


	/**
	 * The installed database, described.
	 *
	 * @param array<string, mixed> $status What the refresh service reports.
	 *
	 * @return array<int, string> Lines for the output.
	 */
	private function describe(array $status): array {
		if (($status['present'] ?? false) !== true) {
			return ['Database: none installed'];
		}

		$metadata = (array)($status['metadata'] ?? []);

		return [
			'Database: ' . (string)($status['path'] ?? ''),
			'Fetched: ' . (string)($metadata['fetchedAt'] ?? 'unknown') . ', ' . (string)($metadata['databaseType'] ?? 'unknown type'),
			'Attribution: ' . (string)($metadata['attribution'] ?? 'none recorded'),
		];
	}//end describe()
}//end class
