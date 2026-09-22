<?php

/**
 * What a form binding resolves to today, said out loud on the admin surface.
 *
 * 🔴 A BINDING THAT RESOLVES TO NOTHING LOOKS EXACTLY LIKE ONE THAT IS FINE.
 * The admin page shows the binding an editor configured: a type tuple, an
 * audience, maybe a form name. All of that can be perfectly well-formed while
 * the form it points at is unpublished, published to a different audience, or
 * gone. The page renders the same either way, and the first person to find out
 * is a citizen meeting an empty form on a Saturday.
 *
 * So the preview names the form the binding resolves to RIGHT NOW, and when it
 * resolves to none it says so in words, with the reason. "No form" is a state
 * that has to be visible; an empty field where a form name should be reads as
 * "nobody has filled this in yet", which is a different problem with a
 * different fix.
 *
 * 🔴 AND IT NEVER GUESSES A NAME. When the resolution fails there is no
 * "(unknown form)" and no last-known value: a stale name is worse than no name,
 * because it tells an administrator the binding is working.
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
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * Describes one binding's current resolution for an administrator.
 */
class PortalBindingPreview {

	/**
	 * The binding resolves to a published form an audience can reach.
	 *
	 * @var string
	 */
	public const RESOLVED = 'resolved';

	/**
	 * The binding resolves to nothing a citizen could open.
	 *
	 * @var string
	 */
	public const RESOLVES_TO_NONE = 'resolves_to_none';

	/**
	 * The binding sends the citizen somewhere else entirely.
	 *
	 * @var string
	 */
	public const EXTERNAL = 'external';

	/**
	 * Wire the preview.
	 *
	 * @param PortalFormBindingResolver $resolver The resolution the render uses.
	 */
	public function __construct(private readonly PortalFormBindingResolver $resolver) {
	}//end __construct()

	/**
	 * What this binding resolves to today.
	 *
	 * Calls the SAME resolution the render calls. A preview that reimplements
	 * it drifts, and the drift shows up as an administrator being told a
	 * binding is healthy while the citizen-facing render disagrees, which is
	 * worse than having no preview at all.
	 *
	 * @param array<string, mixed> $binding The binding as configured.
	 *
	 * @return array<string, mixed> The preview.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function describe(array $binding): array {
		$render = $this->resolver->render(binding: $binding);
		$external = ((string)($render['kind'] ?? '') === PortalFormBindingResolver::KIND_EXTERNAL);
		$resolvesToNone = (($render['resolvesToNoForm'] ?? false) === true);

		if ($resolvesToNone === false && $external === true) {
			return [
				'state' => self::EXTERNAL,
				'formName' => null,
				'destination' => (string)($render['destination'] ?? ''),
				'message' => sprintf(
					'This entry sends people to %s. Nothing is filled in here, so nothing arrives here either.',
					(string)($render['destination'] ?? 'another website')
				),
			];
		}

		if ($resolvesToNone === false) {
			return [
				'state' => self::RESOLVED,
				'formName' => (string)($render['formName'] ?? ''),
				'destination' => null,
				'message' => sprintf(
					'This entry opens "%s" today.',
					(string)($render['formName'] ?? '')
				),
			];
		}

		// 🔴 NO NAME IS INVENTED HERE. Not the configured formName, not a last
		// known value: a stale name tells an administrator the binding works.
		return [
			'state' => self::RESOLVES_TO_NONE,
			'formName' => null,
			'destination' => null,
			'message' => $this->reasonFor(render: $render, binding: $binding),
		];
	}//end describe()

	/**
	 * Why the binding resolves to nothing, in words an administrator can act on.
	 *
	 * Each sentence names the next step, because "no form found" sends somebody
	 * to a colleague and one of these sends them to the right screen.
	 *
	 * @param array<string, mixed> $render  The resolution.
	 * @param array<string, mixed> $binding The binding as configured.
	 *
	 * @return string The message.
	 */
	private function reasonFor(array $render, array $binding): string {
		if ((string)($render['kind'] ?? '') === PortalFormBindingResolver::KIND_EXTERNAL) {
			return 'This entry is set to send people to another website, but no address is filled in, '
				.'so nobody can start it.';
		}

		$named = trim((string)($binding['formName'] ?? ''));
		if ($named !== '') {
			return sprintf(
				'This entry opens no form today. It asks for "%s", and no form of that name is published '
				.'to this audience. Publish it, or point the entry at a form that is.',
				$named
			);
		}

		return 'This entry opens no form today. No published form matches its type and audience, so a '
			.'citizen who reaches it sees nothing to fill in.';
	}//end reasonFor()

	/**
	 * Whether an administrator should be warned about this binding.
	 *
	 * @param array<string, mixed> $binding The binding.
	 *
	 * @return bool True when it resolves to nothing.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function needsAttention(array $binding): bool {
		return ((string)$this->describe(binding: $binding)['state'] === self::RESOLVES_TO_NONE);
	}//end needsAttention()
}//end class
