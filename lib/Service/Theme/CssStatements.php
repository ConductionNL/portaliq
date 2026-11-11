<?php

/**
 * Portaliq Css Statements
 *
 * A block body split into its declarations.
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
 * Splits a block body on the semicolons that end a declaration, not the
 * ones inside `url(...)` or a quoted string (a `data:` logo has one).
 * One instance per body.
 *
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */
final class CssStatements {
	/**
	 * How a character changes the bracket depth.
	 *
	 * @var array<string, int>
	 */
	private const NESTING = ['(' => 1, ')' => -1];

	/**
	 * The statements so far; the last one is still being read.
	 *
	 * @var array<int, string>
	 */
	private array $statements = [''];

	/**
	 * The bracket depth outside quotes.
	 *
	 * @var integer
	 */
	private int $depth = 0;

	/**
	 * The quote open at this point, or ''.
	 *
	 * @var string
	 */
	private string $quote = '';

	/**
	 * The statements of one block body.
	 *
	 * @param string $body The declarations of one block.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/nldesign-theme-integration/tasks.md
	 */
	public function statementsOf(string $body): array {
		foreach (str_split($body) as $char) {
			$this->read(char: $char);
		}

		return $this->statements;
	}//end statementsOf()

	/**
	 * Read one character: a quote opens or closes, a top-level semicolon
	 * ends the statement, anything else joins it.
	 *
	 * @param string $char The character.
	 *
	 * @return void
	 */
	private function read(string $char): void {
		if ($this->inOrAtQuote(char: $char) === true) {
			$this->quote = $this->quoteAfter(char: $char);
		} else if ($char === ';' && $this->depth === 0) {
			$this->statements[] = '';
			return;
		}

		$this->append(char: $char);
	}//end read()

	/**
	 * Whether a quote is open or this character is one.
	 *
	 * @param string $char The character.
	 *
	 * @return bool
	 */
	private function inOrAtQuote(string $char): bool {
		return $this->quote !== '' || $char === '"' || $char === "'";
	}//end inOrAtQuote()

	/**
	 * Add a character to the statement being read, tracking brackets
	 * outside quotes.
	 *
	 * @param string $char The character.
	 *
	 * @return void
	 */
	private function append(string $char): void {
		if ($this->quote === '') {
			$this->depth = max(0, ($this->depth + (self::NESTING[$char] ?? 0)));
		}

		$this->statements[(count($this->statements) - 1)] .= $char;
	}//end append()

	/**
	 * The open quote after one character: a quote opens, the same quote closes.
	 *
	 * @param string $char The character.
	 *
	 * @return string
	 */
	private function quoteAfter(string $char): string {
		if ($this->quote === '') {
			return $char;
		}

		if ($char === $this->quote) {
			return '';
		}

		return $this->quote;
	}//end quoteAfter()
}//end class
