<?php

/**
 * SPDX-FileCopyrightText: 2026 Custom Development
 * SPDX-License-Identifier: AGPL-3.0-only
 */
return [
    'routes' => [
        ['name' => 'Page#index', 'url' => '/', 'verb' => 'GET'],
    ],
    'ocs' => [

        // Organizations
        ['root' => '/apps/organization', 'name' => 'Organization#getOrganizations', 'url' => '/organizations', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Organization#getOrganization', 'url' => '/organizations/{organizationId}', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Organization#updateOrganization', 'url' => '/organizations/{organizationId}', 'verb' => 'PUT'],
        ['root' => '/apps/organization', 'name' => 'Organization#getOrganizationMembers', 'url' => '/organizations/{organizationId}/members', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Organization#searchAvailableUsers', 'url' => '/organizations/{organizationId}/available-users', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Organization#addOrganizationMember', 'url' => '/organizations/{organizationId}/members', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Organization#removeOrganizationMember', 'url' => '/organizations/{organizationId}/members/{userId}', 'verb' => 'DELETE'],
        ['root' => '/apps/organization', 'name' => 'Organization#createOrganizationUser', 'url' => '/organizations/{organizationId}/users', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Organization#createOrganization', 'url' => '/organizations', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Organization#updateSubscription', 'url' => '/organizations/{organizationId}/subscription', 'verb' => 'PUT'],

        // Plans
        ['root' => '/apps/organization', 'name' => 'Plan#getPlans', 'url' => '/plans', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Plan#getPlan', 'url' => '/plans/{planId}', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Plan#createPlan', 'url' => '/plans', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Plan#updatePlan', 'url' => '/plans/{planId}', 'verb' => 'PUT'],
        ['root' => '/apps/organization', 'name' => 'Plan#deletePlan', 'url' => '/plans/{planId}', 'verb' => 'DELETE'],

    ],
];
