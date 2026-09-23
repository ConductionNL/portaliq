<?php

/**
 * Portaliq Traffic Reaggregate Command
 *
 * `occ portaliq:traffic:reaggregate`: rebuild every daily record whose raw
 * events are still retained, now, instead of waiting for the aggregation
 * job's one-time back-fill.
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
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
 */

declare(strict_types=1);

namespace OCA\Portaliq\Command;

use OCA\Portaliq\Service\TrafficAggregationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-aggregate the retained days on demand.
 *
 * The same back-fill the aggregation job runs once after an upgrade, safe
 * to repeat: a day is rebuilt from all of its raw events, a day with none
 * left keeps its record, and a day that lost part of its events to the
 * purge keeps its more complete record. Always exits 0; the counts say
 * what happened.
 *
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
 */
class TrafficReaggregate extends Command {

	/**
	 * Constructor.
	 *
	 * @param TrafficAggregationService $aggregation Does the work.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficAggregationService $aggregation,
	) {
		parent::__construct();
	}//end __construct()


	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
	 */
	protected function configure(): void {
		$this->setName(name: 'portaliq:traffic:reaggregate');
		$this->setDescription(description: 'Rebuild the daily traffic records of every day whose raw events are still retained');
		$this->addOption(
			name: 'portal',
			mode: InputOption::VALUE_REQUIRED,
			description: 'Only this portal slug, and the roll-up portals that include it'
		);
	}//end configure()


	/**
	 * Run the back-fill and report.
	 *
	 * @param InputInterface  $input  The options.
	 * @param OutputInterface $output The report.
	 *
	 * @return int Always 0.
	 *
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$only = trim((string)$input->getOption('portal'));
		if ($only === '') {
			$only = null;
		}

		$result = $this->aggregation->backfill(only: $only);
		$output->writeln('Portals: ' . $result['portals']);
		$output->writeln('Days rebuilt: ' . $result['days']);
		$output->writeln('Days kept as they were (no raw events left, or fewer than the record counts): ' . $result['kept']);
		$output->writeln('Roll-up days summed: ' . $result['rollupDays']);

		return 0;
	}//end execute()
}//end class
