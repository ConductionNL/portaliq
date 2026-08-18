<?php

/**
 * Portaliq Portal Shared Theme
 *
 * Adopting a token set that arrived from another instance.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A shared theme becomes a portal's OWN tokens, or it is refused by name.
 *
 * ADOPTION COPIES, IT DOES NOT LINK — and that is the answer to "what happens
 * when a shared theme is withdrawn while a portal is using it" (task 4.3).
 *
 * A link would mean another instance can change, or remove, what a live
 * government portal looks like, at a moment nobody here chose. Withdrawal
 * upstream would then either restyle the portal or strip it — and the second
 * is what the task forbids. Copying the declarations into the portal's own
 * `tokens` makes adoption a decision with a result: the portal keeps rendering
 * exactly what it was adopted with, and a withdrawal is something an operator
 * is TOLD about rather than something that happens to their visitors.
 *
 * SHARED CONFIGURATION IS INPUT FROM ANOTHER INSTANCE, so every declaration
 * goes through the theme app's `CustomTokenSetValidator` before it can be
 * stored (task 4.2). That validator is the same one the upload path uses; a
 * second opinion about what a safe declaration looks like is a second answer
 * to a security question.
 *
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */
class PortalSharedTheme {

	/**
	 * The theme app's declaration validator.
	 */
	private const VALIDATOR = 'OCA\\NLDesign\\Service\\CustomTokenSetValidator';

	/**
	 * The theme app's shareable-config type.
	 */
	private const CONFIG_TYPE = 'OCA\\NLDesign\\Service\\Config\\NlDesignThemeShareableConfigType';


	/**
	 * @param ContainerInterface $container For resolving the theme app's services.
	 * @param LoggerInterface    $logger    Records a refusal.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Adopt a shared theme bundle as one portal's own tokens.
	 *
	 * REFUSES VISIBLY (task 4.4). A hostile or unusable bundle returns a named
	 * reason rather than an empty token map: a portal that silently adopted
	 * nothing would render unstyled, and "the theme did not apply" is the one
	 * outcome an operator cannot distinguish from "the theme is subtle".
	 *
	 * @param array<string, mixed> $bundle The shared bundle.
	 * @param string               $slug   The portal slug, for `--{slug}-*` extras.
	 *
	 * @return array{adopted: bool, tokens: array<string, string>, refusal: string, skipped: array<int, string>}
	 *
	 * @spec openspec/changes/nldesign-theme-integration/tasks.md
	 */
	public function adopt(array $bundle, string $slug): array {
		$validator = $this->service(id: self::VALIDATOR, method: 'validateDeclarations');
		if ($validator === null) {
			return $this->refuse(reason: 'the theme app is not available to validate this bundle');
		}

		$declarations = $this->declarationsFrom(bundle: $bundle);
		if ($declarations === []) {
			return $this->refuse(reason: 'the bundle carries no token declarations');
		}

		try {
			$split = $validator->validateDeclarations($declarations, $slug);
		} catch (Throwable $e) {
			return $this->refuse(reason: 'validation failed: ' . $e->getMessage());
		}

		// NULL IS A HARD FAILURE, not an empty result. The validator's own
		// contract: a forbidden construct in an accepted declaration returns
		// null and sets `lastError`. Treating that as "nothing to adopt" would
		// turn a refused hostile bundle into a silent no-op.
		if ($split === null) {
			$error = 'a declaration was refused';
			if (method_exists($validator, 'getLastError') === true) {
				$last = $validator->getLastError();
				if (is_array($last) === true && isset($last['message']) === true) {
					$error = (string)$last['message'];
				}
			}

			$this->logger->warning('[portaliq] shared theme refused', ['reason' => $error]);
			return $this->refuse(reason: $error);
		}

		$accepted = (array)($split['accepted'] ?? []);
		if ($accepted === []) {
			return $this->refuse(reason: 'no declaration in this bundle is adoptable');
		}

		return [
			'adopted' => true,
			'tokens' => $accepted,
			'refusal' => '',
			// SKIPPED IS REPORTED, NOT DISCARDED. A bundle carrying Nextcloud's
			// own `--color-*` variables is adopted minus those, and an operator
			// who expected them needs to know they did not travel.
			'skipped' => array_values((array)($split['skipped'] ?? [])),
		];
	}//end adopt()


	/**
	 * The declarations inside a shared bundle.
	 *
	 * Reads the theme app's own deserialiser when it is available, and falls
	 * back to the bundle's plain `declarations` map otherwise — a bundle is
	 * data, and refusing to read one because a helper is missing would make
	 * adoption depend on which services happen to be registered.
	 *
	 * @param array<string, mixed> $bundle The bundle.
	 *
	 * @return array<string, string> Token name to value.
	 */
	private function declarationsFrom(array $bundle): array {
		$type = $this->service(id: self::CONFIG_TYPE, method: 'deserialise');
		if ($type !== null) {
			try {
				$result = $type->deserialise($bundle);
				$declarations = (array)($result['import']['declarations'] ?? []);
				if ($declarations !== []) {
					return $this->stringMap(values: $declarations);
				}
			} catch (Throwable $e) {
				$this->logger->info(
					'[portaliq] shared theme deserialise failed; reading the bundle directly',
					['reason' => $e->getMessage()]
				);
			}
		}

		return $this->stringMap(values: (array)($bundle['declarations'] ?? []));
	}//end declarationsFrom()


	/**
	 * A map reduced to string keys and string values.
	 *
	 * @param array<mixed> $values The raw map.
	 *
	 * @return array<string, string> The map.
	 */
	private function stringMap(array $values): array {
		$map = [];
		foreach ($values as $name => $value) {
			if (is_string($name) === true && is_scalar($value) === true) {
				$map[$name] = (string)$value;
			}
		}

		return $map;
	}//end stringMap()


	/**
	 * A refusal carrying its reason.
	 *
	 * @param string $reason Why the bundle was not adopted.
	 *
	 * @return array{adopted: bool, tokens: array<string, string>, refusal: string, skipped: array<int, string>}
	 */
	private function refuse(string $reason): array {
		return ['adopted' => false, 'tokens' => [], 'refusal' => $reason, 'skipped' => []];
	}//end refuse()


	/**
	 * One of the theme app's services, or null.
	 *
	 * @param string $id     The class name.
	 * @param string $method A method it must expose.
	 *
	 * @return object|null The service.
	 */
	private function service(string $id, string $method): ?object {
		try {
			$service = $this->container->get($id);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true && method_exists($service, $method) === true) {
			return $service;
		}

		return null;
	}//end service()


}//end class
