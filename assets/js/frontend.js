/**
 * Ticketoo front-end progressive enhancement (ADR 0003).
 *
 * Every shortcode view renders complete HTML on the server, so filters,
 * pagination and all three forms work without JavaScript (plain links and
 * classic form posts). This file upgrades the interactive parts — status
 * filters, pagination, ticket creation, replies and closing — to REST calls
 * authenticated with the localized `X-WP-Nonce`.
 *
 * Degradation rules:
 * - A link action that never reaches the server falls back to navigating to
 *   the link's own href, exactly as without JavaScript.
 * - A form action that never reaches the server is replayed as its native
 *   POST (form.submit() bypasses the submit event, so nothing loops).
 * - Once the server has answered, the action is never replayed; failures are
 *   shown as `.ticketoo-notice` blocks using ticketooFrontend.i18n strings.
 * - While a form request is in flight the form is locked (submit button
 *   disabled plus a busy flag); re-submits are ignored so create/reply
 *   cannot fire a second REST POST from a fast double click or Enter.
 *
 * The payload contract is `window.ticketooFrontend = {restUrl, nonce, i18n}`
 * (see TicketooShortcode::enqueue_assets()). No guest token is ever stored
 * here — the reply form reads the token its template already renders.
 *
 * @package Ticketoo
 */

