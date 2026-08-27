<?php

/**
 * Portaliq Initialize Settings Repair Step
 *
 * Repair step that initializes Portaliq register and schemas on install/upgrade.
 *
 * @category Repair
 * @package  OCA\Portaliq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Repair;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\SettingsService;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes Portaliq configuration via SettingsService.
 *
 * Also generates the portal auth edge's dedicated `jwt_signing_secret` on
 * first install (portal-auth-edge-session-hardening) — idempotent, only when
 * unset, so a fresh install is safe-by-default without an operator having to
 * know to configure anything.
 */
class InitializeSettings implements IRepairStep {
	/**
	 * Minimum acceptable secret length (matches PortalJwtService's own guard).
	 */
	private const MIN_SECRET_LENGTH = 32;

	/**
	 * Constructor for InitializeSettings.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param LoggerInterface $logger The logger interface
	 * @param IConfig $config App config, for the signing secret
	 * @param ISecureRandom $random Cryptographically secure generator
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settingsService,
		private LoggerInterface $logger,
		private IConfig $config,
		private ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Initialize Portaliq register and schemas via ConfigurationService';
	}//end getName()

	/**
	 * Run the repair step to initialize Portaliq configuration.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/specs/configuration-initialization/spec.md#REQ-INIT-002
	 */
	public function run(IOutput $output): void {
		$output->info('Initializing Portaliq configuration...');

		$this->ensureSigningSecret(output: $output);

		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning(
				'OpenRegister is not installed or enabled. Skipping auto-configuration.'
			);
			$this->logger->warning(
				'Portaliq: OpenRegister not available, skipping register initialization'
			);
			return;
		}

		try {
			$result = $this->settingsService->loadConfiguration(force: false);

			if ($result['success'] === true) {
				$version = ($result['version'] ?? 'unknown');
				$output->info(
					'Portaliq configuration imported successfully (version: ' . $version . ')'
				);
				return;
			}

			$message = ($result['message'] ?? 'unknown error');
			$output->warning(
				'Portaliq configuration import issue: ' . $message
			);
		} catch (\Throwable $e) {
			$output->warning('Could not auto-configure Portaliq: ' . $e->getMessage());
			$this->logger->error(
				'Portaliq initialization failed',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end run()

	/**
	 * Generate a dedicated `jwt_signing_secret` for the portal auth edge, only
	 * when unset or too short — idempotent on re-run (upgrades never
	 * overwrite an already-configured secret, which would invalidate every
	 * live session).
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.2
	 */
	private function ensureSigningSecret(IOutput $output): void {
		$existing = (string)$this->config->getAppValue(Application::APP_ID, 'jwt_signing_secret', '');
		if ($existing !== '' && strlen($existing) >= 16) {
			// Already configured — never overwrite (would invalidate every
			// live portal session signed with it).
			return;
		}

		$secret = $this->random->generate(
			self::MIN_SECRET_LENGTH,
			(ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
		);
		$this->config->setAppValue(Application::APP_ID, 'jwt_signing_secret', $secret);
		$output->info('Portaliq: generated a dedicated portal auth-edge signing secret.');
		$this->logger->info('Portaliq: generated a dedicated jwt_signing_secret on install/upgrade');
	}//end ensureSigningSecret()
}//end class
