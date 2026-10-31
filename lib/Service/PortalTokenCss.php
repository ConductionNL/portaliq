<?php

/**
 * Portaliq Portal Token CSS
 *
 * A portal's own token overrides, rendered as one `:root` block after its
 * theme. A declaration is kept only when its name belongs to a themed family
 * and its value holds only the characters a token value needs. A declaration
 * that fails either rule is dropped, never escaped.
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
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-portal-must-be-able-to-override-its-themes-tokens-safely-req-ptb-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Renders a portal's `tokens` map as safe CSS.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-portal-must-be-able-to-override-its-themes-tokens-safely-req-ptb-002
 */
class PortalTokenCss {

	/**
	 * The token families a portal may override.
	 */
	private const FAMILIES = ['nldesign', 'utrecht', 'tilburg', 'conduction', 'ams', 'c'];

	/**
	 * Strings a value may never contain, whatever its characters.
	 */
	private const FORBIDDEN = ['url(', 'expression', 'javascript:', 'data:', '@import', '\\'];

	/**
	 * The `:root` block for a portal's tokens, or '' when none survive.
	 *
	 * @param mixed $tokens The portal's `tokens`: token name to value.
	 *
	 * @return string The CSS.
	 *
	 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-portal-must-be-able-to-override-its-themes-tokens-safely-req-ptb-002
	 */
	public function css(mixed $tokens): string {
		if (is_array($tokens) === false) {
			return '';
		}

		$declarations = [];
		foreach ($tokens as $name => $value) {
			if (is_string($name) === false || is_string($value) === false || $this->nameIsAllowed(name: $name) === false) {
				continue;
			}

			$value = trim($value);
			if ($this->valueIsAllowed(value: $value) === false) {
				continue;
			}

			$declarations[] = $name . ':' . $value;
		}

		if ($declarations === []) {
			return '';
		}

		return ':root{' . implode(';', $declarations) . '}';
	}//end css()

	/**
	 * Whether a token name is a custom property of a themed family.
	 *
	 * @param string $name The name.
	 *
	 * @return bool
	 */
	private function nameIsAllowed(string $name): bool {
		if (preg_match('/^--(' . implode('|', self::FAMILIES) . ')-[a-z0-9-]+$/', $name) !== 1) {
			return false;
		}

		return true;
	}//end nameIsAllowed()

	/**
	 * Whether a value holds only what a token value needs.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private function valueIsAllowed(string $value): bool {
		if ($value === '' || strlen($value) > 200 || preg_match('/^[A-Za-z0-9#%.,()\s\/_-]+$/', $value) !== 1) {
			return false;
		}

		$lower = strtolower($value);
		foreach (self::FORBIDDEN as $needle) {
			if (str_contains($lower, $needle) === true) {
				return false;
			}
		}

		return true;
	}//end valueIsAllowed()
}//end class
