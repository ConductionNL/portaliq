<?php

/**
 * Contract of PortalActionForwarder
 *
 * The public surface of PortalActionForwarder that other components call, so they depend
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

use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IResponse;

/**
 * Contract of PortalActionForwarder.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalActionForwarderInterface {

	/**
	 * Decode a relayed domain response body to an array, degrading to `[]` for
	 * an empty, non-string or non-array-decoding body.
	 *
	 * @param IResponse $response The domain app's response.
	 *
	 * @return array<mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T8
	 */
	public function decodeBody(IResponse $response): array;

	/**
	 * The answer to the portal client when the forward failed on the way:
	 * 504 `forward_timeout` when the app did not answer in time (it may
	 * still finish, so the page says the request may still be processing),
	 * else 502 `forward_failed`. Transport internals never leak.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/action-forward-survives-a-slow-answer/specs/portal-contribution-contract/spec.md#requirement-a-forward-that-times-out-says-the-request-may-still-be-processing-req-afs-002
	 */
	public function failureResponse(): JSONResponse;

	/**
	 * Forward an already-authorised action to its domain endpoint.
	 *
	 * When the action declares a `fields` whitelist, the caller passes the
	 * rebuilt map and it becomes the ENTIRE forwarded body — an undeclared
	 * field (subjectRef, or anything else a client smuggles into the body) can
	 * never reach the domain app through the forward. A null `$whitelisted`
	 * means the action declares no `fields` and the raw request body is relayed
	 * as-is (contract v2, A6): forwards like shillinq's `pay` carry an opaque
	 * domain payload with no whitelist.
	 *
	 * @param array<string, mixed> $action The already-authorised action declaration.
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed>|null $whitelisted The rebuilt whitelisted body, or null to relay raw.
	 * @param string $scopeValue The server-resolved value of the action's declared `scopeClaim`,
	 *                           signed into the assertion; '' when the action declares none.
	 * @param list<array{name: string, type: string, tmp_name: string, size: int}> $files Checked uploads of a row action
	 *                                                                           that declares `files`; sent multipart.
	 *
	 * @return IResponse|null The domain app's response, or null on transport failure.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T8
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-frozen-assertion-wire-format
	 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-every-call-to-this-instance-goes-through-one-loopback-service
	 * @spec openspec/changes/row-action-carries-files/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-row-action-may-carry-the-files-the-resident-adds-req-raf-001
	 */
	public function forward(array $action, array $subject, ?array $whitelisted = null, string $scopeValue = '', array $files = []): ?IResponse;

	/**
	 * Whether an action may be forwarded at all: a non-empty INSTANCE-LOCAL
	 * endpoint path (SSRF guard: a leading slash, no protocol-relative `//`,
	 * no scheme) and an allowed method. Trust is the caller's check.
	 *
	 * The same rule `ContributionController::isForwardableAction()` applies to
	 * the id-addressed forward; the row-scoped forward reads it from here.
	 *
	 * @param array<string, mixed> $action The matched action declaration.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
	 */
	public function isForwardable(array $action): bool;
}//end interface
