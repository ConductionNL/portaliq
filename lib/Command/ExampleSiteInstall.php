<?php

/**
 * Portaliq Example Site Install Command
 *
 * `occ portaliq:example-site:install <site>`: put a shipped example site on
 * this instance. Writes what is missing, never changes what is there, and
 * reads the instance back to say what arrived.
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
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
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
 * Install an example site.
 *
 * Exit codes: 0 everything declared is on the instance, 1 nothing could be
 * done (unknown site, OpenRegister missing), 2 something did not arrive.
 *
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
 */
class ExampleSiteInstall extends Command {
	/**
	 * The word the output uses for each schema.
	 */
	private const WORDS = ['portal' => 'Portal', 'menu' => 'Menus', 'page' => 'Pages', 'newsItem' => 'News items'];

	/**
	 * Constructor.
	 *
	 * @param ExampleSiteCatalogue $catalogue The shipped sites.
	 * @param ExampleSiteInstaller $installer Writes and proves.
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
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	protected function configure(): void {
		$this->setName(name: 'portaliq:example-site:install');
		$this->setDescription(description: 'Install a shipped example site: its portal, menus, pages and news. Existing content is never changed');
		$this->addArgument(name: 'site', mode: InputArgument::REQUIRED, description: 'The example site, for example zuiddrecht');
	}//end configure()

	/**
	 * Install the site and report what arrived.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int 0, 1 or 2; see the class.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-the-install-must-prove-what-arrived
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id   = trim((string)$input->getArgument('site'));
		$site = $this->catalogue->find(id: $id);
		if ($site === null) {
			$output->writeln('<error>No example site "' . $id . '". Sites you can install: ' . implode(', ', $this->catalogue->ids()) . '</error>');

			return 1;
		}

		$report = $this->installer->install(site: $site);
		if ($report['available'] === false) {
			$output->writeln('<error>OpenRegister is not available, so nothing was written. Enable openregister and run this again.</error>');

			return 1;
		}

		foreach ($report['types'] as $schema => $counts) {
			$output->writeln(
				sprintf(
					'%s: %d declared, %d created, %d already there, %d found afterwards',
					self::WORDS[$schema],
					$counts['declared'],
					$counts['created'],
					$counts['kept'],
					$counts['arrived']
				)
			);
		}

		if ($report['themeOffered'] === false) {
			$output->writeln(
				'<comment>The theme app does not offer the set "' . (string)($site['portal']['theme'] ?? '')
				. '". The site shows without its house style until the theme app (thematiq) with that set is installed.</comment>'
			);
		}

		foreach ($report['missing'] as $missing) {
			$output->writeln('<error>Not on the instance: ' . $missing . '</error>');
		}

		foreach ($report['lost'] as $lost) {
			$output->writeln('<error>Written but not kept: ' . $lost . '</error>');
		}

		if ($report['ok'] === false) {
			$output->writeln(
				'<error>The site is not complete. A key that is not kept usually means the portaliq register is older than this app:'
				. ' run "occ maintenance:repair" and install again.</error>'
			);

			return 2;
		}

		$output->writeln('The site is installed. Open it at /index.php/apps/portaliq/site?portal=' . $report['portal']);

		return 0;
	}//end execute()
}//end class
