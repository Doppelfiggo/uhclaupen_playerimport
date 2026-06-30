( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {

		var selectAll  = document.getElementById( 'uhc-select-all' );
		var bulkTeam   = document.getElementById( 'uhc-bulk-team' );
		var bulkApply  = document.getElementById( 'uhc-bulk-apply' );
		var rowChecks  = document.querySelectorAll( '.uhc-row-check' );

		if ( ! selectAll || ! bulkApply ) {
			return; // Not on the preview page or no unmatched rows.
		}

		// ── Select all checkbox ──────────────────────────────────────────
		selectAll.addEventListener( 'change', function () {
			rowChecks.forEach( function ( cb ) {
				cb.checked = selectAll.checked;
			} );
		} );

		// Uncheck "select all" if any individual checkbox is unchecked.
		rowChecks.forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				var allChecked = Array.from( rowChecks ).every( function ( c ) { return c.checked; } );
				selectAll.checked = allChecked;
			} );
		} );

		// ── Bulk apply ───────────────────────────────────────────────────
		bulkApply.addEventListener( 'click', function () {
			var teamId = bulkTeam.value;
			if ( ! teamId ) {
				bulkTeam.focus();
				bulkTeam.style.outline = '2px solid #c5221f';
				setTimeout( function () { bulkTeam.style.outline = ''; }, 1500 );
				return;
			}

			rowChecks.forEach( function ( cb ) {
				if ( ! cb.checked ) return;
				var rowIndex = cb.getAttribute( 'data-row' );
				var select   = document.querySelector( '.uhc-row-team-select[data-row="' + rowIndex + '"]' );
				if ( select ) {
					select.value = teamId;
					// Visual feedback: highlight the row briefly.
					var row = select.closest( 'tr' );
					if ( row ) {
						row.style.transition = 'background 0.3s';
						row.style.background = '#e8f5e9';
						setTimeout( function () { row.style.background = ''; }, 800 );
					}
				}
			} );
		} );

	} );
} )();
