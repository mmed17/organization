<template>
	<NcAppContentList :selection="!!selectedId">
		<div class="list-toolbar">
			<NcTextField
				v-model="searchQuery"
				:show-trailing-button="false"
				label="Search organizations..."
				class="search-field">
				<template #leading-icon>
					<Magnify :size="20" />
				</template>
			</NcTextField>
		<NcButton v-if="canCreate" type="primary" @click="$emit('create')">
			<template #icon><Plus :size="20" /></template>
			New
		</NcButton>
		</div>

		<div v-if="loading" class="state-container">
			<NcLoadingIcon :size="64" />
			<p>Loading organizations...</p>
		</div>

		<div v-else-if="filteredOrganizations.length === 0" class="state-container">
			<div class="empty-icon-bg">
				<AccountGroup :size="48" />
			</div>
			<h3>No organizations found</h3>
			<p>{{ canCreate ? 'Get started by creating a new organization.' : 'No organizations available for your account.' }}</p>
			<NcButton v-if="canCreate" type="primary" @click="$emit('create')">
				<template #icon>
					<Plus :size="20" />
				</template>
				Create Organization
			</NcButton>
		</div>

		<template v-else>
			<NcListItem
				v-for="org in filteredOrganizations"
				:key="org.id"
				:name="org.displayname"
				:active="selectedId === org.id"
				@click="$emit('select', org)"
				:force-display-actions="true">
				<template #icon>
					<NcAvatar
						:display-name="org.displayname"
						:size="44"
						:disable-tooltip="true" />
				</template>
				
				<template #subname>
					{{ org.subscription.planName || 'Custom' }} • {{ org.usercount }} / {{ org.subscription.maxMembers }} Members • {{ org.subscription.maxProjects }} Projects
				</template>

				<template #details>
					<span :class="['status-dot', org.subscription.status]"></span>
				</template>

				<template #actions>
					<NcActions>
						<NcActionButton @click="$emit('select', org)">
							<template #icon>
								<CardAccountDetails :size="20" />
							</template>
							View Details
						</NcActionButton>
					</NcActions>
				</template>
			</NcListItem>
		</template>
	</NcAppContentList>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import {
	NcAppContentList,
	NcListItem,
	NcActions,
	NcActionButton,
	NcTextField,
	NcLoadingIcon,
	NcAvatar,
	NcButton,
} from '@nextcloud/vue'


import Plus from 'vue-material-design-icons/Plus.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue'

const props = defineProps<{
	organizations: any[]
	selectedId: number | null
	loading: boolean
	canCreate: boolean
}>()

const emit = defineEmits(['select', 'create'])

const searchQuery = ref('')

const filteredOrganizations = computed(() => {
	if (!searchQuery.value) return props.organizations
	const query = searchQuery.value.toLowerCase()
	return props.organizations.filter(org => 
		org.displayname.toLowerCase().includes(query) || 
		String(org.id).includes(query)
	)
})
</script>

<style scoped>
.list-toolbar {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	position: sticky;
	top: 0;
	background-color: var(--color-main-background);
	z-index: 10;
}

.list-toolbar .search-field {
	flex: 1;
	min-width: 0;
}

.state-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.empty-icon-bg {
	background-color: var(--color-background-dark);
	border-radius: 50%;
	padding: 20px;
	margin-bottom: 20px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.status-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	display: inline-block;
	background-color: var(--color-text-lighter);
}

.status-dot.active { background-color: var(--color-success); }
.status-dot.expired { background-color: var(--color-error); }
.status-dot.cancelled { background-color: var(--color-warning); }
</style>