( function () {
	'use strict';

	var config = window.ticketooFrontend;

	if ( ! config || 'string' !== typeof config.restUrl || '' === config.restUrl ) {
		return;
	}

	// Mirrors TicketooShortcode::LIST_PER_PAGE so pagination stays in sync.
	var PER_PAGE = 20;

	// Sequence number of the newest list request per shortcode root; a stale
	// answer is dropped without disturbing a sibling root's request.
	function nextSequence( root ) {
		if ( ! root.ticketooSequence ) {
			root.ticketooSequence = 0;
		}

		return ++root.ticketooSequence;
	}

	function text( key, fallback ) {
		var strings = config.i18n || {};

		return 'string' === typeof strings[ key ] && '' !== strings[ key ] ? strings[ key ] : fallback;
	}

	function statusLabel( slug ) {
		var labels = ( config.i18n && config.i18n.statuses ) || {};

		return 'string' === typeof labels[ slug ] && '' !== labels[ slug ] ? labels[ slug ] : slug;
	}

	function closest( node, selector ) {
		if ( ! node || 1 !== node.nodeType || ! node.closest ) {
			return null;
		}

		return node.closest( selector );
	}

	function fieldValue( form, name ) {
		var field = form.querySelector( '[name="' + name + '"]' );

		return field ? field.value : '';
	}

	/**
	 * Rebuilds href with this plugin's query args replaced (a null or empty
	 * value removes the arg), preserving everything else in the URL.
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

	function nonceHeader() {
		return 'string' === typeof config.nonce ? config.nonce : '';
	}

	function jsonOptions( payload ) {
		return {
			method: 'POST',
			headers: {
				'X-WP-Nonce': nonceHeader(),
				'Content-Type': 'application/json'
			},
			credentials: 'same-origin',
			body: JSON.stringify( payload )
		};
	}

	function ticketsUrl( params ) {
		var pairs = [];

		Object.keys( params ).forEach( function ( key ) {
			var value = params[ key ];

			if ( null === value || 'undefined' === typeof value || '' === value ) {
				return;
			}

			pairs.push( encodeURIComponent( key ) + '=' + encodeURIComponent( value ) );
		} );

		var url = config.restUrl + '/tickets';

		if ( ! pairs.length ) {
			return url;
		}

		// Plain permalinks carry ?rest_route=/... inside restUrl, so the
		// args must append with & — a second ? would glue itself onto the
		// route value and the server would answer 404.
		return url + ( -1 === url.indexOf( '?' ) ? '?' : '&' ) + pairs.join( '&' );
	}

	function submitButton( form ) {
		return form.querySelector( 'button[type="submit"], input[type="submit"], button:not([type])' );
	}

	function nativeSubmit( form ) {
		window.HTMLFormElement.prototype.submit.call( form );
	}

	function isBusy( form ) {
		return true === form.ticketooBusy;
	}

	/**
	 * Marks a form as in flight (or idle again). The flag stops a second
	 * submit — a fast double click or Enter in a text field — from firing a
	 * second REST POST, and the disabled button gives the same visual
	 * feedback as a native submission while the request runs.
	 */
	function setBusy( form, busy, button ) {
		form.ticketooBusy = busy;

		if ( button ) {
			button.disabled = busy;
		}
	}

	/**
	 * Inserts (and replaces) a .ticketoo-notice next to the given element,
	 * mirroring the block TicketooShortcode::notice_html() prints server-side.
	 */
	function showNotice( anchor, type, message, linkUrl ) {
		if ( ! anchor || ! anchor.parentNode || '' === message ) {
			return;
		}

		var container = anchor.parentNode;
		var stale = container.querySelectorAll( '.ticketoo-notice' );

		for ( var i = 0; i < stale.length; i++ ) {
			container.removeChild( stale[ i ] );
		}

		var notice = document.createElement( 'div' );
		notice.className = 'ticketoo-notice ticketoo-notice--' + ( 'error' === type ? 'error' : 'success' );
		notice.setAttribute( 'role', 'status' );
		notice.appendChild( document.createTextNode( message ) );

		if ( linkUrl ) {
			var link = document.createElement( 'a' );

			link.setAttribute( 'href', linkUrl );
			link.appendChild( document.createTextNode( text( 'view', 'View ticket' ) ) );
			notice.appendChild( document.createTextNode( ' ' ) );
			notice.appendChild( link );
		}

		container.insertBefore( notice, anchor );
	}

	function showError( anchor, json ) {
		var message =
			json && 'string' === typeof json.message && '' !== json.message
				? json.message
				: text( 'error', 'Your submission could not be saved. Please try again.' );

		showNotice( anchor, 'error', message );
	}

	/**
	 * Re-renders the page through the classic post/redirect/get cycle when
	 * our own DOM update cannot run (a theme overrode the template).
	 */
	function reloadWithNotice( code ) {
		window.location.href = withArgs( window.location.href, { ticketoo_notice: code } );
	}

	/**
	 * Sends one mutating REST request for a form. `anchor` positions error
	 * notices, `noticeCode` names the ticketoo_notice used if the DOM update
	 * itself fails after a successful response.
	 *
	 * The form is locked for the whole flight; a re-submit while it is busy
	 * is ignored (create/reply would otherwise duplicate server-side) and
	 * the native fallback can fire only once.
	 */
	function mutate( url, options, form, anchor, noticeCode, onSuccess ) {
		if ( isBusy( form ) ) {
			return;
		}

		var button = submitButton( form );
		var answered = false;
		var nativeFired = false;

		setBusy( form, true, button );

		function release() {
			if ( ! isBusy( form ) ) {
				return;
			}

			setBusy( form, false, button );
		}

		function fallbackToNative() {
			if ( nativeFired ) {
				return;
			}

			nativeFired = true;
			// Re-enable before submitting: form.submit() bypasses the submit
			// event, so this cannot loop, and a page restored from the
			// back/forward cache must not come back with the form locked.
			release();
			nativeSubmit( form );
		}

		var request;

		try {
			request = window.fetch( url, options );
		} catch ( error ) {
			fallbackToNative();
			return;
		}

		request
			.then( function ( response ) {
				answered = true;

				return response.json()
					.catch( function () {
						return null;
					} )
					.then( function ( json ) {
						release();

						if ( ! response.ok ) {
							showError( anchor, json );
							return;
						}

						try {
							onSuccess( json );
						} catch ( error ) {
							reloadWithNotice( noticeCode );
						}
					} );
			}, function () {
				// No response arrived: replay the action as a classic POST.
				fallbackToNative();
			} )
			.catch( function () {
				release();

				if ( answered ) {
					// The server answered, so replaying could duplicate the
					// action; fall back to a server-rendered notice instead.
					reloadWithNotice( noticeCode );
				} else {
					fallbackToNative();
				}
			} );
	}

	/* ---------- ticket list (filters, pagination, refresh) ---------- */

	function currentStatus( root ) {
		var active = root.querySelector( '.ticketoo-filters [data-filter-status].is-active' );

		return active ? active.getAttribute( 'data-filter-status' ) || '' : '';
	}

	function currentPage( root ) {
		var active = root.querySelector( '.ticketoo-pagination [data-page].is-active' );
		var page = active ? parseInt( active.getAttribute( 'data-page' ), 10 ) : 1;

		return page && page > 0 ? page : 1;
	}

	/**
	 * The clean page URL (no status/page args): the "All" filter's href in
	 * the list view, best effort otherwise.
	 */
	function baseUrl( root ) {
		var all = root.querySelector( '.ticketoo-filters [data-filter-status=""]' );

		if ( all && all.getAttribute( 'href' ) ) {
			return all.getAttribute( 'href' );
		}

		var any = root.querySelector( '.ticketoo-filters [data-filter-status]' );

		if ( any && any.getAttribute( 'href' ) ) {
			return withArgs( any.getAttribute( 'href' ), { ticketoo_status: null, ticketoo_page: null } );
		}

		return window.location.href;
	}

	function loadList( root, status, page, browserUrl ) {
		var list = root.querySelector( '.ticketoo-list' );

		if ( ! list ) {
			window.location.href = browserUrl;
			return;
		}

		var sequence = nextSequence( root );

		function stale() {
			return sequence !== root.ticketooSequence;
		}

		function done() {
			if ( ! stale() && list.getAttribute( 'aria-busy' ) ) {
				list.removeAttribute( 'aria-busy' );
			}
		}

		function navigate() {
			if ( stale() ) {
				return;
			}

			done();
			window.location.href = browserUrl;
		}

		list.setAttribute( 'aria-busy', 'true' );

		var request;

		try {
			request = window.fetch( ticketsUrl( { status: status, page: page, per_page: PER_PAGE } ), {
				headers: { 'X-WP-Nonce': nonceHeader() },
				credentials: 'same-origin'
			} );
		} catch ( error ) {
			navigate();
			return;
		}

		request
			.then( function ( response ) {
				return response.json()
					.catch( function () {
						return null;
					} )
					.then( function ( json ) {
						if ( stale() ) {
							return;
						}

						done();

						if ( ! response.ok || ! json || ! Array.isArray( json.items ) ) {
							navigate();
							return;
						}

						try {
							var total = parseInt( json.total_pages, 10 ) || 0;
							var current = parseInt( json.page, 10 ) || page;

							if ( total > 0 && current > total ) {
								// The requested page vanished (a filter
								// narrowed the list): step back to the last
								// real page instead of showing an empty box.
								loadList( root, status, total, withArgs( browserUrl, { ticketoo_page: total } ) );
								return;
							}

							renderList( root, list, json.items );
							renderPagination( root, list, status, json );
							markActiveFilter( root, status );

							if ( window.history && window.history.replaceState ) {
								window.history.replaceState( null, '', browserUrl );
							}
						} catch ( error ) {
							navigate();
						}
					} );
			}, navigate )
			.catch( navigate );
	}

	function renderList( root, list, items ) {
		while ( list.firstChild ) {
			list.removeChild( list.firstChild );
		}

		if ( ! items.length ) {
			var empty = document.createElement( 'p' );

			empty.className = 'ticketoo-empty';
			empty.appendChild( document.createTextNode( text( 'empty', 'You have not opened a ticket yet.' ) ) );
			list.appendChild( empty );
			return;
		}

		var ul = document.createElement( 'ul' );

		ul.className = 'ticketoo-items';

		for ( var i = 0; i < items.length; i++ ) {
			ul.appendChild( listItem( root, items[ i ] ) );
		}

		list.appendChild( ul );
	}

	/**
	 * Builds one row identical to templates/list.php, with every string set
	 * through text nodes so response data never reaches innerHTML.
	 */
	function listItem( root, item ) {
		var status = 'string' === typeof item.status ? item.status : '';
		var li = document.createElement( 'li' );

		li.className = 'ticketoo-item ticketoo-item--' + status;

		var link = document.createElement( 'a' );

		link.className = 'ticketoo-item__link';
		link.setAttribute(
			'href',
			withArgs( baseUrl( root ), {
				ticketoo_status: null,
				ticketoo_page: null,
				ticketoo_ticket: item.id
			} )
		);

		var subject = document.createElement( 'span' );

		subject.className = 'ticketoo-item__subject';
		subject.appendChild( document.createTextNode( item.subject || '' ) );
		link.appendChild( subject );
		li.appendChild( link );

		var badge = document.createElement( 'span' );

		badge.className = 'ticketoo-badge ticketoo-badge--' + status;
		badge.appendChild( document.createTextNode( statusLabel( status ) ) );
		li.appendChild( badge );

		var at = 'string' === typeof item.last_activity_at ? item.last_activity_at : '';
		var time = document.createElement( 'time' );

		time.className = 'ticketoo-item__time';
		time.setAttribute( 'datetime', at );
		time.appendChild( document.createTextNode( at ) );
		li.appendChild( time );

		return li;
	}

	/**
	 * Rebuilds (or removes) the .ticketoo-pagination nav after the list
	 * changed, mirroring templates/list.php including its is-active state.
	 */
	function renderPagination( root, list, status, json ) {
		var total = parseInt( json.total_pages, 10 ) || 0;
		var page = parseInt( json.page, 10 ) || 1;
		var nav = root.querySelector( '.ticketoo-pagination' );

		if ( total < 2 ) {
			if ( nav && nav.parentNode ) {
				nav.parentNode.removeChild( nav );
			}

			return;
		}

		if ( ! nav ) {
			nav = document.createElement( 'nav' );
			nav.className = 'ticketoo-pagination';
			list.parentNode.insertBefore( nav, list.nextSibling );
		}

		while ( nav.firstChild ) {
			nav.removeChild( nav.firstChild );
		}

		var base = baseUrl( root );

		if ( page > 1 ) {
			nav.appendChild( pagerLink( base, status, page - 1, text( 'prev', 'Previous' ), false ) );
		}

		for ( var n = 1; n <= total; n++ ) {
			nav.appendChild( pagerLink( base, status, n, String( n ), n === page ) );
		}

		if ( page < total ) {
			nav.appendChild( pagerLink( base, status, page + 1, text( 'next', 'Next' ), false ) );
		}
	}

	function pagerLink( base, status, page, label, active ) {
		var link = document.createElement( 'a' );

		link.className = 'ticketoo-pagination__link' + ( active ? ' is-active' : '' );
		link.setAttribute( 'href', withArgs( base, { ticketoo_status: status || null, ticketoo_page: page } ) );
		link.setAttribute( 'data-page', String( page ) );
		link.appendChild( document.createTextNode( label ) );

		return link;
	}

	function markActiveFilter( root, status ) {
		var links = root.querySelectorAll( '.ticketoo-filters [data-filter-status]' );

		for ( var i = 0; i < links.length; i++ ) {
			if ( ( links[ i ].getAttribute( 'data-filter-status' ) || '' ) === ( status || '' ) ) {
				links[ i ].classList.add( 'is-active' );
			} else {
				links[ i ].classList.remove( 'is-active' );
			}
		}
	}

	/**
	 * Handles a click on a [data-filter-status] or [data-page] link: fetch
	 * the list over REST, or navigate to the link's href when anything goes
	 * wrong (identical to the no-JS behaviour).
	 */
	function handleListLink( event, link ) {
		event.preventDefault();

		var root = closest( link, '.ticketoo' );

		if ( ! root ) {
			window.location.href = link.href;
			return;
		}

		var isFilter = link.hasAttribute( 'data-filter-status' );
		var status = isFilter ? link.getAttribute( 'data-filter-status' ) || '' : currentStatus( root );
		var page = 1;

		if ( ! isFilter ) {
			page = parseInt( link.getAttribute( 'data-page' ), 10 );

			if ( ! page || page < 1 ) {
				page = 1;
			}
		}

		loadList( root, status, page, link.href );
	}

	/**
	 * The .ticketoo list root nearest to `root` in document order: the last
	 * list root preceding it, or — when none precedes — the first that
	 * follows. Each shortcode view renders its own .ticketoo root, so the
	 * create form finds its sibling list here instead of inside itself.
	 */
	function nearestListRoot( root ) {
		var roots = document.querySelectorAll( '.ticketoo.ticketoo-view-list' );
		var preceding = null;

		for ( var i = 0; i < roots.length; i++ ) {
			if ( root && root.compareDocumentPosition ) {
				// Node.DOCUMENT_POSITION_PRECEDING (2): roots[i] sits
				// before root in document order.
				if ( root.compareDocumentPosition( roots[ i ] ) & 2 ) {
					preceding = roots[ i ];
					continue;
				}
			}

			return preceding || roots[ i ];
		}

		return preceding;
	}

	/**
	 * Re-fetches the list in its current filter/page state, used after a
	 * ticket was created on a page that shows list + form together. The
	 * lookup stays inside the shortcode root that owns the trigger and only
	 * then falls back to the nearest list root, so a page carrying several
	 * lists refreshes the one belonging to this form — never the first
	 * .ticketoo-list found document-wide.
	 */
	function refreshList( root ) {
		var listRoot = root;

		if ( ! root || ! root.querySelector || ! root.querySelector( '.ticketoo-list' ) ) {
			listRoot = nearestListRoot( root );
		}

		if ( ! listRoot || ! listRoot.querySelector( '.ticketoo-list' ) ) {
			return;
		}

		var status = currentStatus( listRoot );
		var page = currentPage( listRoot );
		var browserUrl = withArgs( window.location.href, {
			ticketoo_status: status || null,
			ticketoo_page: page > 1 ? page : null
		} );

		loadList( listRoot, status, page, browserUrl );
	}

	/* ---------- new ticket form ---------- */

	function handleCreate( form ) {
		var root = closest( form, '.ticketoo' ) || form;
		var payload = {
			subject: fieldValue( form, 'ticketoo_subject' ),
			content: fieldValue( form, 'ticketoo_content' )
		};
		var email = form.querySelector( '[name="ticketoo_email"]' );

		if ( email && '' !== email.value ) {
			payload.email = email.value;
		}

		mutate(
			config.restUrl + '/tickets',
			jsonOptions( payload ),
			form,
			root,
			'created',
			function ( json ) {
				onCreated( form, root, json );
			}
		);
	}

	function onCreated( form, root, json ) {
		form.reset();

		var url = json && 'string' === typeof json.url ? json.url : '';
		var loggedIn = document.body && document.body.classList.contains( 'logged-in' );

		// Guests reach their new ticket through the emailed token link
		// (spec §5); the REST url carries no token, so it is only offered
		// to logged-in owners.
		showNotice(
			root,
			'success',
			text( 'created', 'Your ticket has been created.' ),
			loggedIn && url ? url : ''
		);
		refreshList( root );
	}

	/* ---------- reply form ---------- */

	function handleReply( form ) {
		var root = closest( form, '.ticketoo' ) || form;
		var id = fieldValue( form, 'ticketoo_id' );

		if ( '' === id ) {
			nativeSubmit( form );
			return;
		}

		var data = new FormData();

		data.append( 'content', fieldValue( form, 'ticketoo_content' ) );

		// The bearer token comes from the template's own hidden field (the
		// same one the classic POST uses) and is sent only to this ticket's
		// REST route; it is never stored or logged.
		var token = fieldValue( form, 'ticketoo_token' );

		if ( '' !== token ) {
			data.append( 'token', token );
		}

		var files = form.querySelector( '[name="files[]"]' );

		if ( files && files.files ) {
			for ( var i = 0; i < files.files.length; i++ ) {
				data.append( 'files[]', files.files[ i ] );
			}
		}

		mutate(
			config.restUrl + '/tickets/' + encodeURIComponent( id ) + '/messages',
			{
				method: 'POST',
				headers: { 'X-WP-Nonce': nonceHeader() },
				credentials: 'same-origin',
				body: data
			},
			form,
			root,
			'replied',
			function ( json ) {
				onReplied( form, root, json );
			}
		);
	}

	function onReplied( form, root, json ) {
		var messages = root.querySelector( '.ticketoo-messages' );

		if ( ! messages ) {
			throw new Error( 'ticketoo: conversation markup missing' );
		}

		messages.appendChild( messageNode( json ) );

		// The reply payload carries the post-reply status: a customer reply
		// can move the ticket back to open server-side, so refresh the chip.
		var status = json && 'string' === typeof json.ticket_status && '' !== json.ticket_status ? json.ticket_status : '';

		if ( '' !== status ) {
			var badge = root.querySelector( '.ticketoo-badge' );

			if ( badge ) {
				setBadge( badge, status );
			}
		}

		var content = form.querySelector( '[name="ticketoo_content"]' );

		if ( content ) {
			content.value = '';
		}

		var files = form.querySelector( '[name="files[]"]' );

		if ( files ) {
			files.value = '';
		}

		showNotice( root, 'success', text( 'replied', 'Your reply has been sent.' ) );
	}

	function messageNode( json ) {
		var li = document.createElement( 'li' );
		var isAgent = json && 1 === parseInt( json.is_agent, 10 );

		li.className = 'ticketoo-message ' + ( isAgent ? 'ticketoo-message--agent' : 'ticketoo-message--user' );

		var author = document.createElement( 'span' );

		author.className = 'ticketoo-message__author';
		author.appendChild( document.createTextNode( text( 'you', 'You' ) ) );
		li.appendChild( author );

		var stamp = mysqlStamp( new Date() );
		var time = document.createElement( 'time' );

		time.className = 'ticketoo-message__time';
		time.setAttribute( 'datetime', stamp );
		time.appendChild( document.createTextNode( stamp ) );
		li.appendChild( time );

		var body = document.createElement( 'div' );

		body.className = 'ticketoo-message__body';
		// The REST reply echoes content after wp_kses_post() — the same
		// sanitizing templates/conversation.php applies on the server.
		body.innerHTML = json && 'string' === typeof json.content ? json.content : '';
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

	/* ---------- close ticket ---------- */

	function handleClose( event, button ) {
		event.preventDefault();

		var form = closest( button, 'form' );
		var root = closest( button, '.ticketoo' );

		if ( ! form ) {
			return;
		}

		var anchor = root || form;
		var id = button.getAttribute( 'data-close-ticket' );

		if ( ! id ) {
			nativeSubmit( form );
			return;
		}

		mutate(
			config.restUrl + '/tickets/' + encodeURIComponent( id ) + '/status',
			jsonOptions( { status: 'closed' } ),
			form,
			anchor,
			'closed',
			function ( json ) {
				onClosed( anchor, form, json );
			}
		);
	}

	function onClosed( root, form, json ) {
		var status = json && 'string' === typeof json.status && '' !== json.status ? json.status : 'closed';
		var badge = root.querySelector( '.ticketoo-badge' );

		if ( ! badge ) {
			throw new Error( 'ticketoo: badge markup missing' );
		}

		setBadge( badge, status );

		if ( form && form.parentNode ) {
			form.parentNode.removeChild( form );
		}

		showNotice( root, 'success', text( 'closed', 'The ticket has now been closed.' ) );
	}

	/**
	 * Rewrites a status chip in place: modifier class plus translated label.
	 *
	 * @param {HTMLElement} badge The .ticketoo-badge element.
	 * @param {string}      status Status slug.
	 */
	function setBadge( badge, status ) {
		badge.className = 'ticketoo-badge ticketoo-badge--' + status;

		while ( badge.firstChild ) {
			badge.removeChild( badge.firstChild );
		}

		badge.appendChild( document.createTextNode( statusLabel( status ) ) );
	}

	/* ---------- delegated listeners ---------- */

	function onClick( event ) {
		if ( event.defaultPrevented || event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		// The filters live in .ticketoo-filters, a sibling of .ticketoo-list
		// — match the data hooks themselves, never ".ticketoo-list [data-*]".
		var filter = target.closest( '[data-filter-status]' );

		if ( filter && 'A' === filter.tagName && filter.getAttribute( 'href' ) ) {
			try {
				handleListLink( event, filter );
			} catch ( error ) {
				window.location.href = filter.href;
			}

			return;
		}

		var pager = target.closest( '[data-page]' );

		if ( pager && 'A' === pager.tagName && pager.getAttribute( 'href' ) ) {
			try {
				handleListLink( event, pager );
			} catch ( error ) {
				window.location.href = pager.href;
			}

			return;
		}

		var closer = target.closest( '[data-close-ticket]' );

		if ( closer ) {
			handleClose( event, closer );
		}
	}

	function onSubmit( event ) {
		if ( event.defaultPrevented ) {
			return;
		}

		var form = event.target;

		if ( ! form || 'FORM' !== form.tagName ) {
			return;
		}

		if ( 'ticketoo-form' === form.id ) {
			event.preventDefault();

			// A second submit while the first request is in flight would
			// create a duplicate ticket — ignore it.
			if ( isBusy( form ) ) {
				return;
			}

			try {
				handleCreate( form );
			} catch ( error ) {
				nativeSubmit( form );
			}

			return;
		}

		if ( 'ticketoo-reply' === form.id ) {
			event.preventDefault();

			// Same guard as the create form: a duplicate reply POST.
			if ( isBusy( form ) ) {
				return;
			}

			try {
				handleReply( form );
			} catch ( error ) {
				nativeSubmit( form );
			}
		}
	}

	function init() {
		document.addEventListener( 'click', onClick, false );
		document.addEventListener( 'submit', onSubmit, false );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init, false );
	} else {
		init();
	}
} )();
