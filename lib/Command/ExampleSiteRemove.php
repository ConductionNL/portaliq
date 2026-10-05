<?php

/**
 * Portaliq Example Site Remove Command
 *
 * `occ portaliq:example-site:remove <site>`: take an installed example site
 * off this instance. Deletes exactly what the install created and leaves
 * everything else, also inside the same portal.
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
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
 */

declare(strict_types=1);

namespace OCA\Portaliq\Command;

use OCA\Portaliq\Service\ExampleSite\ExampleSiteCatalogue;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Remove an example site.
 *
 * Exit codes: 0 everything the install created is gone, 1 nothing could be
 * done (unknown site, no record of an install), 2 something could not be
 * deleted or the portal was kept because it holds other content.
 *
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
 */
class ExampleSiteRemove extends Command {
	/**
	 * The word the output uses for each schema.
	 */
	private const WORDS = ['menu' => 'Menus', 'page' => 'Pages', 'newsItem' => 'News items'];

	/**
	 * What the output says about the portal, per outcome.
	 */
	private const PORTAL_LINES = [
		'deleted'       => 'Portal: deleted',
		'kept-not-ours' => 'Portal: kept. It was there before the example site was installed',
		'kept-content'  => 'Portal: kept. It still holds menus or pages that are not from the example site',
		'failed'        => 'Portal: could not be deleted',
	];

	/**
	 * Constructor.
	 *
	 * @param ExampleSiteCatalogue $catalogue The shipped sites.
	 * @param ExampleSiteInstaller $installer Deletes from its own record.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSiteCatalogue $catalogue,
		private readonly ExampleSiteInstaller $installer,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
	 */
	protected function configure(): void {
		$this->setName(name: 'portaliq:example-site:remove');
		$this->setDescription(description: 'Remove an installed example site: only what the install created is deleted');
		$this->addArgument(name: 'site', mode: InputArgument::REQUIRED, description: 'The example site, for example zuiddrecht');
	}//end configure()

	/**
	 * Remove the site and report what was deleted.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int 0, 1 or 2; see the class.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id   = trim((string)$input->getArgument('site'));
		$site = $this->catalogue->find(id: $id);
		if ($site === null) {
			$output->writeln('<error>No example site "' . $id . '". Sites this app ships: ' . implode(', ', $this->catalogue->ids()) . '</error>');

			return 1;
		}

		$report = $this->installer->remove(site: $id, slug: (string)$site['portal']['slug']);
		if ($report['recorded'] === false) {
			$output->writeln('<error>Nothing is recorded for "' . $id . '": it was not installed with this command, or it is already removed.</error>');

			return 1;
		}

		if ($report['deleted'] === []) {
			$output->writeln('<error>OpenRegister is not available, so nothing was deleted.</error>');

			return 1;
		}

		foreach (self::WORDS as $schema => $word) {
			$output->writeln(
				sprintf('%s: %d deleted, %d already gone', $word, $report['deleted'][$schema], $report['gone'][$schema])
			);
		}

		$output->writeln(self::PORTAL_LINES[$report['portal']]);
		foreach ($report['failed'] as $failed) {
			$output->writeln('<error>Could not be deleted: ' . $failed . '</error>');
		}

		if ($report['failed'] !== [] || $report['portal'] === 'failed' || $report['portal'] === 'kept-content') {
			return 2;
		}

		return 0;
	}//end execute()
}//end class
