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
use OCA\Portaliq\Service\Intake\LandingSubmissionDrain;
use OCA\Portaliq\Service\Intake\PortalDraftDrain;
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
	 * @param IntakeQueueDrain      $drain  Does the work.
	 * @param PortalDraftDrain|null $drafts Moves saved `portalDraft` rows into OpenRegister drafts (task 5.3).
	 * @param LandingSubmissionDrain|null $landing Submits stored landing-page submissions into their form's destination.
	 */
	public function __construct(
		private readonly IntakeQueueDrain $drain,
		private readonly ?PortalDraftDrain $drafts = null,
		private readonly ?LandingSubmissionDrain $landing = null,
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
		$this->setDescription(
			description: 'Send the requests still waiting in the old intake queue into their destination, and move saved drafts there as drafts'
		);
		$this->addOption(
			name: 'limit',
			mode: InputOption::VALUE_REQUIRED,
			description: 'How many queued and how many failed entries one run takes',
			default: '200'
		);
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
		$limit = max(1, (int)$input->getOption('limit'));
		if ((bool)$input->getOption('dry-run') === true) {
			$waiting = $this->drain->waiting(limit: $limit);
			$drafts  = (int)$this->drafts?->waiting(limit: $limit);
			$landing = (int)$this->landing?->waiting(limit: $limit);
			$output->writeln('Waiting: ' . $waiting);
			$output->writeln('Drafts waiting: ' . $drafts);
			$output->writeln('Landing-page submissions waiting: ' . $landing);

			return (int)($waiting + $drafts + $landing > 0);
		}

		$result = $this->drain->drain(limit: $limit);

		$output->writeln('Delivered: ' . $result['delivered']);
		$output->writeln('Refused: ' . $result['refused']);
		foreach ($result['reasons'] as $reference => $reason) {
			$output->writeln('  ' . $reference . ': ' . $reason);
		}

		$output->writeln('Still waiting: ' . $result['waiting']);
		$draftsWaiting  = $this->drainDrafts(limit: $limit, output: $output);
		$landingWaiting = $this->drainLanding(limit: $limit, output: $output);
		if ($result['waiting'] === 0 && $draftsWaiting === 0 && $landingWaiting === 0) {
			return 0;
		}

		return 1;
	}//end execute()

	/**
	 * Move saved `portalDraft` rows into OpenRegister drafts and report (task 5.3).
	 *
	 * @param int             $limit  How many rows one run takes.
	 * @param OutputInterface $output The report.
	 *
	 * @return int The rows still waiting.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#5.3
	 */
	private function drainDrafts(int $limit, OutputInterface $output): int {
		if ($this->drafts === null) {
			return 0;
		}

		$result = $this->drafts->drain(limit: $limit);
		$output->writeln('Drafts moved: ' . $result['converted']);
		$output->writeln('Drafts expired: ' . $result['expired']);
		$output->writeln('Drafts kept: ' . $result['refused']);
		foreach ($result['reasons'] as $id => $reason) {
			$output->writeln('  ' . $id . ': ' . $reason);
		}

		$output->writeln('Drafts still waiting: ' . $result['waiting']);
		return $result['waiting'];
	}//end drainDrafts()

	/**
	 * Submit stored landing-page submissions into their form's destination and report (task 4.1).
	 *
	 * @param int             $limit  How many one run takes.
	 * @param OutputInterface $output The report.
	 *
	 * @return int The submissions still waiting.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
	 */
	private function drainLanding(int $limit, OutputInterface $output): int {
		if ($this->landing === null) {
			return 0;
		}

		$result = $this->landing->drain(limit: $limit);
		$output->writeln('Landing-page submissions delivered: ' . $result['delivered']);
		$output->writeln('Landing-page submissions kept: ' . $result['refused']);
		foreach ($result['reasons'] as $id => $reason) {
			$output->writeln('  ' . $id . ': ' . $reason);
		}

		$output->writeln('Landing-page submissions still waiting: ' . $result['waiting']);
		return $result['waiting'];
	}//end drainLanding()
}//end class
