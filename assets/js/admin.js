/**
 * Ticketoo wp-admin panel progressive enhancement (spec §6; ADR 0002/0004).
 *
 * MenuPage::render() prints a static shell — toolbar, table skeleton,
 * pagination nav and a hidden detail pane — and localizes
 * `ticketooAdmin = {restUrl, nonce, counterInterval, i18n}`. This file
 * fills that shell with `ticketoo/v1` REST data (always scope=all; the
 * routes reject non-agents server-side) and wires the panel behaviour:
 * status tabs, a 300 ms debounced search, the agent filter, pagination,
 * the in-page detail view (conversation, reply with attachments, status
 * buttons, assign select, download links) and the new-ticket counter
 * polled every ticketoo_counter_refresh_seconds (0 disables polling;
 * the first refresh always runs).
 *
 * House rules (mirrors assets/js/frontend.js):
 * - Vanilla ES5, no dependencies, no build step (ADR 0004).
 * - One `api()` helper signs every request with the localized
 *   X-WP-Nonce and same-origin cookies; failures surface as
 *   .ticketoo-notice blocks — never a silent no-op.
 * - Sequence numbers drop stale list/detail/counter answers so a slow
 *   response can never overwrite a newer one.
 * - Response data reaches the DOM only through text nodes; the sole
 *   exception is message HTML, which the server already ran through
 *   wp_kses_post() (same exception as frontend.js).
 * - Mutating controls lock themselves (busy flag + disabled) while a
 *   request is in flight, so a fast double click cannot fire twice.
 *
 * The agent roster (toolbar filter options, assign select options and
 * message author names) ships as <option>s in the shell — there is no
 * agents REST route (spec §5).
 *
 * No guest token ever appears here: the panel authenticates with the
 * wp_rest nonce and the logged-in agent's cookies.
 *
 * @package Ticketoo
 */

