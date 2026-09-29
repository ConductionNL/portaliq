<?php

/**
 * Whether an adopted theme's own tokens meet AA on the surfaces a portal paints.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Theme
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Theme;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contrast, checked when a theme is chosen rather than after a review.
 *
 * It asks the theme app's own `ContrastService`, called in process: a second
 * implementation of the WCAG ratio in this app would be a second answer to the
 * same question. Without the theme app the verdict is `evaluated: false`, never
 * a pass, and a set whose tokens name none of the checked surfaces is
 * `measured: 0`, also never a pass.
 */
class PortalThemeContrast {

	/**
	 * The theme app's contrast arithmetic, under its current namespace and the
	 * one it had before the rename, tried in that order.
	 */
	private const CONTRAST_SERVICES = [
		'OCA\\Thematiq\\Service\\ContrastService',
		'OCA\\NLDesign\\Service\\ContrastService',
	];

	/**
	 * The surfaces a portal paints from a token, and the text tokens on each.
	 *
	 * The hero band is left out on purpose: it takes its colour from vendored
	 * CSS reading the palette, so there is no token to hold text against, and
	 * listing it would make this check skip it and still report a pass.
	 *
	 * @var array<string, array{background: string, text: array<int, string>}>
	 */
	private const SURFACES = [
		'page' => [
			'background' => '--nldesign-color-background',
			'text' => ['--nldesign-color-text'],
		],
		'footer' => [
			'background' => '--nldesign-color-footer-background',
			'text' => ['--nldesign-footer-legal-color', '--nldesign-footer-heading-color'],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the theme app's service.
	 * @param LoggerInterface    $logger    Records a check that could not run.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Evaluate one theme's token values against the portal's surfaces.
	 *
	 * @param array<string, string> $tokens The theme's token values.
	 *
	 * @return array{evaluated: bool, measured: int, passes: bool, findings: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	public function evaluate(array $tokens): array {
		$unevaluated = ['evaluated' => false, 'measured' => 0, 'passes' => false, 'findings' => []];

		$service = $this->contrastService();
		if ($service === null) {
			return $unevaluated;
		}

		$findings = [];
		$measured = 0;
		foreach (self::SURFACES as $surface => $roles) {
			$background = trim((string)($tokens[$roles['background']] ?? ''));
			$candidates = $this->candidatesFor(roles: $roles, tokens: $tokens);
			if ($background === '' || $candidates === []) {
				// A surface the theme does not paint is skipped, and the skip
				// shows in `measured`, never as a pass.
				continue;
			}

			try {
				$results = $service->evaluate($candidates, $background);
			} catch (Throwable $e) {
				$this->logger->warning('[portaliq] theme contrast evaluation failed', ['surface' => $surface, 'reason' => $e->getMessage()]);
				return $unevaluated;
			}

			foreach ((array)$results as $result) {
				if (is_array($result) === false || ($result['unevaluated'] ?? false) === true) {
					continue;
				}

				$measured++;
				if (($result['pass'] ?? false) === true) {
					continue;
				}

				$findings[] = [
					'surface' => $surface,
					'token' => (string)($result['name'] ?? ''),
					'ratio' => (float)($result['ratio'] ?? 0),
					'threshold' => (float)($result['threshold'] ?? 4.5),
				];
			}
		}//end foreach

		return [
			'evaluated' => $measured > 0,
			'measured' => $measured,
			'passes' => ($measured > 0 && $findings === []),
			'findings' => $findings,
		];
	}//end evaluate()

	/**
	 * The text colours a surface's roles resolve to, as the service takes them.
	 *
	 * @param array{background: string, text: array<int, string>} $roles  The surface.
	 * @param array<string, string>                               $tokens The theme's tokens.
	 *
	 * @return array<int, array{name: string, value: string, role: string}>
	 */
	private function candidatesFor(array $roles, array $tokens): array {
		$candidates = [];
		foreach ($roles['text'] as $name) {
			$value = trim((string)($tokens[$name] ?? ''));
			if ($value !== '') {
				$candidates[] = ['name' => $name, 'value' => $value, 'role' => 'text'];
			}
		}

		return $candidates;
	}//end candidatesFor()

	/**
	 * The theme app's contrast service, or null when it is not installed.
	 *
	 * @return object|null
	 */
	private function contrastService(): ?object {
		foreach (self::CONTRAST_SERVICES as $class) {
			try {
				$service = $this->container->get($class);
			} catch (Throwable $missing) {
				continue;
			}

			if (is_object($service) === true && method_exists($service, 'evaluate') === true) {
				return $service;
			}
		}

		return null;
	}//end contrastService()
}//end class
