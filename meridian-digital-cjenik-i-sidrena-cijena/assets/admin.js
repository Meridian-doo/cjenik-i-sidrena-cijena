/*
 * Small enhancements for the Price list screens. Everything works without
 * them: filters submit with Enter, the drop zone is a file input, and a toast
 * is plain text.
 */

// Filters that apply as soon as they change.
document.addEventListener( 'change', ( event ) => {
	if ( event.target.matches( '[data-cjenik-autosubmit]' ) ) {
		event.target.form.requestSubmit();
	}
} );

// A spinner in the button while the form submits.
document.addEventListener( 'submit', ( event ) => {
	const button = event.submitter;
	if ( button && button.matches( '[data-cjenik-busy]' ) ) {
		button.classList.add( 'is-busy' );
		button.setAttribute( 'aria-busy', 'true' );
	}
} );

// Coming back to the page from the history shows it as it was left.
window.addEventListener( 'pageshow', ( event ) => {
	if ( event.persisted ) {
		document.querySelectorAll( '.is-busy[data-cjenik-busy]' ).forEach( ( button ) => {
			button.classList.remove( 'is-busy' );
			button.removeAttribute( 'aria-busy' );
		} );
	}
} );

// A confirmation toast goes after 6 seconds, or on a click, and waits while
// it has the pointer or focus. Its text is put back after load so screen
// readers announce it.
document.querySelectorAll( '.cjenik-toast' ).forEach( ( toast ) => {
	const message = toast.querySelector( 'p' );
	const text = message.textContent;
	let timer;
	const hide = () => {
		toast.classList.add( 'is-leaving' );
		setTimeout( () => toast.remove(), 200 );
	};
	const wait = () => {
		clearTimeout( timer );
		timer = setTimeout( hide, 6000 );
	};
	message.textContent = '';
	setTimeout( () => ( message.textContent = text ), 100 );
	wait();
	toast.addEventListener( 'mouseenter', () => clearTimeout( timer ) );
	toast.addEventListener( 'focusin', () => clearTimeout( timer ) );
	toast.addEventListener( 'mouseleave', wait );
	toast.addEventListener( 'focusout', wait );
	toast.addEventListener( 'click', () => {
		clearTimeout( timer );
		hide();
	} );
} );

// The chosen file under the drop zone, with a way to take it back.
const kilobytes = new Intl.NumberFormat( document.documentElement.lang || undefined, { style: 'unit', unit: 'kilobyte', maximumFractionDigits: 0 } );
document.querySelectorAll( '.cjenik-upload' ).forEach( ( form ) => {
	const zone = form.querySelector( '.cjenik-dropzone' );
	const input = zone.querySelector( 'input[type="file"]' );
	const row = form.querySelector( '.cjenik-file' );
	const show = () => {
		const file = input.files[ 0 ];
		row.hidden = ! file;
		if ( file ) {
			row.querySelector( '.cjenik-file__name' ).textContent = file.name;
			row.querySelector( '.cjenik-file__size' ).textContent = kilobytes.format( Math.max( 1, file.size / 1024 ) );
		}
	};
	input.addEventListener( 'change', show );
	[ 'dragenter', 'dragover' ].forEach( ( type ) => zone.addEventListener( type, () => zone.classList.add( 'is-dragging' ) ) );
	[ 'dragleave', 'drop' ].forEach( ( type ) => zone.addEventListener( type, () => zone.classList.remove( 'is-dragging' ) ) );
	row.querySelector( '[data-cjenik-remove]' ).addEventListener( 'click', () => {
		input.value = '';
		show();
		input.focus();
	} );
} );
