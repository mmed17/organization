<template>
	<NcAppNavigation>
		<div class="mode-chip">{{ modeLabel }}</div>

		<NcAppNavigationItem
			:active="activeItem === 'organizations'"
			name="Organizations"
			@click="$emit('update:activeItem', 'organizations')">
			<template #icon>
				<AccountGroup :size="20" />
			</template>
		</NcAppNavigationItem>
		<NcAppNavigationItem
			v-if="showPlans"
			:active="activeItem === 'plans'"
			name="Subscription Plans"
			@click="$emit('update:activeItem', 'plans')">
			<template #icon>
				<CardAccountDetails :size="20" />
			</template>
		</NcAppNavigationItem>
	</NcAppNavigation>
</template>

<script setup lang="ts">
import { NcAppNavigation, NcAppNavigationItem } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue'

defineProps<{
	activeItem: string
	showPlans: boolean
	modeLabel: string
}>()

defineEmits(['update:activeItem'])
</script>

<style scoped>
.mode-chip {
	margin: 10px 12px 14px;
	padding: 6px 10px;
	border-radius: 999px;
	font-size: 0.75rem;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	background: color-mix(in srgb, var(--color-primary) 14%, transparent);
	color: var(--color-main-text);
	border: 1px solid color-mix(in srgb, var(--color-primary) 35%, var(--color-border));
}
</style>
