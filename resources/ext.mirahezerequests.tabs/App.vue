<template>
	<cdx-tabs v-model:active="activeTab">
		<cdx-tab
			v-for="tab in tabs"
			:key="tab.name"
			:name="tab.name"
			:label="tab.label"
		>
			<!-- form HTML built by the server; content can't set data-mw-tabs (T15786) -->
			<!-- eslint-disable vue/no-v-html -->
			<div
				class="mirahezerequests-tab-content"
				v-html="tab.html"
			></div>
			<!-- eslint-enable vue/no-v-html -->
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
		const root = document.querySelector( '[data-mw-tabs]' );
		const raw = root ? root.getAttribute( 'data-mw-tabs' ) : '[]';
		const tabs = raw ? JSON.parse( raw ) : [];

		return {
			tabs,
			activeTab: tabs.length ? tabs[ 0 ].name : null
		};
	}
} );
</script>
