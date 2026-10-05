<?php

/**
 * Portaliq Example Resident Remove Command
 *
 * `occ portaliq:example-resident:remove <site>`: take an example resident off
 * this instance again. Deletes what the install recorded as its own, and
 * nothing else.
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
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
 */

declare(strict_types=1);

namespace OCA\Portaliq\Command;

use OCA\Portaliq\Service\ExampleResident\ExampleResidentCatalogue;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentRemover;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
/**
 * Remove an example resident.
 *
 * Exit codes: 0 everything recorded is gone, 1 nothing could be done (unknown
 * site, no record, OpenRegister missing), 2 something could not be deleted.
 *
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
 */
class ExampleResidentRemove extends Command {
	/**
	 * What the output says per part, by outcome.
	 */
	private const LINES = [
		'account' => [
			'deleted'       => 'Portal account: deleted',
			'kept-not-ours' => 'Portal account: none recorded, nothing deleted',
			'failed'        => '<error>Portal account: could not be deleted</error>',
		],
		'signIn'  => [
			'withdrawn'     => 'Sign-in mode: taken off the portal',
			'kept-not-ours' => 'Sign-in mode: the portal offered it before the install, so it stays',
			'failed'        => '<error>Sign-in mode: could not be taken off the portal</error>',
		],
		'user'    => [
			'deleted'       => 'Nextcloud account: deleted',
			'kept-not-ours' => 'Nextcloud account: not made by the install, so it stays',
			'failed'        => '<error>Nextcloud account: could not be deleted</error>',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ExampleResidentCatalogue $catalogue The shipped residents.
	 * @param ExampleResidentRemover   $remover   Deletes from the install's own record.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleResidentCatalogue $catalogue,
		private readonly ExampleResidentRemover $remover,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
	 */
	protected function configure(): void {
		$this->setName(name: 'portaliq:example-resident:remove');
		$this->setDescription(description: 'Remove an example resident: only what the install created is deleted');
		$this->addArgument(name: 'site', mode: InputArgument::REQUIRED, description: 'The example site, for example zuiddrecht');
	}//end configure()

	/**
	 * Remove the resident and report what was deleted.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int 0, 1 or 2; see the class.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
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

		$report = $this->remover->remove(id: $id, slug: (string)$resident['portal']);
		if ($report['recorded'] === false) {
			$output->writeln('<error>Nothing is recorded for "' . $id . '": it was not installed with this command, or it is already removed.</error>');
			return 1;
		}

		if ($report['available'] === false) {
			$output->writeln('<error>OpenRegister is not available, so nothing was deleted.</error>');
			return 1;
		}

		foreach ($report['types'] as $type => $counts) {
			$output->writeln(sprintf('%s: %d deleted, %d already gone', $type, $counts['deleted'], $counts['gone']));
		}

		foreach (['account', 'signIn', 'user'] as $part) {
			$output->writeln(self::LINES[$part][$report[$part]]);
		}

		foreach ($report['failed'] as $failed) {
			if (isset(self::LINES[$failed]) === false) {
				$output->writeln('<error>Could not be deleted: ' . $failed . '</error>');
			}
		}

		if ($report['failed'] !== []) {
			$output->writeln(
				'<comment>OpenRegister keeps an archive record, such as a case, until its retention rule removes it.'
				. ' What is left stays in its app and stays recorded here: a new install for the same account id uses it again.</comment>'
			);
			return 2;
		}

		return 0;
	}//end execute()
}//end class
