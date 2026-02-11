<template>
	<AppNavigation
		:active-item="activeItem"
		@update:activeItem="activeItem = $event" />

	<OrganizationsView
		v-if="activeItem === 'organizations'" />

	<PlansView
		v-else-if="activeItem === 'plans'" />
</template>

<script setup lang="ts">
import { ref } from 'vue'
import AppNavigation from './components/AppNavigation.vue'
import OrganizationsView from './views/OrganizationsView.vue'
import PlansView from './views/PlansView.vue'

const activeItem = ref('organizations')
</script>
