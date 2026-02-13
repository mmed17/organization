<template>
	<AppNavigation
		:active-item="activeItem"
		:show-plans="permissions.isGlobalAdmin"
		:mode-label="modeLabel"
		@update:activeItem="onNavigationChange" />

	<OrganizationsView
		v-if="activeItem === 'organizations'"
		:permissions="permissions" />

	<PlansView
		v-else-if="activeItem === 'plans'" />
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import AppNavigation from './components/AppNavigation.vue'
import OrganizationsView from './views/OrganizationsView.vue'
import PlansView from './views/PlansView.vue'

const activeItem = ref('organizations')

const settings = loadState('organization', 'settings', {
	permissions: {
		isGlobalAdmin: false,
		isOrganizationAdmin: false,
		organizationId: null,
	},
}) as any

const permissions = settings.permissions

const modeLabel = computed(() => permissions.isGlobalAdmin ? 'Global Admin Mode' : 'Organization Admin Mode')

const onNavigationChange = (item: string) => {
	if (item === 'plans' && !permissions.isGlobalAdmin) {
		activeItem.value = 'organizations'
		return
	}

	activeItem.value = item
}
</script>
