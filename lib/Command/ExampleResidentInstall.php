<?php

/**
 * Portaliq Example Resident Install Command
 *
 * `occ portaliq:example-resident:install <site>`: give an installed example
 * site its resident, so the area behind the sign-in button has something to
 * show. Makes a Nextcloud account to sign in with, a portal account, the
 * sign-in mode on the portal and the resident's cases, and reads the instance
 * back to say what arrived.
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
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
 */

declare(strict_types=1);

namespace OCA\Portaliq\Command;

use OCA\Portaliq\Service\ExampleResident\ExampleResidentCatalogue;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
/**
 * Install an example resident.
 *
 * Exit codes: 0 everything declared is on the instance, 1 nothing was written
 * (unknown site, OpenRegister or the example site missing, the account id is
 * somebody's), 2 something did not arrive.
 *
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
 */
class ExampleResidentInstall extends Command {
	/**
	 * Constructor.
	 *
	 * @param ExampleResidentCatalogue $catalogue The shipped residents.
	 * @param ExampleResidentInstaller $installer Writes and proves.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleResidentCatalogue $catalogue,
		private readonly ExampleResidentInstaller $installer,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
	 */
	protected function configure(): void {
		$this->setName(name: 'portaliq:example-resident:install');
		$this->setDescription(
			description: 'Give an installed example site its resident: an account to sign in with and a few cases. For a demo or test instance'
		);
		$this->addArgument(name: 'site', mode: InputArgument::REQUIRED, description: 'The example site, for example zuiddrecht');
		$this->addOption(
			name: 'user',
			mode: InputOption::VALUE_REQUIRED,
			description: 'The id of the Nextcloud account to make for the resident, when the shipped one is taken'
		);
		$this->addOption(
			name: 'password-from-env',
			mode: InputOption::VALUE_NONE,
			description: 'Read the password of the new account from the environment variable OC_PASS instead of making one'
		);
	}//end configure()

	/**
	 * Install the resident and report what arrived.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int 0, 1 or 2; see the class.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-install-must-prove-what-arrived
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id       = trim((string)$input->getArgument('site'));
		$resident = $this->catalogue->find(id: $id);
		if ($resident === null) {
			$output->writeln(
				'<error>No example resident for "' . $id . '". Sites that have one: ' . implode(', ', $this->catalogue->ids()) . '</error>'
			);
			return 1;
		}

		$password = '';
		if ($input->getOption('password-from-env') === true) {
			$password = (string)getenv('OC_PASS');
			if ($password === '') {
				$output->writeln('<error>--password-from-env was given, but the environment variable OC_PASS is empty.</error>');
				return 1;
			}
		}

		$report = $this->installer->install(resident: $resident, userId: trim((string)$input->getOption('user')), password: $password);
		if ($report['stopped'] !== '') {
			$output->writeln('<error>' . $report['stopped'] . '</error>');
			return 1;
		}

		$this->printReport(resident: $resident, report: $report, output: $output);
		if ($report['ok'] === false) {
			$output->writeln('<error>The example resident is not complete. See the lines above.</error>');
			return 2;
		}

		return 0;
	}//end execute()

	/**
	 * Write what the install did, per part and per type, then what went wrong, the password and where to sign in.
	 *
	 * @param array<string, mixed> $resident The declaration.
	 * @param array<string, mixed> $report   The installer's report.
	 * @param OutputInterface      $output   The command output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-install-must-prove-what-arrived
	 */
	private function printReport(array $resident, array $report, OutputInterface $output): void {
		$output->writeln('Nextcloud account ' . $report['userId'] . ': ' . self::word(outcome: $report['user']));
		$output->writeln('Portal account ' . $report['userId'] . ': ' . self::word(outcome: $report['account']));
		$output->writeln('Sign-in mode ' . $resident['signIn']['mode'] . ' on portal ' . $report['portal'] . ': ' . self::word(outcome: $report['signIn']));
		foreach ($report['types'] as $type => $counts) {
			$output->writeln(
				sprintf(
					'%s: %d declared, %d created, %d already there, %d found afterwards',
					$type,
					$counts['declared'],
					$counts['created'],
					$counts['kept'],
					$counts['arrived']
				)
			);
		}

		$problems = [
			'Not written: '          => $report['dropped'],
			'Not on the instance: '  => $report['missing'],
			'Written but not kept: ' => $report['lost'],
		];
		foreach ($problems as $words => $lines) {
			foreach ($lines as $line) {
				$output->writeln('<error>' . $words . $line . '</error>');
			}
		}

		if ($report['password'] !== '') {
			$output->writeln('The password of ' . $report['userId'] . ' is shown once, here: ' . $report['password']);
		}

		$output->writeln(
			'Sign in at /index.php/apps/portaliq/site?portal=' . $report['portal'] . '&route=/mijn with the card "'
			. (string)($resident['signIn']['label']['title'] ?? $resident['signIn']['mode']) . '".'
		);
	}//end printReport()

	/**
	 * The words for an outcome.
	 *
	 * @param string $outcome `created`, `added` or `kept`.
	 *
	 * @return string
	 */
	private static function word(string $outcome): string {
		if ($outcome === 'kept') {
			return 'already there';
		}

		return $outcome;
	}//end word()
}//end class
