<?php

/**
 * Initialize Actions Repair Step
 *
 * Seeds the ADR-023 action-authorization matrix from lib/actions.seed.json,
 * on install and on every upgrade. An action already in the stored matrix is
 * never touched — an admin's customisation wins — while an action the seed
 * gained since the last upgrade is added with its seed default, so a new
 * gate does not silently fall back to the service's admin-only default
 * without ever appearing in the matrix.
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
 * @link https://conduction.nl
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Repair;

use OCA\Portaliq\Service\ActionAuthService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Merge the seed's missing actions into the action-authorization matrix.
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
class InitializeActions implements IRepairStep {
	private const SEED_PATH = __DIR__ . '/../actions.seed.json';

	/**
	 * Constructor.
	 *
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private ActionAuthService $actionAuth,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair-step name.
	 *
	 * @return string
	 *
	 * @spec openspec/architecture/adr-023-action-authorization.md
	 */
	public function getName(): string {
		return 'Initialize action-authorization matrix (ADR-023)';
	}//end getName()

	/**
	 * Add every seed action the stored matrix lacks; keep every action it has.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/architecture/adr-023-action-authorization.md
	 */
	public function run(IOutput $output): void {
		$seed = $this->readSeed(output: $output);
		if ($seed === null) {
			return;
		}

		$matrix = $this->actionAuth->getMatrix();
		$added  = [];
		foreach ($seed as $action => $groups) {
			if (is_string($action) === false || is_array($groups) === false || array_key_exists($action, $matrix) === true) {
				continue;
			}

			$matrix[$action] = $groups;
			$added[]         = $action;
		}

		if (count($added) === 0) {
			$output->info(sprintf('Action matrix already holds every seeded action (%d) — preserving.', count($matrix)));
			return;
		}

		try {
			$this->actionAuth->setMatrix($matrix);
		} catch (\JsonException $e) {
			$output->warning('Failed to write matrix: ' . $e->getMessage());
			return;
		}

		$message = sprintf('Added %d seeded action(s) to the action matrix: %s', count($added), implode(', ', $added));
		$output->info($message);
		$this->logger->info('[portaliq] ADR-023 ' . $message);
	}//end run()

	/**
	 * The seed's `actions` object, or null (with a warning) when it cannot be read.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return array<mixed>|null
	 */
	private function readSeed(IOutput $output): ?array {
		if (file_exists(self::SEED_PATH) === false) {
			$output->warning('actions.seed.json not found — matrix left as is (unknown actions are admin-only).');
			$this->logger->warning('[portaliq] ADR-023 seed file missing at ' . self::SEED_PATH);
			return null;
		}

		$raw = file_get_contents(self::SEED_PATH);
		if ($raw === false) {
			$output->warning('Could not read actions.seed.json — matrix left as is (unknown actions are admin-only).');
			return null;
		}

		try {
			$parsed = json_decode($raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$output->warning('actions.seed.json invalid JSON: ' . $e->getMessage());
			$this->logger->error('[portaliq] ADR-023 seed malformed: ' . $e->getMessage());
			return null;
		}

		$actions = ($parsed['actions'] ?? null);
		if (is_array($actions) === false) {
			$output->warning('actions.seed.json missing `actions` object — matrix left as is.');
			return null;
		}

		return $actions;
	}//end readSeed()
}//end class
