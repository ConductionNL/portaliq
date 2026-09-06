<?php

/**
 * Portaliq Traffic Token Command
 *
 * `occ portaliq:traffic:token <portal>`: mint the bearer token a trusted
 * backend presents to POST /api/traffic/server for one portal. The token
 * is printed once; the portal record keeps only its hash.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
 */

declare(strict_types=1);

namespace OCA\Portaliq\Command;

use OCA\Portaliq\Service\Traffic\TrafficServerToken;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mint a portal's server token.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
 */
class TrafficToken extends Command {

	/**
	 * Constructor.
	 *
	 * @param TrafficServerToken $tokens Mints and stores.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficServerToken $tokens,
	) {
		parent::__construct();
	}//end __construct()


	/**
	 * Name and describe the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
	 */
	protected function configure(): void {
		$this->setName(name: 'portaliq:traffic:token');
		$this->setDescription(description: 'Mint the server-side tracking token for a portal; it is shown once and stored hashed');
		$this->addArgument(name: 'portal', mode: InputArgument::REQUIRED, description: 'The portal slug');
	}//end configure()


	/**
	 * Mint the token and print it.
	 *
	 * @param InputInterface  $input  The portal slug.
	 * @param OutputInterface $output Where the token goes, once.
	 *
	 * @return int 0 when minted, 1 when the portal is unknown or the write failed.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$slug = trim((string)$input->getArgument('portal'));
		$token = $this->tokens->issue(slug: $slug);
		if ($token === null) {
			$output->writeln('<error>No published portal with slug "' . $slug . '", or the portal record could not be written.</error>');

			return 1;
		}

		$output->writeln('Token for ' . $slug . ' (shown once, stored hashed; any previous token no longer works):');
		$output->writeln($token);
		$output->writeln(
			'Send it as "Authorization: Bearer <token>" to POST /index.php/apps/portaliq/api/traffic/server'
			. ' with "portal": "' . $slug . '" in the body.'
		);

		return 0;
	}//end execute()
}//end class
