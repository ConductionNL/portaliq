<?php

/**
 * Portaliq Portal Provision Command
 *
 * `occ portaliq:portal:provision`: the draft portal of one organisation.
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
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t06
 */

declare(strict_types=1);

namespace OCA\Portaliq\Command;

use OCA\Portaliq\Service\Tenancy\PortalProvisioningService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create a draft portal for an organisation.
 *
 * Exit codes: 0 the portal was created, 1 it was refused (invalid input, a
 * slug or host that is taken) or could not be written.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t06
 */
class PortalProvision extends Command {

	/**
	 * What the command says for each refusal.
	 *
	 * @var array<string, string>
	 */
	private const REFUSALS = [
		PortalProvisioningService::INVALID     => 'The organisation, slug, title or host is missing or not valid. '
			.'The slug is lower case letters, digits and hyphens; the host has no scheme or port.',
		PortalProvisioningService::SLUG_TAKEN  => 'This portal slug is already used by another portal.',
		PortalProvisioningService::HOST_TAKEN  => 'This web address is already used by another portal.',
		PortalProvisioningService::UNAVAILABLE => 'OpenRegister is not available, so nothing was written. Enable openregister and run this again.',
		PortalProvisioningService::FAILED      => 'The portal could not be written. Nothing else was created.',
	];

	/**
	 * Constructor.
	 *
	 * @param PortalProvisioningService $provisioning Creates the portal.
	 */
	public function __construct(
		private readonly PortalProvisioningService $provisioning,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t06
	 */
	protected function configure(): void {
		$this->setName(name: 'portaliq:portal:provision');
		$this->setDescription(description: 'Create the draft portal of an organisation: portal, host, menu and home page. It is not published');
		$this->addArgument(name: 'organisation', mode: InputArgument::REQUIRED, description: 'The organisation the portal belongs to');
		$this->addArgument(name: 'slug', mode: InputArgument::REQUIRED, description: 'The portal slug, for example zeist');
		$this->addArgument(name: 'title', mode: InputArgument::REQUIRED, description: 'The portal title, for example "Gemeente Zeist"');
		$this->addArgument(name: 'host', mode: InputArgument::REQUIRED, description: 'The host the portal answers on, for example mijn.zeist.example');
	}//end configure()

	/**
	 * Create the portal and print the DNS record that verifies its host.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int 0 or 1; see the class.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t06
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->provisioning->provision(
			organisation: (string)$input->getArgument('organisation'),
			slug: (string)$input->getArgument('slug'),
			title: (string)$input->getArgument('title'),
			host: (string)$input->getArgument('host')
		);

		if ($result['status'] !== PortalProvisioningService::CREATED || $result['dns'] === null) {
			$output->writeln('<error>'.(self::REFUSALS[$result['status']] ?? self::REFUSALS[PortalProvisioningService::FAILED]).'</error>');

			return 1;
		}

		$slug         = (string)$input->getArgument('slug');
		$organisation = (string)$input->getArgument('organisation');
		$output->writeln('Created the draft portal "'.$slug.'" for organisation "'.$organisation.'". It is not published.');
		$output->writeln('Publish this DNS TXT record to verify the host:');
		$output->writeln('  name:  '.$result['dns']['name']);
		$output->writeln('  value: '.$result['dns']['value']);

		return 0;
	}//end execute()
}//end class
