( function () {
	const { __, _n, sprintf } = wp.i18n;
	const data = window.swaplineAdmin;
	const $ = ( selector, root = document ) => root.querySelector( selector );
	const $$ = ( selector, root = document ) => Array.from( root.querySelectorAll( selector ) );

	let profiles = data.profiles || {};
	let running = false;
	let cancelRequested = false;

	function el( tag, attrs = {}, children = [] ) {
		const node = document.createElement( tag );
		Object.entries( attrs ).forEach( ( [ key, value ] ) => {
			if ( 'text' === key ) {
				node.textContent = value;
			} else if ( 'className' === key ) {
				node.className = value;
			} else {
				node.setAttribute( key, value );
			}
		} );
		children.forEach( ( child ) => node.append( child ) );
		return node;
	}

	async function post( action, fields = {} ) {
		const body = new FormData();
		body.append( 'action', 'swapline_' + action );
		body.append( 'nonce', data.nonces[ action ] );
		Object.entries( fields ).forEach( ( [ key, value ] ) => body.append( key, value ) );

		let json;
		try {
			const response = await fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } );
			json = await response.json();
		} catch ( e ) {
			throw new Error( __( 'The server returned an unexpected response. Try a lower max page size.', 'swapline' ) );
		}
		if ( ! json || ! json.success ) {
			throw new Error( ( json && json.data && json.data.message ) || __( 'Something went wrong.', 'swapline' ) );
		}
		return json.data;
	}

	function notice( type, message ) {
		const box = $( '#swapline-notice' );
		if ( ! box ) {
			return;
		}
		box.className = 'notice inline notice-' + type;
		$( 'p', box ).textContent = message;
		if ( ! message ) {
			box.classList.add( 'hidden' );
		}
	}

	function number( value ) {
		return Number( value ).toLocaleString();
	}

	function renderResults( run ) {
		const box = $( '#swapline-results' );
		box.replaceChildren();
		box.classList.remove( 'hidden' );

		const totals = run.totals;
		const dry = run.config.dry_run;
		/* translators: %s: number of cells. */
		const cells = sprintf( _n( '%s cell', '%s cells', totals.cells_changed, 'swapline' ), number( totals.cells_changed ) );
		/* translators: %s: number of rows. */
		const rows = sprintf( _n( '%s row', '%s rows', totals.rows_matched, 'swapline' ), number( totals.rows_matched ) );
		/* translators: %s: number of replacements. */
		const replacements = sprintf( _n( '%s replacement', '%s replacements', totals.replacements, 'swapline' ), number( totals.replacements ) );
		let summary = dry
			? /* translators: 1: cells, 2: rows, 3: replacements, such as "3 cells". */ sprintf( __( 'Dry run: %1$s in %2$s would change (%3$s). Nothing was written.', 'swapline' ), cells, rows, replacements )
			: /* translators: 1: cells, 2: rows, 3: replacements, such as "3 cells". */ sprintf( __( '%1$s in %2$s changed (%3$s).', 'swapline' ), cells, rows, replacements );
		if ( 'cancelled' === run.status ) {
			summary += ' ' + __( 'The run was cancelled before it finished.', 'swapline' );
		}

		box.append( el( 'h2', { text: dry ? __( 'Dry run results', 'swapline' ) : __( 'Results', 'swapline' ) } ) );
		box.append( el( 'p', { text: summary } ) );

		if ( totals.failures > 0 ) {
			box.append(
				el( 'div', { className: 'notice notice-warning inline' }, [
					el( 'p', {
						text: sprintf(
							/* translators: %s: number of values. */
							_n(
								'%s value contained the search but is broken serialized data or JSON, so it was left unchanged.',
								'%s values contained the search but are broken serialized data or JSON, so they were left unchanged.',
								totals.failures,
								'swapline'
							),
							number( totals.failures )
						),
					} ),
				] )
			);
		}
		if ( totals.rows_not_written > 0 ) {
			box.append(
				el( 'div', { className: 'notice notice-error inline' }, [
					el( 'p', {
						text: sprintf(
							/* translators: %s: number of rows. */
							_n( '%s row could not be written.', '%s rows could not be written.', totals.rows_not_written, 'swapline' ),
							number( totals.rows_not_written )
						),
					} ),
				] )
			);
		}

		const matched = Object.entries( run.tables ).filter( ( [ , counts ] ) => counts.cells_matched > 0 || counts.failures > 0 );
		const untouched = Object.keys( run.tables ).length - matched.length;

		if ( matched.length ) {
			const headings = [
				__( 'Table', 'swapline' ),
				__( 'Rows scanned', 'swapline' ),
				__( 'Rows matched', 'swapline' ),
				__( 'Cells matched', 'swapline' ),
				__( 'Replacements', 'swapline' ),
				__( 'Left unchanged', 'swapline' ),
			];
			const thead = el( 'thead', {}, [ el( 'tr', {}, headings.map( ( text, i ) => el( 'th', { scope: 'col', className: i ? 'num' : '', text } ) ) ) ] );
			const tbody = el(
				'tbody',
				{},
				matched.map( ( [ table, counts ] ) =>
					el( 'tr', {}, [
						el( 'td', {}, [ el( 'code', { text: table } ) ] ),
						el( 'td', { className: 'num', text: number( counts.rows_scanned ) } ),
						el( 'td', { className: 'num', text: number( counts.rows_matched ) } ),
						el( 'td', { className: 'num', text: number( counts.cells_matched ) } ),
						el( 'td', { className: 'num', text: number( counts.replacements ) } ),
						el( 'td', { className: 'num', text: number( counts.failures ) } ),
					] )
				)
			);
			box.append( el( 'div', { className: 'swapline-scroll' }, [ el( 'table', { className: 'widefat striped swapline-report' }, [ thead, tbody ] ) ] ) );
		}
		if ( untouched > 0 ) {
			box.append(
				el( 'p', {
					className: 'description',
					text: sprintf(
						/* translators: %s: number of tables. */
						_n( '%s other table had no matches.', '%s other tables had no matches.', untouched, 'swapline' ),
						number( untouched )
					),
				} )
			);
		}
		if ( totals.cells_changed > 0 ) {
			const button = el( 'button', { type: 'button', className: 'button', text: __( 'View exactly what changed', 'swapline' ) } );
			button.addEventListener( 'click', () => loadChanges( run.id, 1, '' ) );
			box.append( el( 'p', {}, [ button ] ) );
		}
	}

	async function loadChanges( runId, page, table ) {
		const box = $( '#swapline-changes' );
		box.classList.remove( 'hidden' );
		box.setAttribute( 'aria-busy', 'true' );

		let result;
		try {
			result = await post( 'changes', { run: runId, page, table } );
		} catch ( e ) {
			box.replaceChildren( el( 'div', { className: 'notice notice-error inline' }, [ el( 'p', { text: e.message } ) ] ) );
			box.removeAttribute( 'aria-busy' );
			return;
		}

		box.replaceChildren();
		box.append( el( 'h2', { text: __( 'Changed cells', 'swapline' ) } ) );

		const run = result.run;
		let intro = sprintf(
			/* translators: %s: number of cells. */
			_n( '%s recorded change.', '%s recorded changes.', result.total, 'swapline' ),
			number( result.total )
		);
		if ( run.truncated ) {
			intro +=
				' ' +
				sprintf(
					/* translators: %s: number of changed cells in the run. */
					__( 'The run changed %s cells; only the first ones are recorded.', 'swapline' ),
					number( run.totals.cells_changed )
				);
		}
		intro += ' ' + __( 'Long values are split after ; { } , and > to make the diff readable.', 'swapline' );
		box.append( el( 'p', { className: 'description', text: intro } ) );

		const nav = el( 'div', { className: 'tablenav top' } );
		const select = el( 'select', { id: 'swapline-changes-table' } );
		select.append( el( 'option', { value: '', text: __( 'All tables', 'swapline' ) } ) );
		Object.entries( run.tables )
			.filter( ( [ , counts ] ) => counts.cells_changed > 0 )
			.forEach( ( [ name ] ) => {
				const option = el( 'option', { value: name, text: name } );
				option.selected = name === result.table;
				select.append( option );
			} );
		select.addEventListener( 'change', () => loadChanges( runId, 1, select.value ) );
		nav.append(
			el( 'div', { className: 'alignleft actions' }, [ el( 'label', { for: 'swapline-changes-table', className: 'screen-reader-text', text: __( 'Filter by table', 'swapline' ) } ), select ] )
		);

		const pager = el( 'div', { className: 'tablenav-pages' } );
		const prev = el( 'button', { type: 'button', className: 'button', text: __( 'Previous', 'swapline' ) } );
		const next = el( 'button', { type: 'button', className: 'button', text: __( 'Next', 'swapline' ) } );
		prev.disabled = result.page <= 1;
		next.disabled = result.page >= result.pages;
		prev.addEventListener( 'click', () => loadChanges( runId, result.page - 1, result.table ) );
		next.addEventListener( 'click', () => loadChanges( runId, result.page + 1, result.table ) );
		pager.append(
			prev,
			el( 'span', {
				className: 'paging-input',
				/* translators: 1: current page, 2: total pages. */
				text: sprintf( __( 'Page %1$s of %2$s', 'swapline' ), number( result.page ), number( result.pages ) ),
			} ),
			next
		);
		nav.append( pager );
		box.append( nav );

		result.items.forEach( ( item ) => {
			const meta = [ el( 'code', { text: item.table } ), document.createTextNode( ' · ' + item.row + ' · ' ), el( 'code', { text: item.column } ) ];
			const card = el( 'div', { className: 'swapline-change' }, [ el( 'h3', {}, meta ) ] );
			const details = sprintf(
				/* translators: %s: number of replacements. */
				_n( '%s replacement', '%s replacements', item.replacements, 'swapline' ),
				number( item.replacements )
			);
			card.append(
				el( 'p', {
					className: 'description',
					text: item.truncated ? details + ' · ' + __( 'Value is large; the recorded excerpt starts near the first change.', 'swapline' ) : details,
				} )
			);
			const diff = el( 'div', { className: 'swapline-diff' } );
			diff.innerHTML = item.diff;
			card.append( diff );
			box.append( card );
		} );

		box.removeAttribute( 'aria-busy' );
		box.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}

	function initForm() {
		const form = $( '#swapline-form' );
		if ( ! form ) {
			return;
		}

		const runButton = $( '#swapline-run' );
		const cancelButton = $( '#swapline-cancel' );
		const progressBox = $( '#swapline-progress' );
		const progressBar = $( 'progress', progressBox );
		const progressText = $( '.swapline-progress-text', progressBox );
		const boxes = () => $$( 'input[name="tables[]"]', form );

		function confirmLiveRun() {
			const dialog = $( '#swapline-confirm' );
			const backup = $( '#swapline-confirm-backup' );
			if ( ! dialog || ! dialog.showModal || ! window.swaplineRunBackup ) {
				return Promise.resolve(
					window.confirm( __( 'This writes to the database and cannot be undone. Make sure you have a backup. Continue?', 'swapline' ) ) ? { backup: false } : null
				);
			}
			return new Promise( ( resolve ) => {
				dialog.returnValue = '';
				dialog.addEventListener( 'close', () => resolve( 'run' === dialog.returnValue ? { backup: backup.checked } : null ), { once: true } );
				dialog.showModal();
			} );
		}

		function updateCount() {
			const checked = boxes().filter( ( box ) => box.checked ).length;
			$( '#swapline-selected-count' ).textContent = sprintf(
				/* translators: 1: selected tables, 2: total tables. */
				__( '%1$s of %2$s selected', 'swapline' ),
				number( checked ),
				number( boxes().length )
			);
		}

		function updateLabels() {
			runButton.textContent = form.elements.dry_run.checked ? __( 'Run dry run', 'swapline' ) : __( 'Run search & replace', 'swapline' );
			$( '.swapline-regex-help', form ).classList.toggle( 'hidden', ! form.elements.regex.checked );
		}

		function readConfig() {
			return {
				search: form.elements.search.value,
				replace: form.elements.replace.value,
				tables: boxes()
					.filter( ( box ) => box.checked )
					.map( ( box ) => box.value ),
				case_insensitive: form.elements.case_insensitive.checked,
				regex: form.elements.regex.checked,
				skip_guid: form.elements.skip_guid.checked,
				columns: form.elements.columns.value,
				skip_columns: form.elements.skip_columns.value,
				dry_run: form.elements.dry_run.checked,
				page_size: parseInt( form.elements.page_size.value, 10 ) || data.defaults.page_size,
			};
		}

		function applyConfig( config ) {
			const merged = Object.assign( {}, data.defaults, config );
			form.elements.search.value = merged.search;
			form.elements.replace.value = merged.replace;
			[ 'case_insensitive', 'regex', 'skip_guid', 'dry_run' ].forEach( ( key ) => ( form.elements[ key ].checked = !! merged[ key ] ) );
			form.elements.columns.value = [].concat( merged.columns || [] ).join( ', ' );
			form.elements.skip_columns.value = [].concat( merged.skip_columns || [] ).join( ', ' );
			form.elements.page_size.value = merged.page_size;
			const wanted = new Set( merged.tables || [] );
			boxes().forEach( ( box ) => ( box.checked = wanted.has( box.value ) ) );
			const missing = [ ...wanted ].filter( ( name ) => ! boxes().some( ( box ) => box.value === name ) );
			updateCount();
			updateLabels();
			return missing;
		}

		function renderProfiles( selected ) {
			const select = $( '#swapline-profile' );
			const first = select.options[ 0 ];
			select.replaceChildren( first );
			Object.keys( profiles )
				.sort( ( a, b ) => a.localeCompare( b ) )
				.forEach( ( name ) => {
					const option = el( 'option', { value: name, text: name } );
					option.selected = name === selected;
					select.append( option );
				} );
		}

		function setRunning( state ) {
			running = state;
			runButton.disabled = state;
			cancelButton.classList.toggle( 'hidden', ! state );
			cancelButton.disabled = false;
			$$( 'input, textarea, select, button', form )
				.filter( ( node ) => node !== cancelButton && node !== runButton )
				.forEach( ( node ) => ( node.disabled = state ) );
		}

		function showProgress( progress ) {
			progressBox.classList.remove( 'hidden' );
			progressBar.value = progress.percent;
			if ( progress.done ) {
				progressText.textContent = __( 'Finished.', 'swapline' );
				return;
			}
			progressText.textContent = sprintf(
				/* translators: 1: table name, 2: table number, 3: table count, 4: rows done, 5: rows in table. */
				__( 'Table %2$s of %3$s: %1$s, %4$s of %5$s rows', 'swapline' ),
				progress.table,
				number( progress.table_index + 1 ),
				number( progress.table_count ),
				number( progress.rows_done ),
				number( progress.rows_total )
			);
		}

		$$( '[data-select]', form ).forEach( ( button ) =>
			button.addEventListener( 'click', () => {
				const mode = button.dataset.select;
				boxes().forEach( ( box ) => {
					const row = box.closest( 'tr' );
					if ( 'none' === mode ) {
						box.checked = false;
					} else if ( 'core' === mode ) {
						box.checked = '1' === row.dataset.core;
					} else if ( ! row.hidden ) {
						box.checked = true;
					}
				} );
				updateCount();
			} )
		);

		function filterRows() {
			const term = $( '#swapline-table-filter' ).value.trim().toLowerCase();
			const siteSelect = $( '#swapline-site-filter' );
			const site = siteSelect ? siteSelect.value : '';
			boxes().forEach( ( box ) => {
				const row = box.closest( 'tr' );
				row.hidden = ( !! term && ! row.dataset.name.toLowerCase().includes( term ) ) || ( '' !== site && row.dataset.site !== site );
			} );
		}

		$( '#swapline-table-filter' ).addEventListener( 'input', filterRows );
		if ( $( '#swapline-site-filter' ) ) {
			$( '#swapline-site-filter' ).addEventListener( 'change', filterRows );
		}

		form.addEventListener( 'change', ( event ) => {
			if ( event.target.name === 'tables[]' ) {
				updateCount();
			}
			updateLabels();
		} );

		$( '#swapline-profile-load' ).addEventListener( 'click', () => {
			const name = $( '#swapline-profile' ).value;
			if ( ! name || ! profiles[ name ] ) {
				notice( 'warning', __( 'Choose a profile to load.', 'swapline' ) );
				return;
			}
			const missing = applyConfig( profiles[ name ] );
			$( '#swapline-profile-name' ).value = name;
			notice(
				missing.length ? 'warning' : 'info',
				missing.length
					? sprintf(
							/* translators: 1: profile name, 2: list of tables. */
							__( 'Loaded "%1$s". These tables no longer exist and were skipped: %2$s', 'swapline' ),
							name,
							missing.join( ', ' )
					  )
					: /* translators: %s: profile name. */ sprintf( __( 'Loaded "%s".', 'swapline' ), name )
			);
		} );

		$( '#swapline-profile-delete' ).addEventListener( 'click', async () => {
			const name = $( '#swapline-profile' ).value;
			if ( ! name ) {
				notice( 'warning', __( 'Choose a profile to delete.', 'swapline' ) );
				return;
			}
			/* translators: %s: profile name. */
			if ( ! window.confirm( sprintf( __( 'Delete the profile "%s"?', 'swapline' ), name ) ) ) {
				return;
			}
			try {
				profiles = ( await post( 'profile_delete', { name } ) ).profiles;
				renderProfiles( '' );
				/* translators: %s: profile name. */
				notice( 'success', sprintf( __( 'Deleted "%s".', 'swapline' ), name ) );
			} catch ( e ) {
				notice( 'error', e.message );
			}
		} );

		$( '#swapline-profile-save' ).addEventListener( 'click', async () => {
			const name = $( '#swapline-profile-name' ).value.trim();
			if ( ! name ) {
				notice( 'warning', __( 'Give the profile a name.', 'swapline' ) );
				$( '#swapline-profile-name' ).focus();
				return;
			}
			try {
				profiles = ( await post( 'profile_save', { name, config: JSON.stringify( readConfig() ) } ) ).profiles;
				renderProfiles( name );
				/* translators: %s: profile name. */
				notice( 'success', sprintf( __( 'Saved "%s".', 'swapline' ), name ) );
			} catch ( e ) {
				notice( 'error', e.message );
			}
		} );

		cancelButton.addEventListener( 'click', () => {
			cancelRequested = true;
			cancelButton.disabled = true;
			progressText.textContent = __( 'Cancelling after the current batch…', 'swapline' );
		} );

		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			if ( running ) {
				return;
			}
			const config = readConfig();
			if ( ! config.search ) {
				notice( 'error', __( 'Enter something to search for.', 'swapline' ) );
				form.elements.search.focus();
				return;
			}
			if ( ! config.tables.length ) {
				notice( 'error', __( 'Select at least one table.', 'swapline' ) );
				return;
			}
			let backupFirst = false;
			let backupName = '';
			if ( ! config.dry_run ) {
				const choice = await confirmLiveRun();
				if ( ! choice ) {
					return;
				}
				backupFirst = choice.backup;
			}

			notice( '', '' );
			$( '#swapline-results' ).classList.add( 'hidden' );
			$( '#swapline-changes' ).classList.add( 'hidden' );
			cancelRequested = false;
			setRunning( true );

			try {
				if ( backupFirst ) {
					const backup = await window.swaplineRunBackup( config.tables, ( progress ) => {
						progressBox.classList.remove( 'hidden' );
						progressBar.value = progress.percent;
						progressText.textContent = sprintf(
							/* translators: 1: table name, 2: table number, 3: table count. */
							__( 'Backing up table %2$s of %3$s: %1$s', 'swapline' ),
							progress.table,
							number( Math.min( progress.table_index + 1, progress.table_count ) ),
							number( progress.table_count )
						);
						return cancelRequested;
					} );
					if ( ! backup ) {
						notice( 'warning', __( 'The backup was cancelled, so nothing was changed.', 'swapline' ) );
						setRunning( false );
						return;
					}
					backupName = backup.backup.name;
					notice( 'info', __( 'The selected tables were backed up first. The backup is listed on the Backup tab.', 'swapline' ) );
				}
				const progress = await post( 'start', { config: JSON.stringify( config ), backup: backupName } );
				await drive( progress );
			} catch ( e ) {
				notice( 'error', e.message );
				await offerResume();
			}
			setRunning( false );
		} );

		const resumeBox = $( '#swapline-resume' );

		function showUnfinished( job ) {
			if ( ! job ) {
				resumeBox.classList.add( 'hidden' );
				return;
			}
			$( 'p', resumeBox ).textContent = job.dry_run
				? sprintf(
						/* translators: 1: table number, 2: table count. */
						__( 'A dry run stopped at table %1$s of %2$s. Nothing was written. Resume it, or stop and keep what it found.', 'swapline' ),
						number( job.table_index + 1 ),
						number( job.table_count )
				  )
				: sprintf(
						/* translators: 1: table number, 2: table count. */
						__( 'A run stopped at table %1$s of %2$s. The tables before it are already changed. Resume it to finish, or stop and keep the record of what changed.', 'swapline' ),
						number( job.table_index + 1 ),
						number( job.table_count )
				  );
			resumeBox.dataset.job = job.id;
			resumeBox.classList.remove( 'hidden' );
		}

		async function offerResume() {
			try {
				showUnfinished( ( await post( 'status' ) ).job );
			} catch ( e ) {
				// The server can't be reached; reloading the page offers the resume again.
			}
		}

		async function drive( start ) {
			const jobId = start.id;
			let progress = start;
			let run = null;
			showProgress( progress );
			while ( ! run ) {
				if ( cancelRequested ) {
					run = ( await post( 'cancel', { job: jobId } ) ).run;
					break;
				}
				const result = await post( 'step', { job: jobId } );
				progress = result.progress;
				showProgress( progress );
				run = result.run || null;
			}
			if ( run ) {
				renderResults( run );
			}
		}

		$( '[data-action="resume"]', resumeBox ).addEventListener( 'click', async () => {
			const id = resumeBox.dataset.job;
			resumeBox.classList.add( 'hidden' );
			cancelRequested = false;
			setRunning( true );
			notice( '', '' );
			try {
				const status = ( await post( 'status' ) ).job;
				if ( ! status || status.id !== id ) {
					throw new Error( __( 'That run is no longer active.', 'swapline' ) );
				}
				await drive( status );
			} catch ( e ) {
				notice( 'error', e.message );
				await offerResume();
			}
			setRunning( false );
		} );

		$( '[data-action="stop"]', resumeBox ).addEventListener( 'click', async () => {
			try {
				const run = ( await post( 'cancel', { job: resumeBox.dataset.job } ) ).run;
				resumeBox.classList.add( 'hidden' );
				if ( run ) {
					renderResults( run );
				}
			} catch ( e ) {
				notice( 'error', e.message );
			}
		} );

		showUnfinished( data.unfinished );

		window.addEventListener( 'beforeunload', ( event ) => {
			if ( running ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );

		updateCount();
		updateLabels();
	}

	function initHistory() {
		const source = $( '#swapline-history-data' );
		if ( ! source ) {
			return;
		}
		const runs = JSON.parse( source.textContent );
		$$( '[data-run]' ).forEach( ( button ) =>
			button.addEventListener( 'click', () => {
				const run = runs.find( ( item ) => item.id === button.dataset.run );
				if ( ! run ) {
					return;
				}
				renderResults( run );
				if ( run.totals.cells_changed > 0 ) {
					loadChanges( run.id, 1, '' );
				} else {
					$( '#swapline-changes' ).classList.add( 'hidden' );
				}
			} )
		);
	}

	function initUndo() {
		$$( '[data-undo]' ).forEach( ( button ) =>
			button.addEventListener( 'click', async () => {
				if ( ! window.confirm( __( 'Put back the original value of every cell this run changed? Cells edited since are left as they are.', 'swapline' ) ) ) {
					return;
				}
				button.disabled = true;
				notice( '', '' );
				try {
					const result = await post( 'undo', { run: button.dataset.undo } );
					const message = sprintf(
						/* translators: %s: number of cells. */
						_n( '%s cell was put back.', '%s cells were put back.', result.restored, 'swapline' ),
						number( result.restored )
					);
					const skipped = result.changed_since + result.missing;
					notice(
						skipped ? 'warning' : 'success',
						skipped
							? message +
									' ' +
									sprintf(
										/* translators: %s: number of cells. */
										_n( '%s cell was left alone because it changed since or its row is gone.', '%s cells were left alone because they changed since or their rows are gone.', skipped, 'swapline' ),
										number( skipped )
									)
							: message
					);
					button.replaceWith( el( 'span', { className: 'description', text: __( 'Undone', 'swapline' ) } ) );
				} catch ( e ) {
					button.disabled = false;
					notice( 'error', e.message );
				}
			} )
		);
	}

	initForm();
	initHistory();
	initUndo();
} )();