( function () {
	'use strict';

	var config = window.ticketooAdmin;

	if ( ! config || 'string' !== typeof config.restUrl || '' === config.restUrl ) {
		return;
	}

	var root = null;

	// Page size for the ticket table; the server default (20) when omitted.
	var PER_PAGE = 20;

	// Debounce for the search box before it hits REST.
	var SEARCH_DELAY = 300;

	// Message page size for the detail view (server caps per_page at 100).
	var DETAIL_MESSAGES = 100;

	// Shell hooks, bound in init() — the script may run before the markup
	// exists (it is enqueued in the footer today, but that is an enqueue
	// detail, not a contract).
	var rowsEl = null;
	var paginationEl = null;
	var detailEl = null;
	var counterEl = null;
	var searchEl = null;
	var agentFilterEl = null;
	var tableEl = null;

	var state = {
		status: '',
		q: '',
		page: 1,
		agent: '',
		openId: 0,
		openStatus: '',
		openItem: null,
		lastItems: null,
		listSeq: 0,
		detailSeq: 0,
		counterSeq: 0,
		searchTimer: null
	};

	/* ---------- small helpers ---------- */

	function text( key, fallback ) {
		var strings = config.i18n || {};

		return 'string' === typeof strings[ key ] && '' !== strings[ key ] ? strings[ key ] : fallback;
	}

	function statusLabel( slug ) {
		var labels = ( config.i18n && config.i18n.statuses ) || {};

		return 'string' === typeof labels[ slug ] && '' !== labels[ slug ] ? labels[ slug ] : slug;
	}

	function statusLabels() {
		return ( config.i18n && config.i18n.statuses ) || {};
	}

	function nonceHeader() {
		return 'string' === typeof config.nonce ? config.nonce : '';
	}

	function errorMessage( json ) {
		return json && 'string' === typeof json.message && '' !== json.message
			? json.message
			: text( 'error', 'The request could not be completed. Please try again.' );
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function appendText( node, value ) {
		node.appendChild( document.createTextNode( value ) );
	}

	function currentUid() {
		return window.userSettings && window.userSettings.uid ? parseInt( window.userSettings.uid, 10 ) || 0 : 0;
	}

	/**
	 * Rebuilds the href with this plugin's query args replaced (a null or
	 * empty value removes the arg), preserving everything else — copied
	 * from frontend.js so list/deep-link URLs behave identically.
	 */
	function withArgs( href, args ) {
		var hash = '';
		var hashAt = href.indexOf( '#' );

		if ( -1 !== hashAt ) {
			hash = href.slice( hashAt );
			href = href.slice( 0, hashAt );
		}

		var queryAt = href.indexOf( '?' );
		var base = -1 === queryAt ? href : href.slice( 0, queryAt );
		var pairs = [];

		if ( -1 !== queryAt ) {
			href.slice( queryAt + 1 ).split( '&' ).forEach( function ( pair ) {
				if ( '' === pair ) {
					return;
				}

				var key = pair.split( '=' )[ 0 ];

				try {
					key = decodeURIComponent( key );
				} catch ( ignore ) {
					// Keep the raw key when it is not valid percent-encoding.
				}

				if ( ! Object.prototype.hasOwnProperty.call( args, key ) ) {
					pairs.push( pair );
				}
			} );
		}

		Object.keys( args ).forEach( function ( key ) {
			var value = args[ key ];

			if ( null === value || 'undefined' === typeof value || '' === value ) {
				return;
			}

			pairs.push( encodeURIComponent( key ) + '=' + encodeURIComponent( value ) );
		} );

		return base + ( pairs.length ? '?' + pairs.join( '&' ) : '' ) + hash;
	}

	function queryArg( name ) {
		var search = window.location.search || '';

		if ( '?' === search.charAt( 0 ) ) {
			search = search.slice( 1 );
		}

		var pairs = search.split( '&' );

		for ( var i = 0; i < pairs.length; i++ ) {
			if ( '' === pairs[ i ] ) {
				continue;
			}

			var parts = pairs[ i ].split( '=' );
			var key = parts[ 0 ];

			try {
				key = decodeURIComponent( key.replace( /\+/g, ' ' ) );
			} catch ( ignore ) {
				// Keep the raw key when it is not valid percent-encoding.
			}

			if ( key !== name ) {
				continue;
			}

			var value = 'undefined' === typeof parts[ 1 ] ? '' : parts[ 1 ];

			try {
				value = decodeURIComponent( value.replace( /\+/g, ' ' ) );
			} catch ( ignore2 ) {
				// Keep the raw value when it is not valid percent-encoding.
			}

			return value;
		}

		return '';
	}

	/* ---------- REST ---------- */

	function apiUrl( path, params ) {
		var url = config.restUrl + path;
		var pairs = [];

		params = params || {};

		Object.keys( params ).forEach( function ( key ) {
			var value = params[ key ];

			if ( null === value || 'undefined' === typeof value || '' === value ) {
				return;
			}

			pairs.push( encodeURIComponent( key ) + '=' + encodeURIComponent( value ) );
		} );

		if ( ! pairs.length ) {
			return url;
		}

		// Plain permalinks carry ?rest_route=/... inside restUrl, so the
		// args must append with & — a second ? would glue itself onto the
		// route value and the server would answer 404.
		return url + ( -1 === url.indexOf( '?' ) ? '?' : '&' ) + pairs.join( '&' );
	}

	/**
	 * The single request helper: builds the URL, signs every call with the
	 * localized X-WP-Nonce and same-origin cookies, and always settles
	 * with `{ok, status, json}` (json is null when the body is not JSON),
	 * so callers have exactly one success shape and one error shape.
	 */
	function api( path, params, options ) {
		options = options || {};

		var headers = {};

		Object.keys( options.headers || {} ).forEach( function ( key ) {
			headers[ key ] = options.headers[ key ];
		} );

		headers[ 'X-WP-Nonce' ] = nonceHeader();

		var init = {
			method: options.method || 'GET',
			headers: headers,
			credentials: 'same-origin'
		};

		if ( 'undefined' !== typeof options.body ) {
			init.body = options.body;
		}

		var request;

		try {
			request = window.fetch( apiUrl( path, params ), init );
		} catch ( error ) {
			return Promise.reject( error );
		}

		return request.then( function ( response ) {
			return response
				.json()
				.catch( function () {
					return null;
				} )
				.then( function ( json ) {
					return { ok: response.ok, status: response.status, json: json };
				} );
		} );
	}

	/* ---------- notices ---------- */

	/**
	 * Inserts (and replaces) a .ticketoo-notice as a direct child of
	 * `container`, mirroring the front-end notice block. Notices scoped to
	 * the detail pane are direct children of the pane, so a list notice
	 * (a direct child of the root) never clears them and vice versa.
	 */
	function showNotice( container, anchor, type, message ) {
		if ( ! container || '' === message ) {
			return;
		}

		for ( var i = container.childNodes.length - 1; i >= 0; i-- ) {
			var node = container.childNodes[ i ];

			if ( 1 === node.nodeType && node.classList && node.classList.contains( 'ticketoo-notice' ) ) {
				container.removeChild( node );
			}
		}

		var notice = document.createElement( 'div' );

		notice.className = 'ticketoo-notice ticketoo-notice--' + ( 'error' === type ? 'error' : 'success' );
		notice.setAttribute( 'role', 'status' );
		appendText( notice, message );
		container.insertBefore( notice, anchor || null );
	}

	function showListNotice( type, message ) {
		showNotice( root, tableEl, type, message );
	}

	function showDetailNotice( type, message ) {
		var anchor = detailEl.querySelector( '.ticketoo-reply' ) || detailEl.firstChild;

		showNotice( detailEl, anchor, type, message );
	}

	/* ---------- ticket list ---------- */

	function loadList() {
		var sequence = ++state.listSeq;

		rowsEl.setAttribute( 'aria-busy', 'true' );

		api( '/tickets', {
			scope: 'all',
			status: state.status,
			q: state.q,
			page: state.page,
			per_page: PER_PAGE
		} )
			.then( function ( result ) {
				if ( sequence !== state.listSeq ) {
					return;
				}

				if ( ! result.ok || ! result.json || ! Array.isArray( result.json.items ) ) {
					listFailed();
					return;
				}

				renderList( result.json );
			} )
			.catch( function () {
				if ( sequence !== state.listSeq ) {
					return;
				}

				listFailed();
			} );
	}

	function listFailed() {
		rowsEl.removeAttribute( 'aria-busy' );

		if ( ! state.lastItems ) {
			renderMessageRow( text( 'error', 'The request could not be completed. Please try again.' ) );
			return;
		}

		showListNotice( 'error', text( 'error', 'The request could not be completed. Please try again.' ) );
	}

	function renderList( json ) {
		var total = parseInt( json.total_pages, 10 ) || 0;
		var page = parseInt( json.page, 10 ) || state.page;

		if ( total > 0 && page > total ) {
			// The requested page vanished (a filter narrowed the list):
			// step back to the last real page instead of an empty box.
			state.page = total;
			loadList();
			return;
		}

		rowsEl.removeAttribute( 'aria-busy' );
		state.page = page;
		state.lastItems = json.items;

		renderRows( json.items );
		renderPagination( json );
		backfillOpenItem();
		syncUrl();
	}

	/**
	 * Fills in the list-side data of the ticket that is open in the detail
	 * pane (customer name, current assignee) once the list carrying it
	 * arrives — the deep-link path can open a ticket before its row has
	 * been rendered.
	 */
	function backfillOpenItem() {
		if ( ! state.openId || state.openItem || ! state.lastItems ) {
			return;
		}

		var item = findItem( state.openId );

		if ( ! item ) {
			return;
		}

		state.openItem = item;

		var assignSelect = detailEl.querySelector( '[data-assign-agent]' );

		if ( assignSelect ) {
			selectAssignedAgent( assignSelect, item );
		}
	}

	function findItem( id ) {
		if ( ! state.lastItems ) {
			return null;
		}

		for ( var i = 0; i < state.lastItems.length; i++ ) {
			if ( String( state.lastItems[ i ].id ) === String( id ) ) {
				return state.lastItems[ i ];
			}
		}

		return null;
	}

	function agentId( item ) {
		return item && item.assigned_to && item.assigned_to.id ? String( item.assigned_to.id ) : '';
	}

	function customerLabel( item ) {
		if ( item && item.user ) {
			if ( 'string' === typeof item.user.name && '' !== item.user.name ) {
				return item.user.name;
			}

			if ( 'string' === typeof item.user.email && '' !== item.user.email ) {
				return item.user.email;
			}
		}

		return '\u2014';
	}

	function agentLabel( item ) {
		if ( item && item.assigned_to && 'string' === typeof item.assigned_to.name && '' !== item.assigned_to.name ) {
			return item.assigned_to.name;
		}

		return '\u2014';
	}

	function renderMessageRow( message ) {
		clear( rowsEl );

		var row = document.createElement( 'tr' );
		var cell = document.createElement( 'td' );

		cell.className = 'ticketoo-empty';
		cell.setAttribute( 'colspan', '6' );
		appendText( cell, message );
		row.appendChild( cell );
		rowsEl.appendChild( row );
	}

	/**
	 * Rebuilds the tbody from the last response, applying the agent
	 * filter. Every value reaches the DOM through text nodes, so response
	 * data can never inject markup.
	 */
	function renderRows( items ) {
		clear( rowsEl );

		var shown = 0;

		for ( var i = 0; i < items.length; i++ ) {
			var item = items[ i ];

			if ( state.agent && agentId( item ) !== state.agent ) {
				continue;
			}

			rowsEl.appendChild( rowNode( item ) );
			shown++;
		}

		if ( 0 === shown ) {
			renderMessageRow( text( 'empty', 'No tickets found.' ) );
		}
	}

	function rowNode( item ) {
		var status = 'string' === typeof item.status ? item.status : '';
		var row = document.createElement( 'tr' );

		row.setAttribute( 'data-ticket-id', String( item.id ) );

		var number = document.createElement( 'td' );

		number.className = 'ticketoo-col-number';
		appendText( number, String( item.id ) );
		row.appendChild( number );

		var subjectCell = document.createElement( 'td' );

		subjectCell.className = 'ticketoo-col-subject';

		var link = document.createElement( 'a' );

		link.setAttribute( 'href', withArgs( window.location.href, { ticketoo_ticket: item.id } ) );
		link.setAttribute( 'data-open-ticket', String( item.id ) );
		appendText( link, 'string' === typeof item.subject ? item.subject : '' );
		subjectCell.appendChild( link );
		row.appendChild( subjectCell );

		var customer = document.createElement( 'td' );

		customer.className = 'ticketoo-col-customer';
		appendText( customer, customerLabel( item ) );
		row.appendChild( customer );

		var agent = document.createElement( 'td' );

		agent.className = 'ticketoo-col-agent';
		appendText( agent, agentLabel( item ) );
		row.appendChild( agent );

		var statusCell = document.createElement( 'td' );

		statusCell.className = 'ticketoo-col-status';

		var badge = document.createElement( 'span' );

		badge.className = 'ticketoo-badge ticketoo-badge--' + status;
		appendText( badge, statusLabel( status ) );
		statusCell.appendChild( badge );
		row.appendChild( statusCell );

		var activity = document.createElement( 'td' );

		activity.className = 'ticketoo-col-activity';

		var at = 'string' === typeof item.last_activity_at ? item.last_activity_at : '';
		var time = document.createElement( 'time' );

		time.setAttribute( 'datetime', at );
		appendText( time, at );
		activity.appendChild( time );
		row.appendChild( activity );

		return row;
	}

	function renderPagination( json ) {
		clear( paginationEl );

		var total = parseInt( json.total_pages, 10 ) || 0;
		var page = parseInt( json.page, 10 ) || 1;

		if ( total < 2 ) {
			return;
		}

		if ( page > 1 ) {
			paginationEl.appendChild( pagerLink( page - 1, text( 'prev', 'Previous' ), false ) );
		}

		for ( var n = 1; n <= total; n++ ) {
			paginationEl.appendChild( pagerLink( n, String( n ), n === page ) );
		}

		if ( page < total ) {
			paginationEl.appendChild( pagerLink( page + 1, text( 'next', 'Next' ), false ) );
		}
	}

	function pagerLink( page, label, active ) {
		var link = document.createElement( 'a' );

		link.className = 'ticketoo-pagination__link' + ( active ? ' is-active' : '' );
		link.setAttribute(
			'href',
			withArgs( window.location.href, {
				ticketoo_status: state.status || null,
				ticketoo_page: page > 1 ? page : null,
				ticketoo_q: state.q || null
			} )
		);
		link.setAttribute( 'data-page', String( page ) );

		if ( active ) {
			link.setAttribute( 'aria-current', 'page' );
		}

		appendText( link, label );

		return link;
	}

	function markActiveFilter( status ) {
		var filters = root.querySelectorAll( '.ticketoo-filters [data-filter-status]' );

		for ( var i = 0; i < filters.length; i++ ) {
			if ( ( filters[ i ].getAttribute( 'data-filter-status' ) || '' ) === ( status || '' ) ) {
				filters[ i ].classList.add( 'is-active' );
			} else {
				filters[ i ].classList.remove( 'is-active' );
			}
		}
	}

	function handleStatusFilter( filter ) {
		var status = filter.getAttribute( 'data-filter-status' ) || '';

		state.status = status;
		state.page = 1;
		markActiveFilter( status );
		loadList();
	}

	function handlePage( pager ) {
		var page = parseInt( pager.getAttribute( 'data-page' ), 10 );

		if ( ! page || page < 1 || page === state.page ) {
			return;
		}

		state.page = page;
		loadList();
	}

	/* ---------- agent roster (shell <option>s) ---------- */

	/**
	 * The assignable agents as {value, label}, read from the toolbar's
	 * server-rendered filter select (its empty "All agents" option is
	 * skipped). The same roster builds the assign select and resolves
	 * message author names.
	 */
	function roster() {
		var options = [];
		var select = agentFilterEl.options;

		for ( var i = 0; i < select.length; i++ ) {
			if ( '' === select[ i ].value ) {
				continue;
			}

			options.push( {
				value: select[ i ].value,
				label: select[ i ].textContent || select[ i ].innerText || ''
			} );
		}

		return options;
	}

	function rosterNameById() {
		var names = {};
		var options = roster();

		for ( var i = 0; i < options.length; i++ ) {
			names[ options[ i ].value ] = options[ i ].label;
		}

		return names;
	}

	/* ---------- counter ---------- */

	/**
	 * Polls the open-ticket total (the "new-ticket counter") on the
	 * ticketoo_counter_refresh_seconds cadence. A failed poll keeps the
	 * last value: the counter is ambient, so it never shows an error.
	 */
	function refreshCounter() {
		var sequence = ++state.counterSeq;

		api( '/tickets', { scope: 'all', status: 'open', page: 1, per_page: 1 } )
			.then( function ( result ) {
				if ( sequence !== state.counterSeq ) {
					return;
				}

				if ( ! result.ok || ! result.json ) {
					return;
				}

				var total = parseInt( result.json.total, 10 ) || 0;

				clear( counterEl );
				appendText( counterEl, String( total ) + ' ' + statusLabel( 'open' ) );
			} )
			.catch( function () {
				// Keep the previous value; a counter is not worth a notice.
			} );
	}

	/* ---------- detail view ---------- */

	function openDetail( id ) {
		if ( ! id || id < 1 ) {
			return;
		}

		state.openId = id;
		state.openStatus = '';
		state.openItem = findItem( id );

		var sequence = ++state.detailSeq;

		detailEl.removeAttribute( 'hidden' );
		clear( detailEl );

		var loading = document.createElement( 'p' );

		loading.className = 'ticketoo-empty';
		appendText( loading, text( 'loading', 'Loading\u2026' ) );
		detailEl.appendChild( loading );
		syncUrl();

		api( '/tickets/' + encodeURIComponent( String( id ) ), { per_page: DETAIL_MESSAGES } )
			.then( function ( result ) {
				if ( sequence !== state.detailSeq ) {
					return;
				}

				if ( ! result.ok || ! result.json ) {
					detailFailed( sequence, result.json );
					return;
				}

				renderDetail( result.json, sequence );
			} )
			.catch( function () {
				if ( sequence !== state.detailSeq ) {
					return;
				}

				detailFailed( sequence, null );
			} );
	}

	function closeDetail() {
		// Bumping the sequence drops an in-flight read so it cannot
		// repopulate the pane the user just closed.
		state.detailSeq++;
		state.openId = 0;
		state.openStatus = '';
		state.openItem = null;

		clear( detailEl );
		detailEl.setAttribute( 'hidden', 'hidden' );
		syncUrl();
	}

	function detailFailed( sequence, json ) {
		if ( sequence !== state.detailSeq ) {
			return;
		}

		clear( detailEl );
		detailEl.appendChild( detailHeader( { subject: '', status: '' } ) );
		showNotice( detailEl, detailEl.firstChild ? detailEl.firstChild.nextSibling : null, 'error', errorMessage( json ) );
	}

	function detailHeader( ticket ) {
		var header = document.createElement( 'div' );

		header.className = 'ticketoo-detail__header';

		if ( 'string' === typeof ticket.subject && '' !== ticket.subject ) {
			var subject = document.createElement( 'h2' );

			subject.className = 'ticketoo-detail__subject';
			appendText( subject, ticket.subject );
			header.appendChild( subject );
		}

		if ( 'string' === typeof ticket.status && '' !== ticket.status ) {
			var badge = document.createElement( 'span' );

			badge.className = 'ticketoo-badge ticketoo-badge--' + ticket.status;
			appendText( badge, statusLabel( ticket.status ) );
			header.appendChild( badge );
		}

		var customer = state.openItem ? customerLabel( state.openItem ) : '';

		if ( '' !== customer && '\u2014' !== customer ) {
			var meta = document.createElement( 'span' );

			meta.className = 'ticketoo-detail__meta';
			appendText( meta, customer );
			header.appendChild( meta );
		}

		var close = document.createElement( 'button' );

		close.type = 'button';
		close.className = 'ticketoo-button ticketoo-button--secondary ticketoo-detail__close';
		close.setAttribute( 'data-close-detail', '1' );
		appendText( close, text( 'close', 'Close' ) );
		header.appendChild( close );

		return header;
	}

	/**
	 * Builds the whole pane from a GET /tickets/{id} payload: header,
	 * conversation, attachment downloads, reply box, assign select and
	 * the status buttons. Strings go in as text nodes; only message
	 * bodies use innerHTML, and only because the server sent them through
	 * wp_kses_post().
	 */
	function renderDetail( json, sequence ) {
		if ( sequence !== state.detailSeq ) {
			return;
		}

		clear( detailEl );

		state.openStatus = 'string' === typeof json.status ? json.status : '';
		detailEl.appendChild( detailHeader( json ) );

		var messages = document.createElement( 'ol' );

		messages.className = 'ticketoo-messages';

		var list = Array.isArray( json.messages ) ? json.messages : [];

		for ( var i = 0; i < list.length; i++ ) {
			messages.appendChild( messageNode( list[ i ], false ) );
		}

		detailEl.appendChild( messages );
		renderAttachments( Array.isArray( json.attachments ) ? json.attachments : [] );
		detailEl.appendChild( replyForm() );
		detailEl.appendChild( assignField() );
		detailEl.appendChild( statusActions() );
	}

	function renderAttachments( attachments ) {
		if ( ! attachments.length ) {
			return;
		}

		var ul = document.createElement( 'ul' );

		ul.className = 'ticketoo-message__attachments';

		for ( var i = 0; i < attachments.length; i++ ) {
			ul.appendChild( attachmentNode( attachments[ i ] ) );
		}

		var form = detailEl.querySelector( '.ticketoo-reply' );

		detailEl.insertBefore( ul, form || null );
	}

	function attachmentNode( attachment ) {
		var li = document.createElement( 'li' );
		var link = document.createElement( 'a' );

		// Cookie-authenticated REST GETs need the wp_rest nonce; plain
		// navigations cannot send the X-WP-Nonce header, so it travels as
		// _wpnonce (rest_cookie_check_errors() accepts either).
		var url = 'string' === typeof attachment.url ? attachment.url : '';

		link.setAttribute(
			'href',
			url + ( -1 === url.indexOf( '?' ) ? '?' : '&' ) + '_wpnonce=' + encodeURIComponent( nonceHeader() )
		);
		link.setAttribute( 'download', '' );
		appendText( link, 'string' === typeof attachment.name ? attachment.name : '' );
		li.appendChild( link );

		return li;
	}

	function resolveAuthor( message, forceYou ) {
		if ( forceYou ) {
			return text( 'you', 'You' );
		}

		var uid = currentUid();
		var userId = parseInt( message.user_id, 10 ) || 0;

		if ( uid && userId === uid ) {
			return text( 'you', 'You' );
		}

		var names = rosterNameById();

		if ( names[ String( userId ) ] ) {
			return names[ String( userId ) ];
		}

		var item = state.openItem;

		if ( item && item.user && ( parseInt( item.user.id, 10 ) || 0 ) === userId ) {
			return item.user.name || item.user.email || '';
		}

		return '';
	}

	function messageNode( message, forceYou ) {
		var isAgent = 1 === parseInt( message.is_agent, 10 );
		var li = document.createElement( 'li' );

		li.className = 'ticketoo-message ' + ( isAgent ? 'ticketoo-message--agent' : 'ticketoo-message--user' );

		var author = resolveAuthor( message, forceYou );

		if ( '' !== author ) {
			var authorEl = document.createElement( 'span' );

			authorEl.className = 'ticketoo-message__author';
			appendText( authorEl, author );
			li.appendChild( authorEl );
		}

		var at = 'string' === typeof message.created_at && '' !== message.created_at
			? message.created_at
			: mysqlStamp( new Date() );
		var time = document.createElement( 'time' );

		time.className = 'ticketoo-message__time';
		time.setAttribute( 'datetime', at );
		appendText( time, at );
		li.appendChild( time );

		var body = document.createElement( 'div' );

		body.className = 'ticketoo-message__body';
		// The REST payload carries content after wp_kses_post() — the same
		// sanitizing templates/conversation.php applies server-side.
		body.innerHTML = 'string' === typeof message.content ? message.content : '';
		li.appendChild( body );

		return li;
	}

	function mysqlStamp( date ) {
		function pad( number ) {
			return number < 10 ? '0' + number : String( number );
		}

		return (
			date.getFullYear() +
			'-' +
			pad( date.getMonth() + 1 ) +
			'-' +
			pad( date.getDate() ) +
			' ' +
			pad( date.getHours() ) +
			':' +
			pad( date.getMinutes() ) +
			':' +
			pad( date.getSeconds() )
		);
	}

	/* ---------- reply ---------- */

	function replyForm() {
		var form = document.createElement( 'form' );

		form.className = 'ticketoo-reply';
		form.setAttribute( 'enctype', 'multipart/form-data' );

		var contentField = document.createElement( 'p' );

		contentField.className = 'ticketoo-field';

		var contentLabel = document.createElement( 'label' );

		contentLabel.setAttribute( 'for', 'ticketoo-admin-reply-content' );
		appendText( contentLabel, text( 'your_reply', 'Your reply' ) );
		contentField.appendChild( contentLabel );

		var textarea = document.createElement( 'textarea' );

		textarea.id = 'ticketoo-admin-reply-content';
		textarea.setAttribute( 'rows', '4' );
		textarea.setAttribute( 'required', 'required' );
		contentField.appendChild( textarea );
		form.appendChild( contentField );

		var fileField = document.createElement( 'p' );

		fileField.className = 'ticketoo-field';

		var fileLabel = document.createElement( 'label' );

		fileLabel.setAttribute( 'for', 'ticketoo-admin-reply-files' );
		appendText( fileLabel, text( 'attach', 'Attach files' ) );
		fileField.appendChild( fileLabel );

		var fileInput = document.createElement( 'input' );

		fileInput.type = 'file';
		fileInput.id = 'ticketoo-admin-reply-files';
		fileInput.setAttribute( 'name', 'files[]' );
		fileInput.setAttribute( 'multiple', 'multiple' );
		fileField.appendChild( fileInput );
		form.appendChild( fileField );

		var actions = document.createElement( 'p' );

		actions.className = 'ticketoo-actions';

		var button = document.createElement( 'button' );

		button.type = 'submit';
		button.className = 'ticketoo-button';
		appendText( button, text( 'reply', 'Reply' ) );
		actions.appendChild( button );
		form.appendChild( actions );

		bindReplyForm( form, textarea, fileInput, button );

		return form;
	}

	/**
	 * Sends one reply for the open ticket. The form is locked for the
	 * whole flight (busy flag + disabled button), so a fast double click
	 * or Enter in the textarea cannot fire a second POST — the same guard
	 * frontend.js applies to its reply form.
	 */
	function bindReplyForm( form, textarea, fileInput, button ) {
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( true === form.ticketooBusy ) {
				return;
			}

			var id = state.openId;
			var sequence = state.detailSeq;

			if ( ! id ) {
				return;
			}

			var data = new FormData();

			data.append( 'content', textarea.value );

			if ( fileInput && fileInput.files ) {
				for ( var i = 0; i < fileInput.files.length; i++ ) {
					data.append( 'files[]', fileInput.files[ i ] );
				}
			}

			form.ticketooBusy = true;
			button.disabled = true;

			function release() {
				form.ticketooBusy = false;
				button.disabled = false;
			}

			api( '/tickets/' + encodeURIComponent( String( id ) ) + '/messages', null, {
				method: 'POST',
				body: data
			} )
				.then( function ( result ) {
					release();

					if ( sequence !== state.detailSeq ) {
						// The pane was closed or switched while the reply
						// was in flight: the message is stored, but there
						// is no pane to decorate.
						loadList();
						return;
					}

					if ( ! result.ok || ! result.json ) {
						showDetailNotice( 'error', errorMessage( result.json ) );
						return;
					}

					appendMessage( result.json );
					textarea.value = '';

					if ( fileInput ) {
						fileInput.value = '';
					}

					showDetailNotice( 'success', text( 'replied', 'Your reply has been sent.' ) );
					loadList();
				} )
				.catch( function () {
					release();

					if ( sequence === state.detailSeq ) {
						showDetailNotice( 'error', text( 'error', 'The request could not be completed. Please try again.' ) );
					}
				} );
		} );
	}

	function appendMessage( message ) {
		var messages = detailEl.querySelector( '.ticketoo-messages' );

		if ( ! messages ) {
			return;
		}

		messages.appendChild( messageNode( message, true ) );
		messages.scrollTop = messages.scrollHeight;

		if ( Array.isArray( message.attachments ) && message.attachments.length ) {
			var ul = detailEl.querySelector( '.ticketoo-message__attachments' );

			if ( ! ul ) {
				renderAttachments( message.attachments );
				return;
			}

			for ( var i = 0; i < message.attachments.length; i++ ) {
				ul.appendChild( attachmentNode( message.attachments[ i ] ) );
			}
		}
	}

	/* ---------- assign ---------- */

	function assignField() {
		var wrap = document.createElement( 'div' );

		wrap.className = 'ticketoo-actions';

		var label = document.createElement( 'label' );

		label.setAttribute( 'for', 'ticketoo-admin-assign' );
		appendText( label, text( 'assign', 'Assign' ) );
		wrap.appendChild( label );

		var select = document.createElement( 'select' );

		select.id = 'ticketoo-admin-assign';
		select.className = 'ticketoo-agent-filter';
		select.setAttribute( 'data-assign-agent', '' );

		var none = document.createElement( 'option' );

		none.value = '0';
		appendText( none, text( 'unassigned', 'Unassigned' ) );
		select.appendChild( none );

		var options = roster();

		for ( var i = 0; i < options.length; i++ ) {
			var option = document.createElement( 'option' );

			option.value = options[ i ].value;
			appendText( option, options[ i ].label );
			select.appendChild( option );
		}

		selectAssignedAgent( select, state.openItem );
		bindAssignSelect( select );
		wrap.appendChild( select );

		return wrap;
	}

	function selectAssignedAgent( select, item ) {
		var assigned = item && item.assigned_to ? item.assigned_to : null;
		var id = assigned && assigned.id ? String( assigned.id ) : '';

		if ( '' === id ) {
			select.value = '0';
			return;
		}

		var found = false;

		for ( var i = 0; i < select.options.length; i++ ) {
			if ( select.options[ i ].value === id ) {
				found = true;
				break;
			}
		}

		// An assignee missing from the roster (user removed from the
		// roles) still gets their option, so the select never lies about
		// the current state.
		if ( ! found ) {
			var option = document.createElement( 'option' );

			option.value = id;
			appendText( option, assigned.name || id );
			select.appendChild( option );
		}

		select.value = id;
	}

	/**
	 * POSTs the assignee on every change of the select, locking it while
	 * the request runs and reverting it when the server refuses.
	 */
	function bindAssignSelect( select ) {
		var previous = select.value;

		select.addEventListener( 'change', function () {
			if ( true === select.disabled ) {
				return;
			}

			var id = state.openId;
			var sequence = state.detailSeq;
			var userId = parseInt( select.value, 10 ) || 0;

			if ( ! id ) {
				return;
			}

			select.disabled = true;

			function release() {
				select.disabled = false;
			}

			api( '/tickets/' + encodeURIComponent( String( id ) ) + '/assign', null, {
				method: 'POST',
				body: JSON.stringify( { user_id: userId } ),
				headers: { 'Content-Type': 'application/json' }
			} )
				.then( function ( result ) {
					release();

					if ( sequence !== state.detailSeq ) {
						loadList();
						return;
					}

					if ( ! result.ok || ! result.json ) {
						select.value = previous;
						showDetailNotice( 'error', errorMessage( result.json ) );
						return;
					}

					previous = select.value;
					applyAssigned( id, result.json.assigned_to );
					showDetailNotice( 'success', text( 'assigned', 'The ticket has been assigned.' ) );
					loadList();
				} )
				.catch( function () {
					release();

					if ( sequence === state.detailSeq ) {
						select.value = previous;
						showDetailNotice( 'error', text( 'error', 'The request could not be completed. Please try again.' ) );
					}
				} );
		} );
	}

	/**
	 * Applies the assign answer to the list-side copy of the open ticket.
	 * POST /tickets/{id}/assign replies with the raw assignee id (int) or
	 * null — not the list payload's {id, name} object — so the display
	 * name is resolved from the shell roster here.
	 */
	function applyAssigned( id, assignedTo ) {
		if ( ! state.openItem || String( state.openItem.id ) !== String( id ) ) {
			return;
		}

		var assignedId = parseInt( assignedTo, 10 ) || 0;

		if ( ! assignedId ) {
			state.openItem.assigned_to = null;
			return;
		}

		var names = rosterNameById();

		state.openItem.assigned_to = {
			id: assignedId,
			name: names[ String( assignedId ) ] || ''
		};
	}

	/* ---------- status ---------- */

	function statusActions() {
		var wrap = document.createElement( 'div' );

		wrap.className = 'ticketoo-status-actions';

		var labels = statusLabels();
		var slugs = Object.keys( labels );

		for ( var i = 0; i < slugs.length; i++ ) {
			var slug = slugs[ i ];
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'ticketoo-button';
			button.setAttribute( 'data-set-status', slug );
			button.disabled = slug === state.openStatus;
			appendText( button, statusLabel( slug ) );
			wrap.appendChild( button );
		}

		return wrap;
	}

	function setStatusButtonsBusy( busy ) {
		var buttons = detailEl.querySelectorAll( '[data-set-status]' );

		for ( var i = 0; i < buttons.length; i++ ) {
			buttons[ i ].disabled = busy || buttons[ i ].getAttribute( 'data-set-status' ) === state.openStatus;
		}
	}

	/**
	 * POSTs a new status for the open ticket. Every status button is
	 * locked while the request runs (one click = one POST), the badge and
	 * the button states follow the server's answer, and the list behind
	 * the pane is re-fetched so the row reflects the change too.
	 */
	function handleStatusChange( button ) {
		var slug = button.getAttribute( 'data-set-status' );

		if ( ! slug || ! state.openId ) {
			return;
		}

		var id = state.openId;
		var sequence = state.detailSeq;

		setStatusButtonsBusy( true );

		api( '/tickets/' + encodeURIComponent( String( id ) ) + '/status', null, {
			method: 'POST',
			body: JSON.stringify( { status: slug } ),
			headers: { 'Content-Type': 'application/json' }
		} )
			.then( function ( result ) {
				if ( sequence !== state.detailSeq ) {
					loadList();
					return;
				}

				setStatusButtonsBusy( false );

				if ( ! result.ok || ! result.json || 'string' !== typeof result.json.status ) {
					showDetailNotice( 'error', errorMessage( result.json ) );
					return;
				}

				applyStatus( result.json.status );
				showDetailNotice( 'success', text( 'status_changed', 'The ticket status has been updated.' ) );
				loadList();
			} )
			.catch( function () {
				if ( sequence === state.detailSeq ) {
					setStatusButtonsBusy( false );
					showDetailNotice( 'error', text( 'error', 'The request could not be completed. Please try again.' ) );
				}
			} );
	}

	function applyStatus( status ) {
		state.openStatus = status;

		var header = detailEl.querySelector( '.ticketoo-detail__header' );
		var badge = header ? header.querySelector( '.ticketoo-badge' ) : null;

		if ( badge ) {
			badge.className = 'ticketoo-badge ticketoo-badge--' + status;

			clear( badge );
			appendText( badge, statusLabel( status ) );
		}

		setStatusButtonsBusy( false );
	}

	/* ---------- URL state ---------- */

	/**
	 * Mirrors the visible panel state into the address bar (status, page,
	 * search and the open ticket), so reloads and shared links land on
	 * the same view. replaceState only: the back button leaves the admin.
	 */
	function syncUrl() {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}

		var url = withArgs( window.location.href, {
			ticketoo_status: state.status || null,
			ticketoo_page: state.page > 1 ? state.page : null,
			ticketoo_q: state.q || null,
			ticketoo_ticket: state.openId ? state.openId : null
		} );

		if ( url !== window.location.href ) {
			window.history.replaceState( null, '', url );
		}
	}

	function seedFromUrl() {
		var wanted = queryArg( 'ticketoo_status' );
		var filters = root.querySelectorAll( '.ticketoo-filters [data-filter-status]' );
		var known = '';

		for ( var i = 0; i < filters.length; i++ ) {
			if ( ( filters[ i ].getAttribute( 'data-filter-status' ) || '' ) === wanted ) {
				known = wanted;
				break;
			}
		}

		state.status = known;
		markActiveFilter( known );

		var page = parseInt( queryArg( 'ticketoo_page' ), 10 );

		state.page = page && page > 0 ? page : 1;

		state.q = queryArg( 'ticketoo_q' );
		searchEl.value = state.q;
	}

	/* ---------- delegated listeners ---------- */

	function onClick( event ) {
		if (
			event.defaultPrevented ||
			event.button ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}

		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		var filter = target.closest( '[data-filter-status]' );

		if ( filter ) {
			event.preventDefault();
			handleStatusFilter( filter );
			return;
		}

		var pager = target.closest( '[data-page]' );

		if ( pager ) {
			event.preventDefault();
			handlePage( pager );
			return;
		}

		var opener = target.closest( '[data-open-ticket]' );

		if ( opener ) {
			event.preventDefault();
			openDetail( parseInt( opener.getAttribute( 'data-open-ticket' ), 10 ) );
			return;
		}

		var row = target.closest( 'tr[data-ticket-id]' );

		if ( row ) {
			openDetail( parseInt( row.getAttribute( 'data-ticket-id' ), 10 ) );
			return;
		}

		var closer = target.closest( '[data-close-detail]' );

		if ( closer ) {
			event.preventDefault();
			closeDetail();
			return;
		}

		var statusButton = target.closest( '[data-set-status]' );

		if ( statusButton ) {
			event.preventDefault();
			handleStatusChange( statusButton );
		}
	}

	/* ---------- init ---------- */

	function init() {
		root = document.getElementById( 'ticketoo-app' );

		if ( ! root ) {
			return;
		}

		rowsEl = root.querySelector( '[data-rows]' );
		paginationEl = root.querySelector( '[data-pagination]' );
		detailEl = root.querySelector( '[data-detail]' );
		counterEl = root.querySelector( '[data-counter]' );
		searchEl = root.querySelector( '[data-search]' );
		agentFilterEl = root.querySelector( '[data-filter-agent]' );
		tableEl = root.querySelector( '.ticketoo-table' );

		if ( ! rowsEl || ! paginationEl || ! detailEl || ! counterEl || ! searchEl || ! agentFilterEl || ! tableEl ) {
			return;
		}

		root.addEventListener( 'click', onClick, false );

		searchEl.addEventListener( 'input', function () {
			if ( state.searchTimer ) {
				window.clearTimeout( state.searchTimer );
			}

			state.searchTimer = window.setTimeout( function () {
				state.searchTimer = null;

				var value = searchEl.value || '';

				if ( value === state.q ) {
					return;
				}

				state.q = value;
				state.page = 1;
				loadList();
			}, SEARCH_DELAY );
		} );

		// The agent filter is client-side: the list route has no agent
		// argument, so the loaded page is filtered in place.
		agentFilterEl.addEventListener( 'change', function () {
			state.agent = agentFilterEl.value || '';

			if ( state.lastItems ) {
				renderRows( state.lastItems );
			}
		} );

		seedFromUrl();
		loadList();

		var interval = parseInt( config.counterInterval, 10 ) || 0;

		refreshCounter();

		if ( interval > 0 ) {
			window.setInterval( refreshCounter, interval * 1000 );
		}

		var deepLink = parseInt( queryArg( 'ticketoo_ticket' ), 10 );

		if ( deepLink > 0 ) {
			openDetail( deepLink );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init, false );
	} else {
		init();
	}
} )();
