<?php

/**
 * The snippet an administrator copies, and who it lets frame the form.
 *
 * 🔴 AN ORIGIN LIST IS A SECURITY DECISION WRITTEN IN A FORMAT NOBODY READS AS
 * ONE. `["https://www.gemeente.nl", "https://*.partner.nl"]` beside a text box
 * is a configuration field. "Only www.gemeente.nl can put this form on a page"
 * is a sentence an administrator can check against what they meant. The list
 * has to be BOTH: the machine-readable value they edit, and the plain sentence
 * that says what they have just decided.
 *
 * 🔴 AND AN EMPTY LIST IS THE DANGEROUS ONE, BECAUSE IT LOOKS LIKE A DEFAULT.
 * A form with no origins serves nobody. That is the safe behaviour and it is
 * the right default, but an administrator who has just pasted a snippet onto
 * their site and sees a blank page needs to be told that is why, on the screen
 * where they copied it, rather than reading a CSP error in a browser console
 * they have never opened.
 *
 * So the snippet is not offered at all for a form that cannot be framed: there
 * is nothing to copy that would work, and offering it invites an afternoon of
 * debugging the host page.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://Portaliq.app
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * Describes one form's embed for the screen where the snippet is copied.
 */
class PortalEmbedSnippetDescription {

	/**
	 * Wire the description.
	 *
	 * @param PortalEmbedGuard  $guard  The origin list and the snippet.
	 * @param PortalEmbedHeight $height The floor the snippet declares.
	 */
	public function __construct(
		private readonly PortalEmbedGuard $guard,
		private readonly PortalEmbedHeight $height = new PortalEmbedHeight(),
	) {
	}//end __construct()

	/**
	 * Everything the snippet screen shows for one form.
	 *
	 * @param array<string, mixed> $binding  The form binding.
	 * @param string               $frameUrl The frame's absolute url.
	 *
	 * @return array<string, mixed> The description.
	 */
	public function describe(array $binding, string $frameUrl): array {
		$origins = $this->guard->allowedOrigins(binding: $binding);
		$embeddable = ($origins !== [] && $frameUrl !== '');

		if ($embeddable === false) {
			return [
				'embeddable' => false,
				'origins' => [],
				'originSentence' => 'This form cannot be put on another website yet. Nobody is allowed to frame '
					.'it, so a page that embeds it would show nothing at all. Add the website addresses that '
					.'may show this form.',
				'snippet' => '',
				'listener' => '',
				'minimumHeight' => PortalEmbedHeight::MINIMUM_HEIGHT,
				'heightSentence' => '',
			];
		}

		$parts = $this->height->snippet(
			frameUrl: $frameUrl,
			title: (string)($binding['route'] ?? 'formulier')
		);

		return [
			'embeddable' => true,
			'origins' => $origins,
			'originSentence' => $this->originSentence(origins: $origins),
			'snippet' => $parts['iframe'],
			'listener' => $parts['listener'],
			'minimumHeight' => $parts['minimumHeight'],
			'heightSentence' => sprintf(
				'The form is at least %d pixels tall wherever it is placed. The second piece is optional: '
				.'with it the form grows to fit its own content, and without it the form still works and '
				.'scrolls inside that height.',
				$parts['minimumHeight']
			),
		];
	}//end describe()

	/**
	 * The origin list as a sentence.
	 *
	 * Names them all rather than "3 origins". An administrator checking whether
	 * they have accidentally allowed a test domain cannot check a count, and a
	 * list that collapses after two entries hides exactly the entry somebody
	 * added in a hurry and meant to remove.
	 *
	 * @param array<int, string> $origins The allowed origins.
	 *
	 * @return string The sentence.
	 */
	private function originSentence(array $origins): string {
		$names = array_map(
			static function (string $origin): string {
				return preg_replace('#^https?://#', '', $origin) ?? $origin;
			},
			$origins
		);

		if (count($names) === 1) {
			return sprintf('Only %s may show this form on its own pages.', $names[0]);
		}

		$last = array_pop($names);

		return sprintf(
			'Only %s and %s may show this form on their own pages.',
			implode(', ', $names),
			$last
		);
	}//end originSentence()
}//end class
