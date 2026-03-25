/**
 * GeoPrice for PMPro — Admin JavaScript
 *
 * @package   GeoPrice_For_PMPro
 * @copyright 2024-2026 GAVAMEDIA Corporation (https://gavamedia.com)
 * @license   GPL-2.0-or-later
 *
 * This script powers the interactive country pricing UI on the PMPro membership
 * level edit page. It handles:
 *
 *   1. PRICING TABLE:
 *      A compact table showing only active countries (US/CA/MX defaults + any
 *      countries with saved prices). Each row has price inputs and a Remove button.
 *      Row highlighting (green) is applied dynamically when prices are entered.
 *
 *   2. ADD COUNTRY MODAL:
 *      A popup dialog listing all ~195 countries. Features:
 *        - Real-time search/filter by country name.
 *        - Sort by name (A-Z) or population (largest first).
 *        - Group by continent with sticky headers.
 *        - Countries already in the table are greyed out with "Added" label.
 *        - Clicking "+ Add" instantly inserts a new row into the pricing table.
 *
 *   3. REMOVE COUNTRY:
 *      Each table row has a Remove button. Clicking it removes the row from
 *      the DOM. When the form is saved, removed countries have no inputs in
 *      the POST data, so their prices are effectively deleted.
 *
 * DATA SOURCE:
 *   Country data (name, currency, continent, population) is passed from PHP
 *   via wp_localize_script() as the global `geoPriceData.countries` object.
 *
 * DEPENDENCIES:
 *   - jQuery (bundled with WordPress admin).
 *   - geoPriceData global (set by admin-level-pricing.php via wp_localize_script).
 */
