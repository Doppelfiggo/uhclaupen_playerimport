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

/* Upload form: the "Manuell bearbeitete Spieler überspringen" sub-option
   only applies to the "Spieler aktualisieren" mode — hide it otherwise. */
( function () {
	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap   = document.getElementById( 'uhc_skip_edited_wrap' );
		var radios = document.querySelectorAll( 'input[name="uhc_mode"]' );
		if ( ! wrap || ! radios.length ) return;

		function sync() {
			var checked = document.querySelector( 'input[name="uhc_mode"]:checked' );
			var update  = checked && 'update' === checked.value;
			wrap.style.display = update ? 'block' : 'none';
			if ( ! update ) {
				var cb = document.getElementById( 'uhc_skip_edited' );
				if ( cb ) cb.checked = false;
			}
		}

		radios.forEach( function ( r ) { r.addEventListener( 'change', sync ); } );
		sync();
	} );
} )();
