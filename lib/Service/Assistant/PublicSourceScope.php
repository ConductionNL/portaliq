<?php

/**
 * Portaliq Public Source Scope
 *
 * The declaration of what the public assistant may read for a portal.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Assistant
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
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Assistant;

/**
 * Public is a declaration, not a guess: the sources are a constant list, and a
 * test pins it so no schema that holds a resident's data can be added by accident.
 *
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t02
 */
class PublicSourceScope {

	/**
	 * The only schemas the assistant may read: published pages, glossary
	 * terms and published publications.
	 *
	 * @var string[]
	 */
	public const SCHEMAS = ['page', 'glossaryTerm', 'publication'];

	/**
	 * The most routes a portal may leave out.
	 *
	 * @var int
	 */
	private const MAX_EXCLUDED = 50;

	/**
	 * What the assistant reads for one portal.
	 *
	 * @param string $portal The portal's slug.
	 * @param array<string, mixed> $settings The portal's `assistant` setting.
	 *
	 * @return array{portal: string, sources: array<int, array<string, mixed>>, excludedRoutes: string[]} The scope.
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t02
	 */
	public function forPortal(string $portal, array $settings=[]): array {
		return [
			'portal'         => $portal,
			'sources'        => [
				['schema' => 'page', 'filters' => ['portal' => $portal, 'status' => 'published']],
				['schema' => 'glossaryTerm', 'filters' => ['portal' => $portal]],
				['schema' => 'publication', 'filters' => ['status' => 'published']],
			],
			'excludedRoutes' => $this->excludedRoutes(settings: $settings),
		];
	}//end forPortal()

	/**
	 * Whether a portal switched the assistant on. Off unless it is exactly true.
	 *
	 * @param array<string, mixed>|null $portal The portal object.
	 *
	 * @return bool True when `assistant.enabled` is true.
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t02
	 */
	public function enabledFor(?array $portal): bool {
		$settings = $portal['assistant'] ?? null;
		return is_array($settings) === true && ($settings['enabled'] ?? false) === true;
	}//end enabledFor()

	/**
	 * The portal's settings as the scope reads them.
	 *
	 * @param array<string, mixed>|null $portal The portal object.
	 *
	 * @return array<string, mixed> The `assistant` setting, or an empty one.
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t02
	 */
	public function settingsFor(?array $portal): array {
		$settings = $portal['assistant'] ?? null;
		if (is_array($settings) === true) {
			return $settings;
		}

		return [];
	}//end settingsFor()

	/**
	 * Whether a source belongs to the scope: its type is listed and it is not
	 * a route the portal left out.
	 *
	 * @param array<string, mixed> $source A source an answer names.
	 * @param array<string, mixed> $scope  The scope from forPortal().
	 *
	 * @return bool True when the source may be shown.
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t02
	 */
	public function admits(array $source, array $scope): bool {
		if (in_array(($source['schema'] ?? null), self::SCHEMAS, true) === false) {
			return false;
		}

		$route = trim((string)($source['route'] ?? ''), '/');
		foreach ((array)($scope['excludedRoutes'] ?? []) as $excluded) {
			if ($route !== '' && trim((string)$excluded, '/') === $route) {
				return false;
			}
		}

		return true;
	}//end admits()

	/**
	 * The routes to leave out, cleaned.
	 *
	 * @param array<string, mixed> $settings The portal's `assistant` setting.
	 *
	 * @return string[] The routes.
	 */
	private function excludedRoutes(array $settings): array {
		$routes = [];
		foreach ((array)($settings['excludedRoutes'] ?? []) as $route) {
			if (is_string($route) === true && trim($route, '/') !== '') {
				$routes[] = trim($route, '/');
			}
		}

		return array_slice(array_values(array_unique($routes)), 0, self::MAX_EXCLUDED);
	}//end excludedRoutes()
}//end class
