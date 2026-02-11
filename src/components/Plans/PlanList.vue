<template>
	<NcAppContentList :selection="!!selectedId">
		<div class="list-toolbar">
			<NcTextField
				v-model="searchQuery"
				:show-trailing-button="false"
				label="Search plans..."
				class="search-field">
				<template #leading-icon>
					<Magnify :size="20" />
				</template>
			</NcTextField>
			<NcButton type="primary" @click="$emit('create')">
				<template #icon><Plus :size="20" /></template>
				New
			</NcButton>
		</div>

		<div v-if="loading" class="state-container">
			<NcLoadingIcon :size="64" />
			<p>Loading plans...</p>
		</div>

		<div v-else-if="filteredPlans.length === 0" class="state-container">
			<div class="empty-icon-bg">
				<CardAccountDetails :size="48" />
			</div>
			<h3>No plans found</h3>
			<p>Get started by creating a new plan.</p>
			<NcButton type="primary" @click="$emit('create')">
				<template #icon>
					<Plus :size="20" />
				</template>
				Create Plan
			</NcButton>
		</div>

		<template v-else>
			<NcListItem
				v-for="plan in filteredPlans"
				:key="plan.id"
				:name="plan.name"
				:active="selectedId === plan.id"
				@click="$emit('select', plan)"
				:force-display-actions="true">
				<template #icon>
					<NcAvatar
						:display-name="plan.name"
						:size="44"
						:disable-tooltip="true" />
				</template>

				<template #subname>
					Members: {{ plan.maxMembers }} • Projects: {{ plan.maxProjects }} • {{ plan.isPublic ? 'Public' : 'Private' }}
				</template>

				<template #details>
					<span :class="['status-dot', plan.isPublic ? 'public' : 'private']"></span>
				</template>

				<template #actions>
					<NcActions>
						<NcActionButton @click="$emit('select', plan)">
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
import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue'

const props = defineProps<{
	plans: any[]
	selectedId: number | null
	loading: boolean
}>()

defineEmits(['select', 'create'])

const searchQuery = ref('')

const filteredPlans = computed(() => {
	if (!searchQuery.value) return props.plans
	const query = searchQuery.value.toLowerCase()
	return props.plans.filter(plan =>
		plan.name.toLowerCase().includes(query) ||
		String(plan.id).includes(query)
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

.status-dot.public { background-color: var(--color-success); }
.status-dot.private { background-color: var(--color-text-lighter); }
</style>
