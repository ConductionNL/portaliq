<?php

/**
 * Portaliq command: drain the old intake queue
 *
 * `occ portaliq:intake:drain` sends every request still waiting in the old
 * intake queue into the destination its form names, and says how many
 * arrived, how many were refused and why, and how many still wait.
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
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Command;

use OCA\Portaliq\Service\Intake\IntakeQueueDrain;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drain the intake queue into destinations.
 *
 * Exits 0 when nothing is left waiting, 1 when entries remain (refused, or
 * past the limit), so a script can repeat it until the queue may be removed.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
 */
class IntakeDrain extends Command {
	/**
	 * Constructor.
	 *
	 * @param IntakeQueueDrain $drain Does the work.
	 */
	public function __construct(
		private readonly IntakeQueueDrain $drain,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
	 */
	protected function configure(): void {
		$this->setName(name: 'portaliq:intake:drain');
		$this->setDescription(description: 'Send the requests still waiting in the old intake queue into their destination');
		$this->addOption(name: 'limit', mode: InputOption::VALUE_REQUIRED, description: 'How many queued and how many failed entries one run takes', default: '200');
		$this->addOption(name: 'dry-run', mode: InputOption::VALUE_NONE, description: 'Count the waiting entries, send nothing');
	}//end configure()

	/**
	 * Drain and report.
	 *
	 * @param InputInterface  $input  The options.
	 * @param OutputInterface $output The report.
	 *
	 * @return int 0 when nothing waits any more, else 1.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$limit  = max(1, (int)$input->getOption('limit'));
		$result = $this->drain->drain(limit: $limit, dryRun: (bool)$input->getOption('dry-run'));

		$output->writeln('Delivered: ' . $result['delivered']);
		$output->writeln('Refused: ' . $result['refused']);
		foreach ($result['reasons'] as $reference => $reason) {
			$output->writeln('  ' . $reference . ': ' . $reason);
		}

		$output->writeln('Still waiting: ' . $result['waiting']);
		if ($result['waiting'] === 0) {
			return 0;
		}

		return 1;
	}//end execute()
}//end class
