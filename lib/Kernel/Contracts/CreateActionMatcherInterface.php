<?php

/**
 * Contract of CreateActionMatcher
 *
 * The public surface of CreateActionMatcher that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of CreateActionMatcher.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CreateActionMatcherInterface {

	/**
	 * The matched action, AMBIGUOUS, or null.
	 *
	 * A named id must be one of the subject's create actions on this register
	 * and schema, else null (refused). Without an id, a single candidate is
	 * used, and two or more answer AMBIGUOUS rather than a guess.
	 *
	 * @param array<string, mixed> $aggregate The subject's aggregated contributions.
	 * @param string               $register  The requested register.
	 * @param string               $schema    The requested schema.
	 * @param mixed                $actionId  The `actionId` the client sent (anything but a non-empty string is none).
	 *
	 * @return array{action: array<string, mixed>, app: string}|string|null
	 *
	 * @spec openspec/changes/archive/2026-10-07-create-names-its-action/tasks.md#T1
	 */
	public function match(array $aggregate, string $register, string $schema, mixed $actionId): array|string|null;

	/**
	 * The same match among `anonymous: true` actions only, for a caller
	 * without a subject. Every active landing-page form is its own anonymous
	 * create action on `landingPageSubmission`, so the first is never taken
	 * for granted there either.
	 *
	 * @param array<string, mixed> $aggregate The anonymous aggregate.
	 * @param string               $register  The requested register.
	 * @param string               $schema    The requested schema.
	 * @param mixed                $actionId  The `actionId` the client sent.
	 *
	 * @return array{action: array<string, mixed>, app: string}|string|null
	 *
	 * @spec openspec/changes/archive/2026-10-07-create-names-its-action/tasks.md#T3
	 */
	public function matchAnonymous(array $aggregate, string $register, string $schema, mixed $actionId): array|string|null;
}//end interface
