/**
 * Prepaid duties column for the Analytics Orders report table.
 *
 * WooCommerce builds the browser CSV export of a report that fits on one page
 * from this table, so the column has to be added here as well as to the
 * server-side export. The amount comes from the `prepaid_duties` field the
 * plugin adds to each report row, which this script is only loaded alongside.
 */
( function () {
	'use strict';

	var COLUMN = 'prepaid_duties';
	var LABEL = ( window.wcShipStationPrepaidDuties && window.wcShipStationPrepaidDuties.label ) || 'Prepaid duties';

	window.wp.hooks.addFilter(
		'woocommerce_admin_report_table',
		'woocommerce-shipstation-integration/prepaid-duties',
		function ( reportTableData ) {
			if ( 'orders' !== reportTableData.endpoint ) {
				return reportTableData;
			}

			// The header is added even before any rows have loaded: the table
			// settles which columns are shown on its first render, and a column
			// first seen later starts out hidden.
			var items = ( reportTableData.items && reportTableData.items.data ) || [];

			// Rows are paired with report items by position. If another filter
			// added or removed rows first, leave the cells empty rather than
			// show one order's duties against another.
			var aligned = items.length === reportTableData.rows.length;

			// The report currency, as Net sales uses it: filtered for the current
			// query, and render() marks negative amounts.
			var reportCurrency = window.wc && window.wc.currency && window.wc.navigation
				? window.wc.currency.getFilteredCurrencyInstance( window.wc.navigation.getQuery() )
				: null;

			reportTableData.headers = reportTableData.headers.concat( {
				label: LABEL,
				key: COLUMN,
				isNumeric: true,
			} );

			reportTableData.rows = reportTableData.rows.map( function ( row, index ) {
				if ( ! aligned ) {
					return row.concat( { display: '', value: '' } );
				}

				var item = items[ index ] || {};
				var amount = Number( item[ COLUMN ] ) || 0;

				return row.concat( {
					display: reportCurrency ? reportCurrency.render( amount ) : String( amount ),
					value: amount,
				} );
			} );

			return reportTableData;
		}
	);
} )();
