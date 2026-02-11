<template>
	<NcAppContent
		:show-details="!!selectedPlan"
		@update:showDetails="onShowDetailsUpdate">
		<template #list>
			<PlanList
				:plans="plans"
				:selected-id="selectedPlan?.id"
				:loading="loading"
				@select="selectPlan"
				@create="openCreateModal" />
		</template>

		<template #default>
			<PlanDetails
				v-if="selectedPlan"
				:plan="selectedPlan"
				:loading="loadingDetails"
				@edit="openEditModal"
				@deleted="onPlanDeleted" />

			<div v-else class="empty-content">
				<div class="icon-bg">
					<CardAccountDetails :size="64" />
				</div>
				<h2>Select a plan</h2>
				<p>Select a plan from the list to view details, or create a new one.</p>
			</div>
		</template>
	</NcAppContent>

	<CreatePlanModal
		:show="showCreateModal"
		@close="showCreateModal = false"
		@success="onPlanCreated" />

	<EditPlanModal
		:show="showEditModal"
		:plan="selectedPlan"
		@close="showEditModal = false"
		@success="onPlanUpdated" />
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { NcAppContent } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue'

import PlanList from '../components/Plans/PlanList.vue'
import PlanDetails from '../components/Plans/PlanDetails.vue'
import CreatePlanModal from '../components/Plans/CreatePlanModal.vue'
import EditPlanModal from '../components/Plans/EditPlanModal.vue'

const plans = ref([])
const loading = ref(true)
const loadingDetails = ref(false)
const selectedPlan = ref(null)
const showCreateModal = ref(false)
const showEditModal = ref(false)

const fetchPlans = async () => {
	loading.value = true
	try {
		const response = await axios.get(generateOcsUrl('apps/organization/plans'))
		plans.value = response.data.ocs.data.plans
	} catch (error) {
		console.error('Failed to fetch plans', error)
	} finally {
		loading.value = false
	}
}

const selectPlan = async (plan: any) => {
	selectedPlan.value = { ...plan }
	loadingDetails.value = true
	try {
		const response = await axios.get(generateOcsUrl('apps/organization/plans/' + plan.id))
		const data = response.data.ocs.data
		selectedPlan.value = {
			...plan,
			...data,
		}
	} catch (error) {
		console.error('Failed to fetch plan details', error)
	} finally {
		loadingDetails.value = false
	}
}

const onShowDetailsUpdate = (show: boolean) => {
	if (!show) {
		selectedPlan.value = null
	}
}

const openCreateModal = () => {
	showCreateModal.value = true
}

const openEditModal = () => {
	showEditModal.value = true
}

const onPlanCreated = () => {
	fetchPlans()
}

const onPlanUpdated = (updatedPlan: any) => {
	fetchPlans()
	if (selectedPlan.value && selectedPlan.value.id === updatedPlan.id) {
		selectPlan(updatedPlan)
	}
}

const onPlanDeleted = () => {
	selectedPlan.value = null
	fetchPlans()
}

onMounted(() => {
	fetchPlans()
})
</script>

<style scoped>
.empty-content {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 20px;
}

.icon-bg {
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
