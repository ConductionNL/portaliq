<?php

/**
 * Portaliq Form Dependents Registrar
 *
 * Puts portaliq's answer on OpenRegister's question "which published forms
 * write into this schema?" (decision 179). Its own small class, so a test can
 * hand it a registration context and see the listener land on the event.
 *
 * @category AppInfo
 * @package  OCA\Portaliq\AppInfo
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
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
 */

declare(strict_types=1);

namespace OCA\Portaliq\AppInfo;

use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCA\Portaliq\Listener\FormDestinationDependentsListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the listener that announces portaliq's published form bindings.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
 */
class FormDependentsRegistrar {
	/**
	 * Register the listener on OpenRegister's event.
	 *
	 * The event is named by its class constant, which needs no autoload: on
	 * an instance without OpenRegister nothing dispatches it and the listener
	 * is never built.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(FormDestinationDependentsEvent::class, FormDestinationDependentsListener::class);
	}//end register()
}//end class
