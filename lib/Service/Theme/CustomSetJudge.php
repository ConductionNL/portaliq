<?php

/**
 * Portaliq Custom Set Judge
 *
 * The theme app's validator's verdict on one custom set's file.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Theme
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

namespace OCA\Portaliq\Service\Theme;

/**
 * Hands one custom set's tokens to the theme app's validator and words its
 * verdict. Built by PortalCustomThemeSets around the validator it resolved.
 *
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */
final class CustomSetJudge {
	/**
	 * Constructor.
	 *
	 * @param object $validator The theme app's CustomTokenSetValidator.
	 */
	public function __construct(
		private readonly object $validator,
	) {
	}//end __construct()

	/**
	 * The validator's verdict on one file: null when it passes.
	 *
	 * @param string $id  The set id.
	 * @param string $css The file.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/tasks.md
	 */
	public function verdict(string $id, string $css): ?string {
		if ($this->hasExtraRoot(css: $css) === true) {
			return 'the file holds more than one :root block of tokens';
		}

		$declarations = $this->declarations(css: $css);
		if ($declarations === []) {
			return 'the file declares no tokens';
		}

		if ($this->validator->validateDeclarations($declarations, substr($id, strlen(PortalCustomThemeSets::ID_PREFIX))) !== null) {
			return null;
		}

		return $this->lastErrorMessage();
	}//end verdict()

	/**
	 * Whether the validator finds a selector besides the one `:root` block.
	 *
	 * @param string $css The file.
	 *
	 * @return bool
	 */
	private function hasExtraRoot(string $css): bool {
		return method_exists($this->validator, 'hasDisallowedSelector') === true
			&& $this->validator->hasDisallowedSelector($css) === true;
	}//end hasExtraRoot()

	/**
	 * The validator's own words for the declaration it refused, where it gives them.
	 *
	 * @return string The reason.
	 */
	private function lastErrorMessage(): string {
		$last = null;
		if (method_exists($this->validator, 'getLastError') === true) {
			$last = $this->validator->getLastError();
		}

		if (is_array($last) === true && is_string($last['message'] ?? null) === true) {
			return $last['message'];
		}

		return 'a declaration was refused';
	}//end lastErrorMessage()

	/**
	 * Every custom-property declaration in a file, name to value.
	 *
	 * Every name is passed on, also one the validator's name pattern would
	 * skip: its value is judged there all the same.
	 *
	 * @param string $css The file.
	 *
	 * @return array<string, string>
	 */
	private function declarations(string $css): array {
		$declarations = [];
		foreach ((new CssStatements())->of(body: $this->blockBody(css: $css)) as $statement) {
			$colon = strpos($statement, ':');
			if ($colon === false) {
				continue;
			}

			$name = trim(substr($statement, 0, $colon));
			if (str_starts_with($name, '--') === true) {
				$declarations[$name] = trim(substr($statement, ($colon + 1)));
			}
		}

		return $declarations;
	}//end declarations()

	/**
	 * The file without comments, cut to the inside of its outermost braces
	 * when it has them.
	 *
	 * @param string $css The file.
	 *
	 * @return string The declarations of the block.
	 */
	private function blockBody(string $css): string {
		$body  = (string)preg_replace('#/\*.*?\*/#s', '', $css);
		$open  = strpos($body, '{');
		$close = strrpos($body, '}');
		if ($open !== false && $close !== false && $close > $open) {
			$body = substr($body, ($open + 1), ($close - $open - 1));
		}

		return $body;
	}//end blockBody()
}//end class
