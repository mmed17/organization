<?php

/**
 * SPDX-FileCopyrightText: 2026 Custom Development
 * SPDX-License-Identifier: AGPL-3.0-only
 */
return [
    'ocs' => [
        // Organizations
        ['root' => '/apps/organization', 'name' => 'Organization#getOrganizations', 'url' => '/organizations', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Organization#getOrganization', 'url' => '/organizations/{organizationId}', 'verb' => 'GET', 'requirements' => ['organizationId' => '.+']],
        ['root' => '/apps/organization', 'name' => 'Organization#createOrganization', 'url' => '/organizations', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Organization#updateSubscription', 'url' => '/organizations/{organizationId}/subscription', 'verb' => 'PUT', 'requirements' => ['organizationId' => '.+']],

        // Plans
        ['root' => '/apps/organization', 'name' => 'Plan#getPlans', 'url' => '/plans', 'verb' => 'GET'],
    ],
];
