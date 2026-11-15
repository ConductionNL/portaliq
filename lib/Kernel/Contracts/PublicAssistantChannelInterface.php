<?php

/**
 * Contract of PublicAssistantChannel
 *
 * The public surface of PublicAssistantChannel that other components call, so they depend
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
 * Contract of PublicAssistantChannel.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicAssistantChannelInterface {

	/**
	 * Ask one question.
	 *
	 * @param array<string, mixed> $portal The resolved portal object.
	 * @param string $question The visitor's question.
	 * @param string $locale The visitor's locale.
	 * @param string $conversationId A conversation id hermiq issued earlier in the visit, or ''.
	 *
	 * @return array{status: string, answer: ?string, sources: array<int, array<string, string>>, removed: bool, conversationId: string}
	 *         `status` is `answered`, `abstained` or `unavailable`. An answer with no
	 *         admitted source is an abstention and carries no text.
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t03
	 */
	public function ask(array $portal, string $question, string $locale, string $conversationId=''): array;

	/**
	 * Whether hermiq's entry point can be called.
	 *
	 * @return bool True when it is installed and resolves.
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t03
	 */
	public function isAvailable(): bool;
}//end interface
