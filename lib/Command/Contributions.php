<?php

/**
 * Portaliq Contributions Command
 *
 * `occ portaliq:contributions [--output=json]`: list every enabled app with
 * whether it contributes to the portal, for which audiences, and how many
 * collections, pages and actions it declares.
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
 * @spec openspec/changes/contributing-apps-census/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Command;

use OCA\Portaliq\Service\ContributionCensus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print the census of contributing apps.
 *
 * @spec openspec/changes/contributing-apps-census/tasks.md#T02
 */
class Contributions extends Command {

	/**
	 * Constructor.
	 *
	 * @param ContributionCensus $census Reads the rows.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContributionCensus $census,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contributing-apps-census/tasks.md#T02
	 */
	protected function configure(): void {
		$this->setName(name: 'portaliq:contributions');
		$this->setDescription(description: 'List every enabled app with what it contributes to the portal, per audience');
		$this->addOption(
			name: 'output',
			mode: InputOption::VALUE_REQUIRED,
			description: 'Output format: plain or json',
			default: 'plain'
		);
	}//end configure()

	/**
	 * Print the census.
	 *
	 * @param InputInterface  $input  The output format.
	 * @param OutputInterface $output Where the rows go.
	 *
	 * @return int 0 when listed, 1 for an unknown output format.
	 *
	 * @spec openspec/changes/contributing-apps-census/specs/portal-contribution-contract/spec.md#requirement-an-administrator-can-see-which-apps-contribute-to-the-portal-req-cac-001
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$format = strtolower(trim((string) $input->getOption('output')));
		if ($format !== 'plain' && $format !== 'json') {
			$output->writeln('<error>Unknown output format "'.$format.'"; use plain or json.</error>');

			return 1;
		}

		$rows = $this->census->rows();
		if ($format === 'json') {
			$output->writeln((string) json_encode($rows, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

			return 0;
		}

		foreach ($rows as $row) {
			$output->writeln(self::line(row: $row));
		}

		return 0;
	}//end execute()

	/**
	 * One app's line.
	 *
	 * @param array<string, mixed> $row A census row: app, status, pattern, error and audiences.
	 *
	 * @return string The line.
	 */
	private static function line(array $row): string {
		$what = match ($row['status']) {
			ContributionCensus::STATUS_ENDPOINTS => 'integrates through its '.$row['pattern'].', not a provider',
			ContributionCensus::STATUS_ERROR => 'provider failed: '.$row['error'],
			ContributionCensus::STATUS_PROVIDER => self::audiences(audiences: $row['audiences']),
			default => 'contributes nothing',
		};

		return $row['app'].': '.$what;
	}//end line()

	/**
	 * A provider's audiences and counts, or that it serves none.
	 *
	 * @param array<int, array<string, mixed>> $audiences The counts per audience.
	 *
	 * @return string The words.
	 */
	private static function audiences(array $audiences): string {
		if ($audiences === []) {
			return 'provider serves no audience';
		}

		$parts = [];
		foreach ($audiences as $counts) {
			$parts[] = $counts['audience'].' ('.$counts['collections'].' collections, '.$counts['pages'].' pages, '.$counts['actions'].' actions)';
		}

		return 'provider for '.implode(separator: '; ', array: $parts);
	}//end audiences()
}//end class
