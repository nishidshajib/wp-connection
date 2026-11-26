( function () {
	'use strict';
	function boot() {
	var cfg = window.wpcDeactivate || {};
	var row = document.querySelector( 'tr[data-plugin="' + cfg.basename + '"]' );
	var dlg = document.getElementById( 'wpc-dlg' );
	if ( ! row || ! dlg ) {
		return;
	}
	var link = row.querySelector( '.deactivate a' );
	if ( ! link ) {
		return;
	}
	var temp = document.getElementById( 'wpc-dlg-temp' );
	var full = document.getElementById( 'wpc-dlg-full' );
	var warn = document.getElementById( 'wpc-dlg-warn' );
	var confirmBox = document.getElementById( 'wpc-dlg-confirm' );
	var radios = dlg.querySelectorAll( 'input[name="mode"]' );
	var lastFocus = null;

	temp.href = link.href;

	function sync() {
		var mode = dlg.querySelector( 'input[name="mode"]:checked' ).value;
		var isFull = mode === 'full';
		warn.hidden = ! isFull;
		full.hidden = ! isFull;
		temp.hidden = isFull;
		full.disabled = ! confirmBox.checked;
		dlg.querySelectorAll( '.wpc-opt' ).forEach( function ( o ) {
			o.classList.toggle( 'is-on', o.querySelector( 'input' ).checked );
		} );
	}

	function open( e ) {
		e.preventDefault();
		lastFocus = document.activeElement;
		dlg.hidden = false;
		sync();
		radios[ 0 ].focus();
	}

	function close() {
		dlg.hidden = true;
		if ( lastFocus ) {
			lastFocus.focus();
		}
	}

	link.addEventListener( 'click', open );
	radios.forEach( function ( r ) {
		r.addEventListener( 'change', sync );
	} );
	confirmBox.addEventListener( 'change', sync );
	dlg.querySelectorAll( '[data-wpc-close]' ).forEach( function ( b ) {
		b.addEventListener( 'click', close );
	} );
	dlg.addEventListener( 'click', function ( e ) {
		if ( e.target === dlg ) {
			close();
		}
	} );
	dlg.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			close();
			return;
		}
		if ( e.key !== 'Tab' ) {
			return;
		}
		var f = Array.prototype.filter.call( dlg.querySelectorAll( 'button, a[href], input' ), function ( el ) {
			return ! el.hidden && ! el.disabled && el.offsetParent !== null;
		} );
		if ( ! f.length ) {
			return;
		}
		if ( e.shiftKey && document.activeElement === f[ 0 ] ) {
			e.preventDefault();
			f[ f.length - 1 ].focus();
		} else if ( ! e.shiftKey && document.activeElement === f[ f.length - 1 ] ) {
			e.preventDefault();
			f[ 0 ].focus();
		}
	} );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
