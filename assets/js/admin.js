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
 *      A polished popup dialog listing all ~195 countries. Features:
 *        - Flag emojis for visual identification.
 *        - Two-line layout: country name on top, code + currency below.
 *        - Real-time search/filter by country name or code.
 *        - Sort by name (A-Z) or population (largest first).
 *        - Group by continent with sticky headers.
 *        - Countries already in the table are dimmed with a green "Added" badge.
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
		   UTILITY — Flag emoji from country code
		   ================================================================
		   Convert a 2-letter ISO country code to a flag emoji.
		   Works by mapping each letter to a Unicode Regional Indicator Symbol:
		     'A' => 0x1F1E6, 'B' => 0x1F1E7, ... 'Z' => 0x1F1FF
		   Pairing two of these gives the flag emoji for that country.
		   Example: 'US' => 🇺🇸, 'CA' => 🇨🇦
		*/
		function codeToFlag(code) {
			if (!code || code.length !== 2) return '';
			var first  = 0x1F1E6 + (code.charCodeAt(0) - 65);
			var second = 0x1F1E6 + (code.charCodeAt(1) - 65);
			return String.fromCodePoint(first) + String.fromCodePoint(second);
		}


		/* ================================================================
		   PRICING TABLE — Row highlighting
		   ================================================================ */

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

		$table.on('input', '.geoprice-price-input', function() {
			updateRowHighlights();
		});

		updateRowHighlights();


		/* ================================================================
		   PRICING TABLE — Remove country
		   ================================================================ */

		$tbody.on('click', '.geoprice-remove-btn', function(e) {
			e.preventDefault();
			$(this).closest('tr').fadeOut(200, function() {
				$(this).remove();
				if ($overlay.is(':visible')) {
					renderModalList();
				}
			});
		});


		/* ================================================================
		   PRICING TABLE — Add country row
		   ================================================================ */

		function addCountryRow(code) {
			var c = countries[code];
			if (!c) return;

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
			/* Small delay so the fade-in is visible before focus shifts. */
			setTimeout(function() { $search.focus(); }, 160);
		});

		$overlay.on('click', '.geoprice-modal-close', function(e) {
			e.preventDefault();
			$overlay.fadeOut(150);
		});

		$overlay.on('click', function(e) {
			if ($(e.target).is('.geoprice-modal-overlay')) {
				$overlay.fadeOut(150);
			}
		});

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
		   ================================================================ */

		function renderModalList() {
			var query   = ($search.val() || '').toLowerCase().trim();
			var sortBy  = $sort.val();
			var groupBy = $group.is(':checked');

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

			/* Filter by search query (matches name or code). */
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

			/* Determine which countries are already in the pricing table. */
			var addedCodes = {};
			$tbody.find('tr[data-code]').each(function() {
				addedCodes[$(this).data('code')] = true;
			});

			/* Build HTML. */
			var html = '';

			if (entries.length === 0) {
				html = '<div class="geoprice-modal-empty">' +
					'<div class="geoprice-modal-empty-icon">&#x1F50D;</div>' +
					'<div class="geoprice-modal-empty-text">No countries match your search.</div>' +
				'</div>';
			} else if (groupBy) {
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
					html += '<div class="geoprice-modal-continent-header">' +
						escHtml(continent) +
						' <span style="opacity:0.5;font-weight:400;">(' + grouped[continent].length + ')</span>' +
					'</div>';
					$.each(grouped[continent], function(_, e) {
						html += buildModalRow(e, addedCodes);
					});
				});
			} else {
				$.each(entries, function(_, e) {
					html += buildModalRow(e, addedCodes);
				});
			}

			$list.html(html);
			$list.scrollTop(0);
		}

		/**
		 * Build the HTML for a single modal country row.
		 *
		 * Layout: [Flag] [Name / Code · Currency] [+ Add | ✓ Added]
		 */
		function buildModalRow(entry, addedCodes) {
			var isAdded = addedCodes[entry.code] || false;
			var cls = 'geoprice-modal-row' + (isAdded ? ' geoprice-already-added' : '');
			var flag = codeToFlag(entry.code);

			var html = '<div class="' + cls + '">';

			/* Flag emoji */
			html += '<span class="geoprice-modal-row-flag">' + flag + '</span>';

			/* Country info: name on line 1, code + currency on line 2 */
			html += '<div class="geoprice-modal-row-info">' +
				'<div class="geoprice-modal-row-name">' + escHtml(entry.name) + '</div>' +
				'<div class="geoprice-modal-row-meta">' +
					escHtml(entry.code) +
					'<span class="geoprice-sep">&middot;</span>' +
					escHtml(entry.currency) +
				'</div>' +
			'</div>';

			/* Action: Add button or Added badge */
			if (isAdded) {
				html += '<span class="geoprice-modal-added-badge">&#10003; Added</span>';
			} else {
				html += '<button type="button" class="geoprice-modal-add-btn" data-code="' + escAttr(entry.code) + '">+ Add</button>';
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
