<template>
	<NcAppContent>
		<template #navigation>
			<NcAppNavigation>
				<NcAppNavigationItem
					:active="activeItem === 'organizations'"
					name="Organizations"
					@click="activeItem = 'organizations'">
					<template #icon>
						<AccountGroup :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:active="activeItem === 'plans'"
					name="Subscription Plans"
					@click="activeItem = 'plans'">
					<template #icon>
						<CardAccountDetails :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigation>
		</template>
		<template #default>
			<OrganizationList v-if="activeItem === 'organizations'" />
			
			<div v-else-if="activeItem === 'plans'" class="plans-container empty-state">
				<div class="empty-content-icon">
					<CardAccountDetails :size="64" />
				</div>
				<h2>Subscription Plans</h2>
				<p>Plan management is coming soon. You can currently manage plans via the database or API.</p>
			</div>
		</template>
	</NcAppContent>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { NcAppContent, NcAppNavigation, NcAppNavigationItem } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue'
import OrganizationList from './components/OrganizationList.vue'

const activeItem = ref('organizations')
</script>

<style scoped>
.plans-container {
	padding: 20px;
	height: 100%;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.empty-content-icon {
	background-color: var(--color-background-dark);
	border-radius: 50%;
	padding: 30px;
	margin-bottom: 24px;
	display: flex;
	align-items: center;
	justify-content: center;
}

h2 {
	font-size: 1.5rem;
	margin-bottom: 12px;
	font-weight: bold;
}

p {
	max-width: 400px;
	line-height: 1.6;
	color: var(--color-text-light);
}
</style>