(function($) {
	'use strict';

	$(function() {
		var countries = geoPriceData.countries || {};
		var $table    = $('#geoprice-country-table');
		var $tbody    = $('#geoprice-country-tbody');
		var $addBtn   = $('#geoprice-add-country-btn');
		var $overlay  = $('#geoprice-modal-overlay');
		var $search   = $('#geoprice-modal-search');
		var $sort     = $('#geoprice-modal-sort');
		var $group    = $('#geoprice-modal-group');
		var $list     = $('#geoprice-modal-list');

		/* ================================================================
		   PRICING TABLE — Row highlighting
		   ================================================================ */

		/**
		 * Update green highlight on rows that have at least one price entered.
		 */
		function updateRowHighlights() {
			$tbody.find('tr').each(function() {
				var $row = $(this);
				var hasValue = false;
				$row.find('.geoprice-price-input').each(function() {
					if ($(this).val().trim() !== '') {
						hasValue = true;
						return false;
					}
				});
				$row.toggleClass('geoprice-has-price', hasValue);
			});
		}

		/* Delegated input handler for real-time highlight updates. */
		$table.on('input', '.geoprice-price-input', function() {
			updateRowHighlights();
		});

		/* Initial highlight pass on page load. */
		updateRowHighlights();


		/* ================================================================
		   PRICING TABLE — Remove country
		   ================================================================ */

		/**
		 * Remove a country row when the Remove button is clicked.
		 * Uses event delegation since rows can be added dynamically.
		 */
		$tbody.on('click', '.geoprice-remove-btn', function(e) {
			e.preventDefault();
			$(this).closest('tr').fadeOut(200, function() {
				$(this).remove();
				/* If the modal is open, refresh it to un-grey the removed country. */
				if ($overlay.is(':visible')) {
					renderModalList();
				}
			});
		});


		/* ================================================================
		   PRICING TABLE — Add country row
		   ================================================================ */

		/**
		 * Build and insert a new table row for a given country code.
		 * Called when the admin clicks "+ Add" in the modal.
		 *
		 * @param {string} code ISO 3166-1 alpha-2 country code.
		 */
		function addCountryRow(code) {
			var c = countries[code];
			if (!c) return;

			/* Don't add duplicates. */
			if ($tbody.find('tr[data-code="' + code + '"]').length > 0) return;

			var html = '<tr data-code="' + escAttr(code) + '">' +
				'<td class="geoprice-col-country">' +
					'<strong>' + escHtml(c.name) + '</strong> ' +
					'<span class="geoprice-country-code">(' + escHtml(code) + ')</span>' +
				'</td>' +
				'<td class="geoprice-col-currency">' + escHtml(c.currency) + '</td>' +
				'<td class="geoprice-col-price">' +
					'<span class="geoprice-dollar-prefix">$</span>' +
					'<input type="text" name="geoprice_prices[' + escAttr(code) + '][initial_payment]" ' +
						'value="" placeholder="default" ' +
						'class="small-text geoprice-price-input" ' +
						'pattern="[0-9]*\\.?[0-9]*" inputmode="decimal" />' +
				'</td>' +
				'<td class="geoprice-col-price">' +
					'<span class="geoprice-dollar-prefix">$</span>' +
					'<input type="text" name="geoprice_prices[' + escAttr(code) + '][billing_amount]" ' +
						'value="" placeholder="default" ' +
						'class="small-text geoprice-price-input" ' +
						'pattern="[0-9]*\\.?[0-9]*" inputmode="decimal" />' +
				'</td>' +
				'<td class="geoprice-col-actions">' +
					'<button type="button" class="button button-link-delete geoprice-remove-btn" title="Remove">' +
						'<span class="dashicons dashicons-no-alt"></span>' +
					'</button>' +
				'</td>' +
			'</tr>';

			var $newRow = $(html).hide();
			$tbody.append($newRow);
			$newRow.fadeIn(200);

			/* Refresh modal to grey out the newly added country. */
			if ($overlay.is(':visible')) {
				renderModalList();
			}
		}


		/* ================================================================
		   MODAL — Open / Close
		   ================================================================ */

		$addBtn.on('click', function(e) {
			e.preventDefault();
			$search.val('');
			renderModalList();
			$overlay.fadeIn(150);
			$search.focus();
		});

		/* Close on X button click. */
		$overlay.on('click', '.geoprice-modal-close', function(e) {
			e.preventDefault();
			$overlay.fadeOut(150);
		});

		/* Close on overlay background click (not the modal itself). */
		$overlay.on('click', function(e) {
			if ($(e.target).is('.geoprice-modal-overlay')) {
				$overlay.fadeOut(150);
			}
		});

		/* Close on Escape key. */
		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && $overlay.is(':visible')) {
				$overlay.fadeOut(150);
			}
		});


		/* ================================================================
		   MODAL — Controls (search, sort, group)
		   ================================================================ */

		$search.on('input', function() {
			renderModalList();
		});

		$sort.on('change', function() {
			renderModalList();
		});

		$group.on('change', function() {
			renderModalList();
		});


		/* ================================================================
		   MODAL — Add button click (delegated)
		   ================================================================ */

		$list.on('click', '.geoprice-modal-add-btn', function(e) {
			e.preventDefault();
			var code = $(this).data('code');
			addCountryRow(code);
		});


		/* ================================================================
		   MODAL — Render the country list
		   ================================================================
		   This is the core rendering function. It:
		     1. Reads current search query, sort order, and group toggle.
		     2. Builds an array of country entries from geoPriceData.
		     3. Filters by search query.
		     4. Sorts by selected order.
		     5. Optionally groups by continent with sticky headers.
		     6. Marks countries already in the pricing table as "Added".
		     7. Outputs the HTML into the modal list container.
		*/
		function renderModalList() {
			var query     = ($search.val() || '').toLowerCase().trim();
			var sortBy    = $sort.val();
			var groupBy   = $group.is(':checked');

			/* Build flat array of country objects. */
			var entries = [];
			$.each(countries, function(code, data) {
				entries.push({
					code:       code,
					name:       data.name,
					currency:   data.currency,
					continent:  data.continent,
					population: data.population
				});
			});

			/* Filter by search query. */
			if (query) {
				entries = entries.filter(function(e) {
					return e.name.toLowerCase().indexOf(query) !== -1 ||
					       e.code.toLowerCase().indexOf(query) !== -1;
				});
			}

			/* Sort. */
			if (sortBy === 'population') {
				entries.sort(function(a, b) {
					return b.population - a.population;
				});
			} else {
				entries.sort(function(a, b) {
					return a.name.localeCompare(b.name);
				});
			}

			/* Get set of country codes already in the pricing table. */
			var addedCodes = {};
			$tbody.find('tr[data-code]').each(function() {
				addedCodes[$(this).data('code')] = true;
			});

			/* Build HTML. */
			var html = '';

			if (entries.length === 0) {
				html = '<div class="geoprice-modal-empty">No countries found.</div>';
			} else if (groupBy) {
				/* Group by continent. */
				var continentOrder = ['North America', 'South America', 'Europe', 'Asia', 'Africa', 'Oceania'];
				var grouped = {};
				$.each(entries, function(_, e) {
					if (!grouped[e.continent]) {
						grouped[e.continent] = [];
					}
					grouped[e.continent].push(e);
				});

				$.each(continentOrder, function(_, continent) {
					if (!grouped[continent] || grouped[continent].length === 0) return;
					html += '<div class="geoprice-modal-continent-header">' + escHtml(continent) + '</div>';
					$.each(grouped[continent], function(_, e) {
						html += buildModalRow(e, addedCodes);
					});
				});
			} else {
				/* Flat list. */
				$.each(entries, function(_, e) {
					html += buildModalRow(e, addedCodes);
				});
			}

			$list.html(html);

			/* Scroll to top when re-rendering. */
			$list.scrollTop(0);
		}

		/**
		 * Build the HTML for a single modal row.
		 *
		 * @param {Object}  entry      Country object {code, name, currency, continent, population}.
		 * @param {Object}  addedCodes Hash of country codes already in the pricing table.
		 * @return {string} HTML string.
		 */
		function buildModalRow(entry, addedCodes) {
			var isAdded = addedCodes[entry.code] || false;
			var cls = 'geoprice-modal-row' + (isAdded ? ' geoprice-already-added' : '');

			var html = '<div class="' + cls + '">' +
				'<span class="geoprice-modal-row-name">' +
					escHtml(entry.name) +
					' <span class="geoprice-modal-row-code">' + escHtml(entry.code) + '</span>' +
				'</span>' +
				'<span class="geoprice-modal-row-currency">' + escHtml(entry.currency) + '</span>';

			if (isAdded) {
				html += '<span class="geoprice-modal-added-label">Added</span>';
			} else {
				html += '<button type="button" class="button button-secondary geoprice-modal-add-btn" data-code="' + escAttr(entry.code) + '">+ Add</button>';
			}

			html += '</div>';
			return html;
		}


		/* ================================================================
		   UTILITY — HTML escaping helpers
		   ================================================================ */

		function escHtml(str) {
			var div = document.createElement('div');
			div.appendChild(document.createTextNode(str));
			return div.innerHTML;
		}

		function escAttr(str) {
			return str.replace(/&/g, '&amp;')
			          .replace(/"/g, '&quot;')
			          .replace(/'/g, '&#39;')
			          .replace(/</g, '&lt;')
			          .replace(/>/g, '&gt;');
		}

	});
})(jQuery);
