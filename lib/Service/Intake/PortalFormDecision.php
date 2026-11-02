<?php

/**
 * Portaliq Portal Form Decision
 *
 * Asks the business rules engine to decide an answer or the next step of a
 * form, on the server.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Duck-typed client for buildiq's rule engine. The rule and its table never
 * leave the server: the browser learns the outcome and the step it opens.
 *
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
 */
class PortalFormDecision {

	/**
	 * What decide() answers when the engine produced an outcome.
	 *
	 * @var string
	 */
	public const DECIDED = 'decided';

	/**
	 * What decide() answers when the engine is not there or did not answer.
	 *
	 * @var string
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * What decide() answers when the engine answered without an outcome.
	 *
	 * @var string
	 */
	public const NO_OUTCOME = 'no_outcome';

	/**
	 * The rule engine's evaluation service, by name only. Nextcloud autoloads
	 * an app's classes while it is enabled, so `class_exists()` is the "is
	 * buildiq on" check.
	 *
	 * @var string
	 */
	public const ENGINE_CLASS = 'OCA\\Buildiq\\Service\\BusinessRuleEngine';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the engine when it is there.
	 * @param LoggerInterface $logger Records an engine that failed.
	 * @param string $engineClass The engine's class name; a test names a fake.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $engineClass = self::ENGINE_CLASS,
	) {
	}//end __construct()

	/**
	 * Decide one step.
	 *
	 * @param array<string, mixed> $decision The step's `decision`: rule, inputs, output, nextStep.
	 * @param array<string, mixed> $answers The answers so far.
	 *
	 * @return array{status: string, outcome: string, output: string, nextStep: string}
	 *         `status` is DECIDED, UNAVAILABLE or NO_OUTCOME; `nextStep` is the step the outcome opens, or ''.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	public function decide(array $decision, array $answers): array {
		$result = ['status' => self::UNAVAILABLE, 'outcome' => '', 'output' => (string)($decision['output'] ?? ''), 'nextStep' => ''];
		$engine = $this->engine();
		if ($engine === null) {
			return $result;
		}

		$inputs = [];
		foreach ((array)($decision['inputs'] ?? []) as $name => $path) {
			$inputs[(string)$name] = $this->read(path: (string)$path, answers: $answers);
		}

		try {
			$answer = $engine->evaluate((string)($decision['rule'] ?? ''), $inputs);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: the rule engine failed', ['reason' => $e->getMessage()]);
			return $result;
		}

		$outcome = '';
		if (is_array($answer) === true && is_scalar($answer['outcome'] ?? null) === true) {
			$outcome = trim((string)$answer['outcome']);
		}

		if ($outcome === '') {
			$result['status'] = self::NO_OUTCOME;
			return $result;
		}

		$result['status']   = self::DECIDED;
		$result['outcome']  = $outcome;
		$map                = (array)($decision['nextStep'] ?? []);
		$result['nextStep'] = '';
		if (is_string($map[$outcome] ?? null) === true) {
			$result['nextStep'] = $map[$outcome];
		}

		return $result;
	}//end decide()

	/**
	 * The answer a dotted path names (`adres.plaats`), or null.
	 *
	 * @param string $path The path.
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return mixed The value.
	 */
	private function read(string $path, array $answers): mixed {
		$value = $answers;
		foreach (explode('.', $path) as $key) {
			if (is_array($value) === false || array_key_exists($key, $value) === false) {
				return null;
			}

			$value = $value[$key];
		}

		return $value;
	}//end read()

	/**
	 * The engine, or null when buildiq is not enabled or it cannot be built.
	 *
	 * @return object|null The engine.
	 */
	private function engine(): ?object {
		if (class_exists($this->engineClass) === false) {
			return null;
		}

		try {
			$engine = $this->container->get($this->engineClass);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: the rule engine cannot be built', ['reason' => $e->getMessage()]);
			return null;
		}

		if (is_object($engine) === true && method_exists($engine, 'evaluate') === true) {
			return $engine;
		}

		return null;
	}//end engine()
}//end class
