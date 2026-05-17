<div id="organization-trial-settings" class="section">
	<h2>Trial Organization Settings</h2>
	<p class="settings-hint">Configure default limits and duration for trial organizations.</p>

	<div class="form-group">
		<label for="trial-duration">Trial Duration</label>
		<input type="text" id="trial-duration" name="trial_duration" value="" placeholder="e.g. 7 days" />
		<em>Duration string parsable by PHP (e.g. "7 days", "14 days", "1 month").</em>
	</div>

	<div class="form-group">
		<label for="trial-max-members">Max Members</label>
		<input type="number" id="trial-max-members" name="trial_max_members" value="" min="1" />
	</div>

	<div class="form-group">
		<label for="trial-max-projects">Max Projects</label>
		<input type="number" id="trial-max-projects" name="trial_max_projects" value="" min="1" />
	</div>

	<div class="form-group">
		<label for="trial-shared-storage">Shared Storage per Project (GB)</label>
		<input type="number" id="trial-shared-storage" name="trial_shared_storage_gb" value="" min="0" step="0.1" />
	</div>

	<div class="form-group">
		<label for="trial-private-storage">Private Storage per User (GB)</label>
		<input type="number" id="trial-private-storage" name="trial_private_storage_gb" value="" min="0" step="0.1" />
	</div>

	<div class="form-group">
		<label for="trial-plan-name">Trial Plan Name</label>
		<input type="text" id="trial-plan-name" name="trial_plan_name" value="" />
	</div>

	<div id="trial-settings-message" class="message" style="display:none"></div>

	<button id="trial-settings-save" class="primary">Save Trial Settings</button>
</div>

<script nonce="<?php p(\OCP\Util::getNonce()) ?>">
document.addEventListener('DOMContentLoaded', function () {
	const initialState = window.OCP?.InitialState?.loadState?.('organization', 'trial_settings') || {};

	const fields = {
		trial_duration: initialState.duration || '7 days',
		trial_max_members: String(initialState.maxMembers ?? 3),
		trial_max_projects: String(initialState.maxProjects ?? 1),
		trial_shared_storage_gb: String((initialState.sharedStoragePerProject || 0) / 1073741824),
		trial_private_storage_gb: String((initialState.privateStoragePerUser || 0) / 1073741824),
		trial_plan_name: initialState.planName || 'Trial Plan',
	};

	Object.entries(fields).forEach(([key, val]) => {
		const el = document.getElementById(key === 'trial_duration' ? 'trial-duration'
			: key === 'trial_max_members' ? 'trial-max-members'
			: key === 'trial_max_projects' ? 'trial-max-projects'
			: key === 'trial_shared_storage_gb' ? 'trial-shared-storage'
			: key === 'trial_private_storage_gb' ? 'trial-private-storage'
			: key === 'trial_plan_name' ? 'trial-plan-name'
			: null);
		if (el) el.value = val;
	});

	document.getElementById('trial-settings-save')?.addEventListener('click', async function () {
		const btn = this;
		btn.disabled = true;
		btn.textContent = 'Saving...';

		const messageEl = document.getElementById('trial-settings-message');

		try {
			const data = {
				trial_duration: document.getElementById('trial-duration').value,
				trial_max_members: parseInt(document.getElementById('trial-max-members').value, 10) || 3,
				trial_max_projects: parseInt(document.getElementById('trial-max-projects').value, 10) || 1,
				trial_shared_storage_gb: parseFloat(document.getElementById('trial-shared-storage').value) || 0,
				trial_private_storage_gb: parseFloat(document.getElementById('trial-private-storage').value) || 0,
				trial_plan_name: document.getElementById('trial-plan-name').value || 'Trial Plan',
			};

			const response = await fetch('<?php p(\OCP\Util::linkToOCS('apps/organization/admin/settings/trial', 1)) ?>', {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json', 'requesttoken': OC?.requestToken || '' },
				body: JSON.stringify(data),
			});

			const result = await response.json();

			if (result.ocs?.meta?.status === 'ok') {
				messageEl.className = 'message success';
				messageEl.textContent = 'Trial settings saved successfully.';
			} else {
				messageEl.className = 'message error';
				messageEl.textContent = result.ocs?.meta?.message || 'Failed to save settings.';
			}
		} catch (e) {
			messageEl.className = 'message error';
			messageEl.textContent = 'Failed to save settings: ' + (e.message || 'Unknown error');
		}

		messageEl.style.display = 'block';
		btn.disabled = false;
		btn.textContent = 'Save Trial Settings';
	});
});
</script>

<style>
#organization-trial-settings .form-group {
	margin-bottom: 16px;
	max-width: 400px;
}

#organization-trial-settings label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

#organization-trial-settings input {
	width: 100%;
	box-sizing: border-box;
}

#organization-trial-settings em {
	font-size: 0.85em;
	color: var(--color-text-lighter);
}

#organization-trial-settings .message {
	margin-bottom: 12px;
	padding: 8px 12px;
	border-radius: var(--border-radius);
}

#organization-trial-settings .message.success {
	background: var(--color-success-light);
	color: var(--color-success-hover);
}

#organization-trial-settings .message.error {
	background: var(--color-error-light);
	color: var(--color-error-hover);
}
</style>
