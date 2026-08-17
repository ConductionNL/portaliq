<?php

/**
 * Portaliq Per-Portal Token CSS.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Renders one portal's token overrides as a `:root` block.
 *
 * WHY A PORTAL NEEDS ITS OWN LAYER AT ALL
 *
 * A portal picks a theme, and a theme is shared. Two portals on one theme
 * cannot differ, and an organisation wanting its own accent has to commission a
 * whole new token set for one colour. This is the delta layer: theme first,
 * portal last.
 *
 * The cascade the renderer emits, in order:
 *
 *     public-bridge.css     component roles ← the nldesign semantic layer
 *     tokens/{theme}.css    the theme's own values
 *     this                  what THIS portal changes
 *
 * THE INPUT IS UNTRUSTED AND ENDS UP IN A STYLESHEET.
 *
 * These values are authored through an admin UI and stored on an object; they
 * are rendered into CSS served to every anonymous visitor. A value containing
 * `}` closes the rule and everything after it is attacker-authored CSS on a
 * government portal — which can reposition, hide or overlay anything on the
 * page, including a login form. So both halves are validated against an
 * allow-list rather than escaped: a name must look like a design token, a value
 * must contain only the characters a token value needs, and anything else is
 * dropped rather than sanitised. Dropping is the safer failure — a portal that
 * silently loses one override is a styling bug; one that renders attacker CSS
 * is an incident.
 */
class PortalTokenCss {

	/**
	 * A design token name, without the leading dashes.
	 *
	 * Deliberately narrow: only the families this fleet themes with. A portal
	 * cannot introduce `--anything`, because the token vocabulary is the
	 * contract the bridge and the components are written against.
	 */
	private const NAME_PATTERN = '/^(nldesign|utrecht|tilburg|conduction|ams|c)-[a-z0-9-]{2,64}$/';

	/**
	 * The characters a token VALUE may contain.
	 *
	 * Enough for colours, lengths, font stacks, `var()` references and simple
	 * functions — and short of anything that can leave the declaration. No
	 * semicolons, no braces, no `@`, no backslashes.
	 */
	private const VALUE_PATTERN = '/^[a-zA-Z0-9 ,.()%#\'"_\/+-]{1,200}$/';

	/**
	 * Substrings refused outright regardless of the pattern above.
	 *
	 * `url(` is the one that matters: it fetches, so it is both an exfiltration
	 * channel (the request itself tells a third party who is reading the page)
	 * and a way to load remote content into a government portal.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN = ['url(', 'expression', 'javascript:', 'data:', '@import', '\\'];

	/**
	 * Render a portal's overrides as a CSS `:root` block.
	 *
	 * @param array<string, mixed> $portal The resolved portal record.
	 *
	 * @return string The CSS, or '' when the portal overrides nothing.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function render(array $portal): string {
		$declarations = $this->declarations(portal: $portal);
		if ($declarations === []) {
			return '';
		}

		$lines = [
			'/* Per-portal token overrides for "' . $this->safeComment(text: (string)($portal['slug'] ?? '')) . '".',
			'   Layered after the theme, so a portal differs from its theme by exactly',
			'   what it names here. Generated — edit the portal, not this. */',
			':root {',
		];

		foreach ($declarations as $name => $value) {
			$lines[] = "\t--" . $name . ': ' . $value . ';';
		}

		$lines[] = '}';

		return implode("\n", $lines) . "\n";
	}

	/**
	 * The accepted overrides, name => value.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, string> The accepted declarations.
	 */
	public function declarations(array $portal): array {
		$tokens = ($portal['tokens'] ?? null);
		if (is_array($tokens) === false) {
			return [];
		}

		$out = [];
		foreach ($tokens as $name => $value) {
			if (is_string($name) === false || is_string($value) === false) {
				continue;
			}

			// A leading `--` is what an author will type, so accept it and
			// normalise rather than silently rejecting the natural spelling.
			$name = ltrim($name, '-');
			if (preg_match(self::NAME_PATTERN, $name) !== 1) {
				continue;
			}

			$value = trim($value);
			if (preg_match(self::VALUE_PATTERN, $value) !== 1) {
				continue;
			}

			$lowered = strtolower($value);
			foreach (self::FORBIDDEN as $needle) {
				if (str_contains($lowered, $needle) === true) {
					continue 2;
				}
			}

			$out[$name] = $value;
		}

		return $out;
	}

	/**
	 * A slug rendered into a CSS comment cannot be allowed to close it.
	 *
	 * @param string $text The text.
	 *
	 * @return string The safe text.
	 */
	private function safeComment(string $text): string {
		return preg_replace('/[^a-zA-Z0-9 _-]/', '', $text) ?? '';
	}
}
