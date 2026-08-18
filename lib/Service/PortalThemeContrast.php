<?php

/**
 * Portaliq Portal Theme Contrast
 *
 * Whether an adopted theme's own tokens meet AA on the surfaces a portal paints.
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
 * Contrast, checked at ADOPTION rather than after a review.
 *
 * WHY IT ASKS THE THEME APP RATHER THAN COMPUTING ITS OWN. `ContrastService`
 * is the fleet's WCAG arithmetic and has no constructor and no dependencies at
 * all, so this resolves it from the container and calls it. A second
 * implementation of a contrast ratio in this app would be a second answer to
 * the same question, and the one that drifted would be the one telling an
 * administrator their portal is fine.
 *
 * IT IS CALLED IN PROCESS, NOT OVER HTTP. `ContrastController::evaluate()`
 * exposes the same service, but it is `#[NoAdminRequired]` — it needs a
 * session — and this runs wherever a theme is adopted, including from a
 * background context. Calling the service directly is the same computation
 * without inventing an internal HTTP hop that has to carry a token.
 *
 * FAILS OPEN, LOUDLY. With the theme app absent this returns an `unevaluated`
 * verdict rather than a pass: an administrator must be able to tell "this
 * theme is fine" from "nothing checked it", and a check that reports success
 * when it did not run is the failure this whole file is guarding against.
 *
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */
class PortalThemeContrast {

	/**
	 * The theme app's contrast arithmetic.
	 */
	private const CONTRAST_SERVICE = 'OCA\\NLDesign\\Service\\ContrastService';

	/**
	 * The surfaces a portal actually paints, and the text that lands on each.
	 *
	 * MEASURED, NOT ASSUMED. This list is the surfaces the site's own
	 * enumeration found painting on a rendered portal (task 2.6), reduced to
	 * those whose background is expressed as a TOKEN and can therefore be
	 * judged before anything renders.
	 *
	 * THE HERO BAND IS DELIBERATELY ABSENT, and its absence is a finding. It
	 * paints itself `#21468B` on the Conduction set, but no
	 * `--nldesign-hero-background` exists in any shipped set — the band's
	 * colour comes from the vendored `.ac-hero` rule reading the theme's own
	 * palette, so there is no single token to hold a text colour against. It
	 * cannot be checked statically at all, which is exactly why
	 * `tests/site-surfaces.spec.mjs` measures it on the RENDERED page instead;
	 * listing it here with a token that does not exist would have made this
	 * check silently skip it and still report a pass.
	 *
	 * Each entry names the token that paints the background and the tokens
	 * whose text sits on it.
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
	 * @param ContainerInterface $container For resolving the theme app's service.
	 * @param LoggerInterface    $logger    Records an unevaluated verdict.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Evaluate one theme's declared tokens against the portal's surfaces.
	 *
	 * @param array<string, string> $tokens The theme's resolved token values.
	 *
	 * @return array<string, mixed> `{evaluated, measured, passes, findings: [...]}`.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/tasks.md
	 */
	public function evaluate(array $tokens): array {
		$service = $this->contrastService();
		if ($service === null) {
			// UNEVALUATED IS NOT A PASS, and it says which it is.
			$this->logger->info('[portaliq] theme contrast not evaluated: nldesign unavailable');
			return ['evaluated' => false, 'measured' => 0, 'passes' => false, 'findings' => []];
		}

		$findings = [];
		$measured = 0;
		foreach (self::SURFACES as $surface => $roles) {
			$background = trim((string)($tokens[$roles['background']] ?? ''));
			$candidates = $this->candidatesFor(roles: $roles, tokens: $tokens);

			// A SURFACE THE THEME DOES NOT PAINT IS SKIPPED, not failed. The
			// renderer falls back to its own default there, and judging a token
			// the theme never declared would report a finding nobody can act
			// on. The skip is visible in `measured`, never as a pass.
			if ($background === '' || $candidates === []) {
				continue;
			}

			try {
				$results = $service->evaluate($candidates, $background);
			} catch (Throwable $e) {
				$this->logger->warning(
					'[portaliq] theme contrast evaluation failed',
					['surface' => $surface, 'reason' => $e->getMessage()]
				);
				return ['evaluated' => false, 'measured' => 0, 'passes' => false, 'findings' => []];
			}

			$measured += count($results);

			foreach ($results as $result) {
				if (($result['pass'] ?? true) === true) {
					continue;
				}

				// NAMED, WITH THE MEASURED RATIO. "This theme fails AA" sends
				// somebody hunting; "--nldesign-hero-title-color is 2.31:1 on
				// the hero band, AA wants 4.5" sends them to the token.
				$findings[] = [
					'surface' => $surface,
					'token' => (string)($result['name'] ?? ''),
					'ratio' => (float)($result['ratio'] ?? 0),
					'threshold' => (float)($result['threshold'] ?? 4.5),
					'on' => $background,
				];
			}
		}

		// `evaluated` REQUIRES SOMETHING TO HAVE BEEN MEASURED, and the count
		// travels with the verdict.
		//
		// The first version returned `passes: true` whenever `findings` was
		// empty — which is also what a set declaring none of these tokens
		// produces. It reported 46 of 46 sets passing, with zero pairs actually
		// compared, and that reads exactly like a clean bill of health. A thing
		// not measured must never be subtracted as though it passed.
		return [
			'evaluated' => $measured > 0,
			'measured' => $measured,
			'passes' => ($measured > 0 && $findings === []),
			'findings' => $findings,
		];
	}//end evaluate()


	/**
	 * The text colours a surface's roles resolve to, in the shape the service
	 * takes.
	 *
	 * @param array{background: string, text: array<int, string>} $roles  The surface.
	 * @param array<string, string>                               $tokens The theme's tokens.
	 *
	 * @return array<int, array<string, string>> The candidates.
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
	 * The theme app's contrast service, or null.
	 *
	 * @return object|null The service.
	 */
	private function contrastService(): ?object {
		try {
			$service = $this->container->get(self::CONTRAST_SERVICE);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true && method_exists($service, 'evaluate') === true) {
			return $service;
		}

		return null;
	}//end contrastService()


}//end class
