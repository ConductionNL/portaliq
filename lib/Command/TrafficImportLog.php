<?php

/**
 * Portaliq Traffic Import Log Command
 *
 * `occ portaliq:traffic:import-log <portal> <file> [--format=combined|json]
 * [--host=https://example.org]`: read an Apache or Nginx access log as
 * page views for a portal, skipping assets and bots.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
 */

declare(strict_types=1);

namespace OCA\Portaliq\Command;

use OCA\Portaliq\Service\Traffic\TrafficLogImporter;
use OCA\Portaliq\Service\Traffic\TrafficLogParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Import an access log.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
 */
class TrafficImportLog extends Command {

	/**
	 * Constructor.
	 *
	 * @param TrafficLogImporter $importer Does the work.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficLogImporter $importer,
	) {
		parent::__construct();
	}//end __construct()


	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
	 */
	protected function configure(): void {
		$this->setName(name: 'portaliq:traffic:import-log');
		$this->setDescription(description: 'Import an Apache or Nginx access log as page views for a portal (assets and bots skipped)');
		$this->addArgument(name: 'portal', mode: InputArgument::REQUIRED, description: 'The portal slug');
		$this->addArgument(name: 'file', mode: InputArgument::REQUIRED, description: 'The log file, or - for standard input');
		$this->addOption(name: 'format', mode: InputOption::VALUE_REQUIRED, description: 'combined (Apache/Nginx) or json (one object per line)', default: 'combined');
		$this->addOption(name: 'host', mode: InputOption::VALUE_REQUIRED, description: 'The site origin the paths belong to, such as https://example.org', default: 'https://localhost');
	}//end configure()


	/**
	 * Import and report.
	 *
	 * @param InputInterface  $input  The arguments.
	 * @param OutputInterface $output The report.
	 *
	 * @return int 0 when imported, 1 when the portal, the file or the format is wrong.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$format = (string)$input->getOption('format');
		if (in_array($format, TrafficLogParser::FORMATS, true) === false) {
			$output->writeln('<error>Unknown format "' . $format . '"; use combined or json.</error>');

			return 1;
		}

		$file = (string)$input->getArgument('file');
		$stream = ($file === '-') ? fopen('php://stdin', 'r') : @fopen($file, 'r');
		if (is_resource($stream) === false) {
			$output->writeln('<error>Cannot read "' . $file . '".</error>');

			return 1;
		}

		try {
			$outcome = $this->importer->import(
				slug: trim((string)$input->getArgument('portal')),
				stream: $stream,
				format: $format,
				host: (string)$input->getOption('host')
			);
		} finally {
			fclose($stream);
		}

		if ($outcome === null) {
			$output->writeln('<error>No published portal with slug "' . (string)$input->getArgument('portal') . '".</error>');

			return 1;
		}

		$output->writeln('Lines read: ' . $outcome['lines']);
		$output->writeln('Page views found: ' . $outcome['views'] . ' (skipped ' . $outcome['skipped'] . ' non-page, asset, error or bot lines; ' . $outcome['duplicates'] . ' duplicates)');
		$output->writeln('Accepted: ' . $outcome['accepted']);
		foreach ($outcome['refused'] as $reason => $count) {
			$output->writeln('Refused (' . $reason . '): ' . $count);
		}

		$output->writeln('The next aggregation run recomputes the days these views fall on. Importing the same file again counts its views again.');

		return 0;
	}//end execute()
}//end class
