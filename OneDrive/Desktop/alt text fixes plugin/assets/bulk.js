( function ( $ ) {
	'use strict';

	var state = {
		lib:    { running: false, offset: 0, total: 0 },
		content:{ running: false, offset: 0, total: 0 },
		meta:   { running: false, offset: 0, total: 0 },
		css:    { running: false, offset: 0, total: 0 },
		global: { running: false, offset: 0, total: 0 },
		seo:    { running: false, offset: 0, total: 0 },
		schema: { running: false, offset: 0, total: 0 }
	};

	var i18n = {
		one:    'There is 1 item missing alt text.',
		many:   'There are %d items missing alt text.',
		done:   'All missing alt text has been fixed.',
		error:  'Stopped: a server error occurred.',
		stopped:'Stopped.',
		pinged: 'Search engines notified (%d).'
	};

	function api( target ) {
	var map = {
		lib:     { batch: 'atf_batch', count: 'atf_count', batchKey: 'libBatch' },
		content: { batch: 'atf_content_batch', count: 'atf_content_count', batchKey: 'conBatch' },
		meta:    { batch: 'atf_meta_batch', count: 'atf_meta_count', batchKey: 'metaBatch' },
		css:     { batch: 'atf_css_batch', count: 'atf_css_count', batchKey: 'cssBatch' },
		global:  { batch: 'atf_global_batch', count: 'atf_global_count', batchKey: 'globalBatch' },
		seo:     { batch: 'atf_seo_batch', count: 'atf_seo_count', batchKey: 'seoBatch' },
		schema:  { batch: 'atf_schema_batch', count: 'atf_schema_count', batchKey: 'schemaBatch' }
	};
		return map[ target ] || map.content;
	}

	function setProgress( target, processed, total ) {
		var pct = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : 100;
		$( '.atf-progress[data-target="' + target + '"] .atf-progress-fill' ).css( 'width', pct + '%' );
		$( '.atf-progress[data-target="' + target + '"] .atf-progress-text' ).text( processed + ' / ' + total + ' (' + pct + '%)' );
	}

	function finish( target, text ) {
		var s = state[ target ];
		s.running = false;
		$( '.atf-start[data-target="' + target + '"]' ).prop( 'disabled', false ).show();
		$( '.atf-stop[data-target="' + target + '"]' ).hide();
		$( '.atf-spinner[data-target="' + target + '"]' ).removeClass( 'is-active' );
		$( '.atf-progress[data-target="' + target + '"]' ).delay( 1200 ).fadeOut( 400, function () {
			$( this ).find( '.atf-progress-fill' ).css( 'width', '0%' );
		} );
		if ( text ) {
			$( '.atf-progress[data-target="' + target + '"] .atf-progress-text' ).text( text );
		}
		refreshCount( target );
	}

	function refreshCount( target ) {
		var a = api( target );
		$.post( ATF.ajaxurl, { action: a.count, nonce: ATF.nonce }, function ( resp ) {
			if ( ! resp.success ) {
				return;
			}
			var n = resp.data.total;
			var msg = n === 1 ? i18n.one : i18n.many.replace( '%d', n );
			$( '#atf-' + target + '-count' ).text( msg );
		} );
	}

	function runBatch( target ) {
		var s = state[ target ];
		if ( ! s.running ) {
			return;
		}
		var a = api( target );
		$.ajax( {
			url: ATF.ajaxurl,
			method: 'POST',
			data: {
				action: a.batch,
				nonce: ATF.nonce,
				offset: s.offset,
				limit: ATF[ a.batchKey ]
			},
			dataType: 'json'
		} ).done( function ( resp ) {
			if ( ! s.running ) {
				return;
			}
			if ( ! resp.success ) {
				finish( target, i18n.error );
				return;
			}
			var d = resp.data;
			s.offset  = d.processed;
			s.total   = d.processed + d.remaining;
			setProgress( target, d.processed, s.total );

			if ( d.finished || d.remaining <= 0 ) {
				var msg = i18n.done;
				if ( 'seo' === target && d.pinged ) {
					msg += ' ' + i18n.pinged.replace( '%d', d.pinged );
				}
				finish( target, msg );
				return;
			}
			setTimeout( function () { runBatch( target ); }, 200 );
		} ).fail( function () {
			if ( s.running ) {
				finish( target, i18n.error );
			}
		} );
	}

	$( function () {
		$( '.atf-start' ).on( 'click', function () {
			var target = $( this ).data( 'target' );
			var s = state[ target ];
			if ( s.running ) {
				return;
			}
			s.running = true;
			s.offset  = 0;
			$( this ).prop( 'disabled', true ).hide();
			$( '.atf-stop[data-target="' + target + '"]' ).show();
			$( '.atf-spinner[data-target="' + target + '"]' ).addClass( 'is-active' );
			$( '.atf-progress[data-target="' + target + '"]' ).show();
			setProgress( target, 0, 0 );
			runBatch( target );
		} );

		$( '.atf-stop' ).on( 'click', function () {
			var target = $( this ).data( 'target' );
			state[ target ].running = false;
			finish( target, i18n.stopped );
		} );

		$( '#atf-audit' ).on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true );
			$( '.atf-spinner[data-target="audit"]' ).addClass( 'is-active' );
			$( '#atf-audit-result' ).html( '' );
			$.post( ATF.ajaxurl, { action: 'atf_audit_run', nonce: ATF.nonce }, function ( resp ) {
				$( '.atf-spinner[data-target="audit"]' ).removeClass( 'is-active' );
				$btn.prop( 'disabled', false );
				if ( ! resp.success ) {
					$( '#atf-audit-result' ).html( '<p class="atf-missing">Error.</p>' );
					return;
				}
				var total = resp.data.total;
				if ( ! total ) {
					$( '#atf-audit-result' ).html( '<p class="atf-ok">No hard-coded images found in the active theme.</p>' );
					return;
				}
				var html = '<p><strong>' + total + ' issue(s) found:</strong></p><table class="widefat" style="max-width:760px"><thead><tr><th>File</th><th>Line</th><th>Type</th><th>Note</th></tr></thead><tbody>';
				$.each( resp.data.findings, function ( i, f ) {
					html += '<tr><td>' + esc( f.file ) + '</td><td>' + esc( f.line ) + '</td><td>' + esc( f.type ) + '</td><td>' + esc( f.detail ) + '</td></tr>';
				} );
				html += '</tbody></table>';
				if ( total > resp.data.findings.length ) {
					html += '<p class="description">Showing first ' + resp.data.findings.length + ' of ' + total + '.</p>';
				}
				$( '#atf-audit-result' ).html( html );
			} ).fail( function () {
				$( '.atf-spinner[data-target="audit"]' ).removeClass( 'is-active' );
				$btn.prop( 'disabled', false );
			} );
		} );

		$( '#atf-tech-audit' ).on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true );
			$( '.atf-spinner[data-target="tech"]' ).addClass( 'is-active' );
			$( '#atf-tech-audit-result' ).html( '' );
			$.post( ATF.ajaxurl, { action: 'atf_audit_run', nonce: ATF.nonce }, function ( resp ) {
				$( '.atf-spinner[data-target="tech"]' ).removeClass( 'is-active' );
				$btn.prop( 'disabled', false );
				if ( ! resp.success ) {
					$( '#atf-tech-audit-result' ).html( '<p class="atf-missing">Error.</p>' );
					return;
				}
				var d = resp.data;
				var badge = function ( s ) {
					if ( 'pass' === s ) { return '<span class="atf-ok">OK</span>'; }
					if ( 'warn' === s ) { return '<span class="atf-warn">WARN</span>'; }
					return '<span class="atf-missing">FAIL</span>';
				};
				var html = '<p><strong>' + d.pass + ' pass / ' + d.warn + ' warn / ' + d.fail + ' fail</strong></p>';
				html += '<table class="widefat" style="max-width:880px"><thead><tr><th>Check</th><th>Status</th><th>Detail</th><th>Fix</th></tr></thead><tbody>';
				$.each( d.checks, function ( i, c ) {
					var fix = '';
					if ( c.fix ) {
						fix = '<button type="button" class="button atf-tech-fix" data-fix="' + esc( c.fix ) + '">' + esc( c.label ) + '</button>';
					}
					html += '<tr><td>' + esc( c.label ) + '</td><td>' + badge( c.status ) + '</td><td>' + esc( c.detail ) + '</td><td>' + fix + '</td></tr>';
				} );
				html += '</tbody></table>';
				$( '#atf-tech-audit-result' ).html( html );
			} ).fail( function () {
				$( '.atf-spinner[data-target="tech"]' ).removeClass( 'is-active' );
				$btn.prop( 'disabled', false );
			} );
		} );

		$( document ).on( 'click', '.atf-tech-fix', function () {
			var $btn = $( this );
			var fix = $btn.data( 'fix' );
			$btn.prop( 'disabled', true ).text( 'Applying…' );
			$.post( ATF.ajaxurl, { action: 'atf_audit_apply', nonce: ATF.nonce, fix: fix }, function ( resp ) {
				if ( resp.success ) {
					$btn.closest( 'tr' ).find( 'td' ).eq( 2 ).append( ' <span class="atf-ok">(applied)</span>' );
					$btn.remove();
				} else {
					$btn.prop( 'disabled', false ).text( 'Retry' );
				}
			} ).fail( function () {
				$btn.prop( 'disabled', false ).text( 'Retry' );
			} );
		} );

		$( '#atf-run-all' ).on( 'click', function () {
			var $btn = $( this );
			if ( $btn.data( 'running' ) ) {
				return;
			}
			$btn.data( 'running', true ).prop( 'disabled', true );
			$( '.atf-spinner[data-target="runall"]' ).addClass( 'is-active' );
			$( '#atf-run-all-status' ).text( 'Running…' );
			$.post( ATF.ajaxurl, { action: 'atf_run_all', nonce: ATF.nonce }, function ( resp ) {
				$( '.atf-spinner[data-target="runall"]' ).removeClass( 'is-active' );
				$btn.data( 'running', false ).prop( 'disabled', false );
				if ( ! resp.success ) {
					$( '#atf-run-all-status' ).text( 'Error.' );
					return;
				}
				var d = resp.data;
				$( '#atf-run-all-status' ).html(
					'Done — library: ' + d.lib + ', content: ' + d.content + ', meta: ' + d.meta +
					', css: ' + d.css + ', seo: ' + d.seo + ', schema: ' + d.schema
				);
			} ).fail( function () {
				$( '.atf-spinner[data-target="runall"]' ).removeClass( 'is-active' );
				$btn.data( 'running', false ).prop( 'disabled', false );
				$( '#atf-run-all-status' ).text( 'Error.' );
			} );
		} );

		function esc( s ) {
			return String( s ).replace( /[&<>"']/g, function ( c ) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
			} );
		}
	} );
} )( jQuery );
