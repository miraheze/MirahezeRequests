<template>
	<cdx-tabs v-model:active="activeTab">
		<cdx-tab
			v-for="tab in tabs"
			:key="tab.name"
			:name="tab.name"
			:label="tab.label"
		>
			<div
				class="mirahezerequests-tab-content"
				v-html="tab.html"
			></div>
		</cdx-tab>
	</cdx-tabs>
</template>

<script>
const { defineComponent } = require( 'vue' );
const {
	CdxTabs,
	CdxTab
} = require( '../codex.js' );

module.exports = exports = defineComponent( {
	name: 'App',

	components: {
		CdxTabs,
		CdxTab
	},

	data() {
		const root = document.getElementById( 'mirahezerequests-tabs-root' );
		const raw = root ? root.getAttribute( 'data-tabs' ) : '[]';
		const tabs = raw ? JSON.parse( raw ) : [];

		return {
			tabs,
			activeTab: tabs.length ? tabs[ 0 ].name : null
		};
	}
} );
</script>
