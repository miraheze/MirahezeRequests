const { createMwApp } = require( 'vue' );
const App = require( './App.vue' );

// by the reserved attribute, as content can reuse the id (T15786)
createMwApp( App ).mount(
	'[data-mw-tabs]'
);
