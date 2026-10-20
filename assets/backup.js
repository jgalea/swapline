( function () {
	const { __, _n, sprintf } = wp.i18n;
	const data = window.swaplineBackup;
	const $ = ( selector, root = document ) => root.querySelector( selector );
	const $$ = ( selector, root = document ) => Array.from( root.querySelectorAll( selector ) );

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

	function body( action, fields ) {
		const form = new FormData();
		form.append( 'action', 'swapline_' + action );
		form.append( 'nonce', data.nonces[ action ] );
		Object.entries( fields ).forEach( ( [ key, value ] ) => form.append( key, value ) );
		return form;
	}

	function unwrap( json ) {
		if ( ! json || ! json.success ) {
			throw new Error( ( json && json.data && json.data.message ) || __( 'Something went wrong.', 'swapline' ) );
		}
		return json.data;
	}

	async function post( action, fields = {} ) {
		let json;
		try {
			const response = await fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body( action, fields ) } );
			json = await response.json();
		} catch ( e ) {
			throw new Error( __( 'The server returned an unexpected response.', 'swapline' ) );
		}
		return unwrap( json );
	}

	function upload( file, onProgress ) {
		return new Promise( ( resolve, reject ) => {
			const xhr = new XMLHttpRequest();
			xhr.open( 'POST', data.ajaxUrl );
			xhr.withCredentials = true;
			xhr.responseType = 'json';
			xhr.upload.addEventListener( 'progress', ( event ) => event.lengthComputable && onProgress( event.loaded / event.total ) );
			xhr.addEventListener( 'load', () => {
				try {
					resolve( unwrap( xhr.response ) );
				} catch ( e ) {
					reject( e );
				}
			} );
			xhr.addEventListener( 'error', () => reject( new Error( __( 'The upload failed.', 'swapline' ) ) ) );
			xhr.send( body( 'import_upload', { file } ) );
		} );
	}

	function notice( type, message ) {
		const box = $( '#swapline-notice' );
		box.className = 'notice inline notice-' + type;
		$( 'p', box ).textContent = message;
		if ( ! message ) {
			box.classList.add( 'hidden' );
		}
	}

	function number( value ) {
		return Number( value ).toLocaleString();
	}

	function readReplace( form ) {
		return {
			replace_enabled: form.elements.replace_enabled.checked,
			search: form.elements.search.value,
			replace: form.elements.replace.value,
			case_insensitive: form.elements.case_insensitive.checked,
			regex: form.elements.regex.checked,
			skip_guid: form.elements.skip_guid.checked,
		};
	}

	function initReplaceToggle( form ) {
		const toggle = () => $( '.swapline-replace-fields', form ).classList.toggle( 'hidden', ! form.elements.replace_enabled.checked );
		form.elements.replace_enabled.addEventListener( 'change', toggle );
		toggle();
	}

	function progressUi() {
		const box = $( '#swapline-progress' );
		return {
			show( percent, text ) {
				box.classList.remove( 'hidden' );
				$( 'progress', box ).value = percent;
				$( '.swapline-progress-text', box ).textContent = text;
			},
			hide() {
				box.classList.add( 'hidden' );
			},
		};
	}

	/**
	 * Backs up the given tables (or every table when the list is empty).
	 * onProgress gets each progress report and returns true to cancel.
	 * Resolves to the backup, or null when cancelled.
	 */
	async function runBackup( config, onProgress ) {
		let progress = await post( 'backup_start', { config: JSON.stringify( config ) } );
		const job = progress.id;
		while ( true ) {
			if ( onProgress( progress ) ) {
				await post( 'backup_cancel', { job } );
				return null;
			}
			const result = await post( 'backup_step', { job } );
			progress = result.progress;
			if ( result.backup ) {
				onProgress( progress );
				return result;
			}
		}
	}

	window.swaplineRunBackup = ( tables, onProgress ) => runBackup( { scope: 'selected', tables }, onProgress );

	function renderBackups( rows ) {
		const list = $( '#swapline-backup-list' );
		if ( ! list ) {
			return;
		}
		list.replaceChildren();
		if ( ! rows.length ) {
			list.append( el( 'tr', {}, [ el( 'td', { colspan: '5', text: __( 'No backups yet.', 'swapline' ) } ) ] ) );
			return;
		}
		rows.forEach( ( row ) => {
			const remove = el( 'button', { type: 'button', className: 'button button-link-delete', text: __( 'Delete', 'swapline' ) } );
			remove.addEventListener( 'click', async () => {
				/* translators: %s: backup date. */
				if ( ! window.confirm( sprintf( __( 'Delete the backup from %s?', 'swapline' ), row.created ) ) ) {
					return;
				}
				try {
					renderBackups( ( await post( 'backup_delete', { file: row.name } ) ).backups );
					notice( 'success', __( 'Backup deleted.', 'swapline' ) );
				} catch ( e ) {
					notice( 'error', e.message );
				}
			} );
			list.append(
				el( 'tr', {}, [
					el( 'td', {}, [ document.createTextNode( row.created ), el( 'br' ), el( 'code', { className: 'swapline-file', text: row.name } ) ] ),
					el( 'td', { className: 'num', text: row.size } ),
					el( 'td', { className: 'num', text: row.tables } ),
					el( 'td', { text: row.note } ),
					el( 'td', { className: 'swapline-actions' }, [ el( 'a', { className: 'button', href: row.download, text: __( 'Download', 'swapline' ) } ), remove ] ),
				] )
			);
		} );
	}

	function initBackup() {
		const form = $( '#swapline-backup-form' );
		if ( ! form ) {
			return;
		}

		const runButton = $( '#swapline-backup-run' );
		const cancelButton = $( '#swapline-backup-cancel' );
		const ui = progressUi();
		const boxes = () => $$( 'input[name="tables[]"]', form );
		let running = false;
		let cancel = false;

		renderBackups( JSON.parse( $( '#swapline-backup-data' ).textContent ) );
		initReplaceToggle( form );

		const toggleScope = () => $( '.swapline-backup-tables', form ).classList.toggle( 'hidden', 'selected' !== form.elements.scope.value );
		$$( 'input[name="scope"]', form ).forEach( ( radio ) => radio.addEventListener( 'change', toggleScope ) );
		toggleScope();

		$$( '[data-select]', form ).forEach( ( button ) =>
			button.addEventListener( 'click', () => {
				const mode = button.dataset.select;
				boxes().forEach( ( box ) => ( box.checked = 'all' === mode || ( 'core' === mode && '1' === box.closest( 'tr' ).dataset.core ) ) );
			} )
		);

		cancelButton.addEventListener( 'click', () => {
			cancel = true;
			cancelButton.disabled = true;
		} );

		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			if ( running ) {
				return;
			}
			const config = Object.assign( readReplace( form ), {
				scope: form.elements.scope.value,
				tables: boxes()
					.filter( ( box ) => box.checked )
					.map( ( box ) => box.value ),
			} );
			if ( 'selected' === config.scope && ! config.tables.length ) {
				notice( 'error', __( 'Select at least one table.', 'swapline' ) );
				return;
			}
			if ( config.replace_enabled && ! config.search ) {
				notice( 'error', __( 'Enter something to search for.', 'swapline' ) );
				form.elements.search.focus();
				return;
			}

			running = true;
			cancel = false;
			runButton.disabled = true;
			cancelButton.disabled = false;
			cancelButton.classList.remove( 'hidden' );
			notice( '', '' );

			try {
				const result = await runBackup( config, ( progress ) => {
					ui.show(
						progress.percent,
						progress.done
							? __( 'Finished.', 'swapline' )
							: sprintf(
									/* translators: 1: table name, 2: table number, 3: table count. */
									__( 'Table %2$s of %3$s: %1$s', 'swapline' ),
									progress.table,
									number( Math.min( progress.table_index + 1, progress.table_count ) ),
									number( progress.table_count )
							  )
					);
					return cancel;
				} );
				if ( result ) {
					renderBackups( result.backups );
					let message = sprintf(
						/* translators: %s: number of rows. */
						_n( 'Backup created with %s row.', 'Backup created with %s rows.', result.backup.rows, 'swapline' ),
						number( result.backup.rows )
					);
					if ( config.replace_enabled ) {
						message +=
							' ' +
							sprintf(
								/* translators: %s: number of rows. */
								_n(
									'%s row was changed in the file. The live database was not touched.',
									'%s rows were changed in the file. The live database was not touched.',
									result.backup.changed,
									'swapline'
								),
								number( result.backup.changed )
							);
					}
					notice( 'success', message );
				} else {
					ui.hide();
					notice( 'warning', __( 'The backup was cancelled.', 'swapline' ) );
				}
			} catch ( e ) {
				ui.hide();
				notice( 'error', e.message );
			}

			running = false;
			runButton.disabled = false;
			cancelButton.classList.add( 'hidden' );
		} );

		$( '#swapline-retention' ).addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			try {
				const result = await post( 'backup_settings', { keep: event.target.elements.keep.value } );
				renderBackups( result.backups );
				notice(
					'success',
					0 === result.keep
						? __( 'All backups will be kept.', 'swapline' )
						: /* translators: %s: number of backups. */ sprintf( _n( 'The latest %s backup will be kept.', 'The latest %s backups will be kept.', result.keep, 'swapline' ), number( result.keep ) )
				);
			} catch ( e ) {
				notice( 'error', e.message );
			}
		} );

		window.addEventListener( 'beforeunload', ( event ) => {
			if ( running ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	}

	function phaseLabel( phase ) {
		return (
			{
				inflate: __( 'Unpacking the file…', 'swapline' ),
				scan: __( 'Checking every statement…', 'swapline' ),
				load: __( 'Loading into temporary tables…', 'swapline' ),
				replace: __( 'Running the search and replace…', 'swapline' ),
				swap: __( 'Swapping the tables in…', 'swapline' ),
			}[ phase ] || ''
		);
	}

	function renderImport( progress, preserve ) {
		const box = $( '#swapline-results' );
		box.replaceChildren();
		box.classList.remove( 'hidden' );
		const counts = progress.counts;

		if ( progress.error ) {
			box.append( el( 'div', { className: 'notice notice-error inline' }, [ el( 'p', { text: progress.error } ), el( 'p', { text: __( 'Your live database was not changed.', 'swapline' ) } ) ] ) );
			if ( progress.rejected.length ) {
				box.append( el( 'h2', { text: __( 'Statements that are not allowed', 'swapline' ) } ) );
				const rows = progress.rejected.map( ( item ) => el( 'tr', {}, [ el( 'td', {}, [ el( 'code', { text: item.statement } ) ] ), el( 'td', { text: item.reason } ) ] ) );
				box.append(
					el( 'div', { className: 'swapline-scroll' }, [
						el( 'table', { className: 'widefat striped swapline-report' }, [
							el( 'thead', {}, [ el( 'tr', {}, [ el( 'th', { scope: 'col', text: __( 'Statement', 'swapline' ) } ), el( 'th', { scope: 'col', text: __( 'Reason', 'swapline' ) } ) ] ) ] ),
							el( 'tbody', {}, rows ),
						] ),
					] )
				);
				if ( counts.rejected > progress.rejected.length ) {
					box.append(
						el( 'p', {
							className: 'description',
							/* translators: %s: number of statements. */
							text: sprintf( __( 'The first %s are listed.', 'swapline' ), number( progress.rejected.length ) ),
						} )
					);
				}
			}
			return;
		}

		box.append( el( 'h2', { text: __( 'Import complete', 'swapline' ) } ) );
		box.append(
			el( 'p', {
				text: sprintf(
					/* translators: 1: number of tables, 2: number of rows. */
					__( '%1$s tables and %2$s rows were imported.', 'swapline' ),
					number( progress.tables.length ),
					number( counts.rows )
				),
			} )
		);
		if ( counts.changed > 0 || counts.failures > 0 ) {
			box.append(
				el( 'p', {
					text: sprintf(
						/* translators: 1: number of rows, 2: number of values. */
						__( 'The search and replace changed %1$s rows. %2$s broken serialized or JSON values were left unchanged.', 'swapline' ),
						number( counts.changed ),
						number( counts.failures )
					),
				} )
			);
		}
		if ( counts.skipped > 0 ) {
			box.append(
				el( 'p', {
					className: 'description',
					text: sprintf(
						/* translators: %s: number of statements. */
						_n(
							'%s statement was not needed and was skipped, such as LOCK TABLES or a DROP TABLE that the temporary tables make unnecessary.',
							'%s statements were not needed and were skipped, such as LOCK TABLES or a DROP TABLE that the temporary tables make unnecessary.',
							counts.skipped,
							'swapline'
						),
						number( counts.skipped )
					),
				} )
			);
		}
		box.append( el( 'p', {}, [ el( 'code', { text: progress.tables.join( ', ' ) } ) ] ) );
		if ( ! preserve ) {
			box.append( el( 'p', { text: __( 'Users and the site address now come from the file, so you may need to log in again.', 'swapline' ) } ) );
		}
	}

	function initImport() {
		const form = $( '#swapline-import-form' );
		if ( ! form ) {
			return;
		}

		const runButton = $( '#swapline-import-run' );
		const cancelButton = $( '#swapline-import-cancel' );
		const ui = progressUi();
		let running = false;
		let cancel = false;

		initReplaceToggle( form );

		const toggleSource = () => $$( '.swapline-source', form ).forEach( ( box ) => box.classList.toggle( 'hidden', box.dataset.source !== form.elements.source.value ) );
		$$( 'input[name="source"]', form ).forEach( ( radio ) => radio.addEventListener( 'change', toggleSource ) );
		toggleSource();

		cancelButton.addEventListener( 'click', () => {
			cancel = true;
			cancelButton.disabled = true;
		} );

		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			if ( running ) {
				return;
			}

			const source = form.elements.source.value;
			const file = form.elements.file.files[ 0 ];
			const config = Object.assign( readReplace( form ), { source, preserve: form.elements.preserve.checked } );

			if ( 'upload' === source && ! file ) {
				notice( 'error', __( 'Choose a file to upload.', 'swapline' ) );
				return;
			}
			if ( 'upload' === source && file.size > data.maxUpload ) {
				notice( 'error', __( 'The file is larger than the maximum upload size. Use WP-CLI for this file.', 'swapline' ) );
				return;
			}
			if ( config.replace_enabled && ! config.search ) {
				notice( 'error', __( 'Enter something to search for.', 'swapline' ) );
				form.elements.search.focus();
				return;
			}
			if ( ! window.confirm( __( 'Importing replaces every table in the file. Continue?', 'swapline' ) ) ) {
				return;
			}

			running = true;
			cancel = false;
			runButton.disabled = true;
			cancelButton.disabled = false;
			cancelButton.classList.remove( 'hidden' );
			notice( '', '' );
			$( '#swapline-results' ).classList.add( 'hidden' );

			try {
				if ( 'upload' === source ) {
					ui.show( 0, __( 'Uploading…', 'swapline' ) );
					config.file = ( await upload( file, ( share ) => ui.show( share * 100, __( 'Uploading…', 'swapline' ) ) ) ).file;
				} else {
					config.file = form.elements.backup.value;
				}

				let progress = await post( 'import_start', { config: JSON.stringify( config ) } );
				const job = progress.id;
				while ( ! progress.done ) {
					if ( cancel && 'swap' !== progress.phase ) {
						await post( 'import_cancel', { job } );
						progress = Object.assign( progress, { done: true, error: __( 'The import was cancelled.', 'swapline' ), rejected: [] } );
						break;
					}
					ui.show( progress.percent, phaseLabel( progress.phase ) );
					progress = await post( 'import_step', { job } );
				}
				if ( progress.error ) {
					ui.hide();
				} else {
					ui.show( 100, __( 'Finished.', 'swapline' ) );
				}
				renderImport( progress, config.preserve );
			} catch ( e ) {
				ui.hide();
				notice( 'error', e.message );
			}

			running = false;
			runButton.disabled = false;
			cancelButton.classList.add( 'hidden' );
		} );

		window.addEventListener( 'beforeunload', ( event ) => {
			if ( running ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	}

	initBackup();
	initImport();
} )();
