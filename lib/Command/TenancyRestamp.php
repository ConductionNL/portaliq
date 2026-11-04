<?php

/**
 * Portaliq TenancyRestamp command
 *
 * `occ portaliq:tenancy:restamp` reports, per organisation, how many rows of
 * portaliq's organisation-scoped schemas already carry their OpenRegister
 * organisation and how many would be restamped, and lists the rows whose
 * organisation does not resolve. It writes nothing. With `--apply` it
 * restamps the rows it can resolve.
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
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Command;

use OCA\Portaliq\Service\Tenancy\PortalOrganisationRestamp;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Report, or with --apply perform, the organisation restamp.
 *
 * Exit codes: 0 the run finished, 1 OpenRegister is not available or a row
 * could not be written.
 *
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
 */
class TenancyRestamp extends Command {

	/**
	 * What a dry run (0) and an apply (1) say, and which count they show.
	 *
	 * @var array<int, array{intro: string, header: string, column: string}>
	 */
	private const MODES = [
		0 => [
			'intro'  => 'Dry run: nothing was written. Run again with --apply to restamp.',
			'header' => 'Organisation, uuid: already, to restamp, failed',
			'column' => 'toRestamp',
		],
		1 => [
			'intro'  => 'Restamp: rows were written.',
			'header' => 'Organisation, uuid: already, restamped, failed',
			'column' => 'restamped',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param PortalOrganisationRestamp $restamp Counts and restamps the rows.
	 */
	public function __construct(
		private readonly PortalOrganisationRestamp $restamp,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
	 */
	protected function configure(): void {
		$this->setName(name: 'portaliq:tenancy:restamp');
		$this->setDescription(description: 'Report which portal rows lack their OpenRegister organisation. Writes nothing unless --apply is given');
		$this->addOption(name: 'apply', mode: InputOption::VALUE_NONE, description: 'Give the rows whose organisation resolves that organisation');
	}//end configure()

	/**
	 * Run the report or the restamp and print it.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int 0 or 1; see the class.
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$report = $this->restampOrReport(apply: ($input->getOption('apply') === true));
		} catch (RuntimeException $e) {
			$output->writeln('<error>'.$e->getMessage().'</error>');
			return 1;
		}

		$failed = $this->printOrganisations(report: $report, output: $output);
		$this->printFindings(report: $report, output: $output);
		if ($failed > 0) {
			return 1;
		}

		return 0;
	}//end execute()

	/**
	 * The report, or the restamp when asked.
	 *
	 * @param bool $apply Whether to write.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- the --apply option, read once in execute().
	 */
	private function restampOrReport(bool $apply): array {
		if ($apply === true) {
			return $this->restamp->apply();
		}

		return $this->restamp->report();
	}//end restampOrReport()

	/**
	 * Print the counts per organisation.
	 *
	 * @param array<string, mixed> $report The run's report.
	 * @param OutputInterface      $output The command output.
	 *
	 * @return int How many writes failed.
	 */
	private function printOrganisations(array $report, OutputInterface $output): int {
		$failed = 0;
		$mode   = self::MODES[(int)($report['apply'] === true)];
		$column = $mode['column'];
		$output->writeln($mode['intro']);
		$output->writeln($mode['header']);
		foreach ($report['organisations'] as $slug => $counts) {
			$output->writeln('  '.$slug.', '.$counts['uuid'].': '.$counts['already'].', '.$counts[$column].', '.$counts['failed']);
			$failed += $counts['failed'];
		}

		if ($report['organisations'] === []) {
			$output->writeln('  (no rows with an organisation that resolves)');
		}

		return $failed;
	}//end printOrganisations()

	/**
	 * Print the rows that do not resolve and the schemas that could not be read.
	 *
	 * @param array<string, mixed> $report The run's report.
	 * @param OutputInterface      $output The command output.
	 *
	 * @return void
	 */
	private function printFindings(array $report, OutputInterface $output): void {
		$output->writeln('Rows whose organisation does not resolve, left as they are: '.count($report['unresolved']));
		foreach ($report['unresolved'] as $row) {
			$output->writeln('  '.$row['schema'].' '.$row['uuid'].': '.$row['organisation']);
		}

		$output->writeln('Rows stored in another organisation than they name, left for a person: '.count($report['elsewhere']));
		foreach ($report['elsewhere'] as $row) {
			$output->writeln('  '.$row['schema'].' '.$row['uuid'].': names '.$row['organisation'].', stored in '.$row['stored']);
		}

		foreach ($report['unreadable'] as $schema => $reason) {
			$output->writeln('<comment>Schema '.$schema.' was not read completely: '.$reason.'</comment>');
		}
	}//end printFindings()
}//end class
