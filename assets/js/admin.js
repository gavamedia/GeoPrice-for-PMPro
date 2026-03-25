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
 *      Column headers (Country, Initial Payment, Renewal Amount) are sortable.
 *      Sort preference is persisted in localStorage across all membership levels.
 *
 *   2. PPP (PURCHASING POWER PARITY):
 *      When PPP data is available (fetched from the World Bank API), a PPP column
 *      shows each country's purchasing power multiplier relative to the US.
 *      "Apply Suggested" buttons let the admin auto-fill prices based on the
 *      base price × PPP multiplier. An "Apply Suggested Pricing to All" button
 *      fills all countries at once. A "Learn More" popup explains the methodology.
 *
 *   3. ADD COUNTRY MODAL:
 *      A polished popup dialog listing all ~195 countries. Features:
 *        - Flag emojis for instant visual identification.
 *        - Two-line layout: country name on top, code + currency below.
 *        - Real-time search/filter by country name or code.
 *        - Sort by name (A-Z) or population (largest first).
 *        - Group by continent with sticky headers.
 *        - Countries already in the table are dimmed with a green "Added" badge.
 *        - Clicking "+ Add" instantly inserts a new row into the pricing table.
 *
 *   4. REMOVE COUNTRY:
 *      Each table row has a Remove button. Clicking it removes the row from
 *      the DOM immediately. On form save, that country has no inputs in POST.
 *
 * MODAL RENDERING NOTE:
 *   On init, the modal overlay is moved from its original position (inside
 *   PMPro's form) to document.body. This prevents CSS containment issues
 *   (e.g., overflow:hidden, transform, or position:relative on ancestor
 *   elements) from breaking the fixed-position overlay.
 *
 *   Visibility is toggled via a CSS class (.geoprice-modal-visible) rather
 *   than jQuery's .show()/.hide(), because jQuery sets display:block which
 *   overrides our display:flex and breaks the centering layout.
 *
 * DATA SOURCE:
 *   Country data (name, currency, continent, population) is passed from PHP
 *   via wp_localize_script() as the global `geoPriceData.countries` object.
 *   PPP multipliers are passed as `geoPriceData.pppMultipliers` (if available).
 *
 * DEPENDENCIES:
 *   - jQuery (bundled with WordPress admin).
 *   - geoPriceData global (set by admin-level-pricing.php via wp_localize_script).
 */
(function($) {
	'use strict';

	$(function() {
		var countries      = geoPriceData.countries || {};
		var pppMultipliers = geoPriceData.pppMultipliers || {};
		var hasPPP         = Object.keys(pppMultipliers).length > 0;

		var $table    = $('#geoprice-country-table');
		var $tbody    = $('#geoprice-country-tbody');
		var $addBtn   = $('#geoprice-add-country-btn');
		var $overlay  = $('#geoprice-modal-overlay');
		var $search   = $('#geoprice-modal-search');
		var $sort     = $('#geoprice-modal-sort');
		var $group    = $('#geoprice-modal-group');
		var $list     = $('#geoprice-modal-list');
		var $saveNote       = $('#geoprice-save-reminder');
		var saveNoteVisible = false;

		/*
		 * Move the modal overlay to <body> so it's outside PMPro's form
		 * hierarchy. This prevents ancestor CSS properties (overflow, transform,
		 * position) from interfering with position:fixed on the overlay.
		 */
		$overlay.appendTo('body');

		/* Also move the PPP info popup to <body>. */
		var $pppOverlay = $('#geoprice-ppp-info-overlay');
		if ($pppOverlay.length) {
			$pppOverlay.appendTo('body');
		}

		/**
		 * Show the "unsaved changes" reminder.
		 * Called whenever the admin adds a country, removes a country,
		 * or edits a price field. Uses a boolean flag so it only fires
		 * once — subsequent calls are instant no-ops.
		 */
		function showSaveReminder() {
			if (saveNoteVisible) return;
			saveNoteVisible = true;
			$saveNote.css('display', 'block');
		}


		/* ================================================================
		   UTILITY — Flag emoji from country code
		   ================================================================
		   Convert a 2-letter ISO country code to a flag emoji.
		   Each letter A-Z maps to a Unicode Regional Indicator Symbol:
		     'A' => U+1F1E6, 'B' => U+1F1E7, ... 'Z' => U+1F1FF
		   Pairing two gives the flag: 'US' => U+1F1FA U+1F1F8
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
			updateApplyButtons();
			showSaveReminder();
		});

		updateRowHighlights();


		/* ================================================================
		   PRICING TABLE — Column sorting
		   ================================================================
		   Sortable columns: Country (alpha), Initial Payment (numeric),
		   Renewal Amount (numeric). Sort preference is stored in
		   localStorage so it persists across page loads and across
		   different membership level edit pages.
		*/

		var SORT_KEY = 'geoprice_table_sort';
		var currentSort = { column: 'country', direction: 'asc' };

		/* Load saved sort preference from localStorage. */
		try {
			var saved = localStorage.getItem(SORT_KEY);
			if (saved) {
				var parsed = JSON.parse(saved);
				if (parsed.column && parsed.direction) {
					currentSort = parsed;
				}
			}
		} catch (e) {
			/* localStorage unavailable or corrupt — use default. */
		}

		/**
		 * Sort the pricing table rows by the given column and direction.
		 *
		 * @param {string} column    One of 'country', 'initial', 'renewal'.
		 * @param {string} direction One of 'asc', 'desc'.
		 */
		function sortTable(column, direction) {
			var $rows = $tbody.find('tr').detach();

			$rows.sort(function(a, b) {
				var valA, valB;

				if (column === 'country') {
					/* Sort alphabetically by country name text. */
					valA = $(a).find('.geoprice-col-country strong').text().toLowerCase();
					valB = $(b).find('.geoprice-col-country strong').text().toLowerCase();
					return direction === 'asc'
						? valA.localeCompare(valB)
						: valB.localeCompare(valA);
				}

				/* Numeric sort for price columns. */
				var inputIndex = (column === 'initial') ? 0 : 1;
				var rawA = $(a).find('.geoprice-price-input').eq(inputIndex).val().trim();
				var rawB = $(b).find('.geoprice-price-input').eq(inputIndex).val().trim();

				/*
				 * Empty values (no price set) sort to the bottom in ascending
				 * order, and to the top in descending order. This keeps
				 * "configured" countries grouped together visually.
				 */
				var numA = rawA === '' ? null : parseFloat(rawA);
				var numB = rawB === '' ? null : parseFloat(rawB);

				if (numA === null && numB === null) return 0;
				if (numA === null) return direction === 'asc' ? 1 : -1;
				if (numB === null) return direction === 'asc' ? -1 : 1;

				return direction === 'asc' ? numA - numB : numB - numA;
			});

			$tbody.append($rows);
		}

		/**
		 * Update the visual state of sort arrows in the table header.
		 * Clears all arrows first, then sets the active one.
		 */
		function updateSortArrows() {
			$table.find('.geoprice-sort-arrow')
				.removeClass('geoprice-sort-asc geoprice-sort-desc');

			$table.find('th[data-sort="' + currentSort.column + '"] .geoprice-sort-arrow')
				.addClass(currentSort.direction === 'asc' ? 'geoprice-sort-asc' : 'geoprice-sort-desc');
		}

		/**
		 * Apply the current sort and save the preference.
		 * Called on init, after header clicks, and after add/remove operations.
		 */
		function applySort() {
			sortTable(currentSort.column, currentSort.direction);
			updateSortArrows();

			/* Persist to localStorage. */
			try {
				localStorage.setItem(SORT_KEY, JSON.stringify(currentSort));
			} catch (e) {
				/* localStorage full or unavailable — silently skip. */
			}
		}

		/* Header click handler — toggle sort direction or switch column. */
		$table.on('click', '.geoprice-sortable', function() {
			var col = $(this).data('sort');
			if (currentSort.column === col) {
				/* Same column: toggle direction. */
				currentSort.direction = (currentSort.direction === 'asc') ? 'desc' : 'asc';
			} else {
				/* New column: default to ascending. */
				currentSort.column = col;
				currentSort.direction = 'asc';
			}
			applySort();
		});

		/* Apply the initial sort on page load. */
		applySort();


		/* ================================================================
		   PPP — Purchasing Power Parity suggestions
		   ================================================================
		   When PPP data is available, each table row shows a multiplier
		   and an "Apply Suggested" button. The button fills both price
		   fields with: base_price × ppp_multiplier.
		*/

		/**
		 * Read the base prices from PMPro's Billing Details form fields.
		 * These are the level's default Initial Payment and Billing Amount.
		 */
		function getBasePrices() {
			return {
				initial: parseFloat($('input[name="initial_payment"]').val()) || 0,
				renewal: parseFloat($('input[name="billing_amount"]').val()) || 0
			};
		}

		/**
		 * Compute the PPP-suggested prices for a country.
		 * Returns null if PPP data is unavailable or base prices are zero.
		 */
		function getSuggestedPrices(code) {
			if (!hasPPP) return null;

			var mult = pppMultipliers[code];
			if (!mult || mult <= 0) return null;

			var base = getBasePrices();
			if (base.initial === 0 && base.renewal === 0) return null;

			return {
				initial: base.initial > 0 ? (base.initial * mult).toFixed(2) : '',
				renewal: base.renewal > 0 ? (base.renewal * mult).toFixed(2) : ''
			};
		}

		/**
		 * Update visibility of all "Apply Suggested" buttons.
		 *
		 * A button is hidden when:
		 *   - No PPP data exists for the country.
		 *   - Base prices are both zero (nothing to suggest).
		 *   - Both current prices already match the suggested values.
		 *   - PPP multiplier is ~1.0 and fields are empty (default = suggested).
		 */
		function updateApplyButtons() {
			if (!hasPPP) return;

			$tbody.find('tr[data-code]').each(function() {
				var $row = $(this);
				var code = $row.data('code');
				var $btn = $row.find('.geoprice-apply-btn');

				if (!$btn.length) return;

				var suggested = getSuggestedPrices(code);
				if (!suggested) {
					$btn.hide();
					return;
				}

				var currentInitial = $row.find('.geoprice-price-input').eq(0).val().trim();
				var currentRenewal = $row.find('.geoprice-price-input').eq(1).val().trim();

				/*
				 * If PPP is approximately 1.0 and fields are empty, the default
				 * price is already the "suggested" price. Hide the button.
				 */
				var mult = pppMultipliers[code] || 0;
				if (mult >= 0.98 && mult <= 1.02 && currentInitial === '' && currentRenewal === '') {
					$btn.hide();
					return;
				}

				/*
				 * If both current values match the suggested values, hide the
				 * button — the suggestion is already applied.
				 */
				var initialMatch = (suggested.initial === '' && currentInitial === '') ||
					currentInitial === suggested.initial;
				var renewalMatch = (suggested.renewal === '' && currentRenewal === '') ||
					currentRenewal === suggested.renewal;

				if (initialMatch && renewalMatch) {
					$btn.hide();
				} else {
					$btn.show();
				}
			});
		}

		/* Per-row "Apply Suggested" button click. */
		$tbody.on('click', '.geoprice-apply-btn', function(e) {
			e.preventDefault();
			var $row = $(this).closest('tr');
			var code = $row.data('code');
			var suggested = getSuggestedPrices(code);
			if (!suggested) return;

			if (suggested.initial !== '') {
				$row.find('.geoprice-price-input').eq(0).val(suggested.initial);
			}
			if (suggested.renewal !== '') {
				$row.find('.geoprice-price-input').eq(1).val(suggested.renewal);
			}

			updateRowHighlights();
			updateApplyButtons();
			showSaveReminder();
		});

		/* "Apply Suggested Pricing to All" button click. */
		$('#geoprice-apply-all-btn').on('click', function(e) {
			e.preventDefault();

			/* Confirm if any existing prices would be overwritten. */
			var hasExisting = false;
			$tbody.find('.geoprice-price-input').each(function() {
				if ($(this).val().trim() !== '') {
					hasExisting = true;
					return false;
				}
			});

			if (hasExisting) {
				if (!confirm('This will replace all existing prices with PPP-suggested values. Continue?')) {
					return;
				}
			}

			$tbody.find('tr[data-code]').each(function() {
				var $row = $(this);
				var code = $row.data('code');
				var suggested = getSuggestedPrices(code);
				if (!suggested) return;

				if (suggested.initial !== '') {
					$row.find('.geoprice-price-input').eq(0).val(suggested.initial);
				}
				if (suggested.renewal !== '') {
					$row.find('.geoprice-price-input').eq(1).val(suggested.renewal);
				}
			});

			updateRowHighlights();
			updateApplyButtons();
			showSaveReminder();
		});

		/*
		 * Re-check Apply Suggested visibility when the admin changes the
		 * base prices in PMPro's Billing Details section above.
		 */
		$('input[name="initial_payment"], input[name="billing_amount"]').on('input', function() {
			updateApplyButtons();
		});

		/* Initial visibility check on page load. */
		updateApplyButtons();


		/* ================================================================
		   PPP — "Learn More" info popup
		   ================================================================ */

		$('#geoprice-ppp-learn-more').on('click', function(e) {
			e.preventDefault();
			$pppOverlay.addClass('geoprice-ppp-info-visible');
		});

		$pppOverlay.on('click', '.geoprice-ppp-info-close', function(e) {
			e.preventDefault();
			$pppOverlay.removeClass('geoprice-ppp-info-visible');
		});

		$pppOverlay.on('click', function(e) {
			if (e.target === $pppOverlay[0]) {
				$pppOverlay.removeClass('geoprice-ppp-info-visible');
			}
		});

		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && $pppOverlay.hasClass('geoprice-ppp-info-visible')) {
				$pppOverlay.removeClass('geoprice-ppp-info-visible');
			}
		});


		/* ================================================================
		   PRICING TABLE — Remove country
		   ================================================================ */

		$tbody.on('click', '.geoprice-remove-btn', function(e) {
			e.preventDefault();
			var $tr = $(this).closest('tr');
			var removedCode = $tr.data('code');

			/*
			 * If either price field has a value, confirm before removing.
			 * This prevents accidental loss of configured pricing data.
			 */
			var hasPrices = false;
			$tr.find('.geoprice-price-input').each(function() {
				if ($(this).val().trim() !== '') {
					hasPrices = true;
					return false;
				}
			});

			if (hasPrices) {
				if (!confirm('This country has pricing set. Are you sure you want to remove it?')) {
					return;
				}
			}

			$tr.fadeOut(200, function() {
				$(this).remove();
				applySort();
				showSaveReminder();

				/*
				 * If the modal is open, un-grey the removed country's row
				 * in-place rather than re-rendering the entire list (which
				 * would reset scroll position).
				 */
				if ($overlay.hasClass('geoprice-modal-visible') && removedCode) {
					$list.find('.geoprice-modal-row.geoprice-already-added').each(function() {
						var $row = $(this);
						var $badge = $row.find('.geoprice-modal-added-badge');
						/* Match by checking if the row contains this country code. */
						if ($row.find('.geoprice-modal-row-code').text() === removedCode) {
							$row.removeClass('geoprice-already-added');
							$badge.replaceWith(
								'<button type="button" class="geoprice-modal-add-btn" data-code="' +
								escAttr(removedCode) + '">+ Add</button>'
							);
						}
					});
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

			var pppMult = pppMultipliers[code];
			var pppCell = '';

			if (hasPPP) {
				if (pppMult) {
					pppCell = '<td class="geoprice-col-ppp">' +
						'<span class="geoprice-ppp-value">' + pppMult.toFixed(2) + '\u00D7</span> ' +
						'<button type="button" class="geoprice-apply-btn" title="Apply PPP-suggested price">Apply Suggested</button>' +
					'</td>';
				} else {
					pppCell = '<td class="geoprice-col-ppp">' +
						'<span class="geoprice-ppp-na">\u2014</span>' +
					'</td>';
				}
			}

			var html = '<tr data-code="' + escAttr(code) + '">' +
				'<input type="hidden" name="geoprice_active_countries[]" value="' + escAttr(code) + '" />' +
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
				pppCell +
				'<td class="geoprice-col-actions">' +
					'<button type="button" class="button button-link-delete geoprice-remove-btn" title="Remove">' +
						'<span class="dashicons dashicons-no-alt"></span>' +
					'</button>' +
				'</td>' +
			'</tr>';

			var $newRow = $(html).hide();
			$tbody.append($newRow);
			applySort();
			$newRow.fadeIn(200);
			updateApplyButtons();
		}


		/* ================================================================
		   MODAL — Open / Close
		   ================================================================
		   We toggle visibility via CSS class instead of jQuery .fadeIn()
		   because jQuery sets display:block, which overrides our CSS
		   display:flex and breaks the centering layout.
		*/

		function openModal() {
			$search.val('');
			renderModalList();
			$overlay.addClass('geoprice-modal-visible');
			setTimeout(function() { $search.focus(); }, 50);
		}

		function closeModal() {
			$overlay.removeClass('geoprice-modal-visible');
		}

		$addBtn.on('click', function(e) {
			e.preventDefault();
			openModal();
		});

		$overlay.on('click', '.geoprice-modal-close', function(e) {
			e.preventDefault();
			closeModal();
		});

		/* Close on overlay background click (not the modal card itself). */
		$overlay.on('click', function(e) {
			if (e.target === $overlay[0]) {
				closeModal();
			}
		});

		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && $overlay.hasClass('geoprice-modal-visible')) {
				closeModal();
			}
		});


		/* ================================================================
		   MODAL — Controls
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
			var $btn = $(this);
			var code = $btn.data('code');
			addCountryRow(code);
			showSaveReminder();

			/*
			 * Instead of re-rendering the entire list (which resets scroll
			 * position), update just this row in-place to show the "Added"
			 * state. This keeps the user's scroll position exactly where it is.
			 */
			var $row = $btn.closest('.geoprice-modal-row');
			$row.addClass('geoprice-already-added');
			$btn.replaceWith('<span class="geoprice-modal-added-badge">&#10003; Added</span>');
		});

		/*
		 * Continent header click — toggle collapse of the group below.
		 * Uses slideToggle for a smooth animation rather than an abrupt show/hide.
		 */
		$list.on('click', '.geoprice-modal-continent-header', function() {
			var $header = $(this);
			var targetId = $header.data('target');
			var $group = $('#' + targetId);
			var $arrow = $header.find('.geoprice-continent-arrow');

			$group.slideToggle(200);
			$arrow.toggleClass('geoprice-collapsed');
		});


		/* ================================================================
		   MODAL — Render the country list
		   ================================================================ */

		function renderModalList() {
			var query   = ($search.val() || '').toLowerCase().trim();
			var sortBy  = $sort.val();
			var groupBy = $group.is(':checked');

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

			if (query) {
				entries = entries.filter(function(e) {
					return e.name.toLowerCase().indexOf(query) !== -1 ||
					       e.code.toLowerCase().indexOf(query) !== -1;
				});
			}

			if (sortBy === 'population') {
				entries.sort(function(a, b) {
					return b.population - a.population;
				});
			} else {
				entries.sort(function(a, b) {
					return a.name.localeCompare(b.name);
				});
			}

			var addedCodes = {};
			$tbody.find('tr[data-code]').each(function() {
				addedCodes[$(this).data('code')] = true;
			});

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
					var cid = 'geoprice-cg-' + continent.replace(/\s+/g, '-').toLowerCase();
					html += '<div class="geoprice-modal-continent-header" data-target="' + cid + '">' +
						'<span>' +
							escHtml(continent) +
							' <span style="opacity:0.5;font-weight:400;">(' + grouped[continent].length + ')</span>' +
						'</span>' +
						'<span class="geoprice-continent-arrow">&#9660;</span>' +
					'</div>';
					html += '<div class="geoprice-modal-continent-group" id="' + cid + '">';
					$.each(grouped[continent], function(_, e) {
						html += buildModalRow(e, addedCodes);
					});
					html += '</div>';
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
		 * Layout: [Flag] [Name / Code · Currency] [+ Add | checkmark Added]
		 */
		function buildModalRow(entry, addedCodes) {
			var isAdded = addedCodes[entry.code] || false;
			var cls = 'geoprice-modal-row' + (isAdded ? ' geoprice-already-added' : '');
			var flag = codeToFlag(entry.code);

			var h = '<div class="' + cls + '">';

			/* Flag */
			h += '<span class="geoprice-modal-row-flag">' + flag + '</span>';

			/* Info: name + meta */
			h += '<div class="geoprice-modal-row-info">';
			h += '<div class="geoprice-modal-row-name">' + escHtml(entry.name) + '</div>';
			h += '<div class="geoprice-modal-row-meta">' +
				'<span class="geoprice-modal-row-code">' + escHtml(entry.code) + '</span>' +
				'<span class="geoprice-sep">&middot;</span>' +
				'<span class="geoprice-modal-row-currency">' + escHtml(entry.currency) + '</span>' +
			'</div>';
			h += '</div>';

			/* Action */
			if (isAdded) {
				h += '<span class="geoprice-modal-added-badge">&#10003; Added</span>';
			} else {
				h += '<button type="button" class="geoprice-modal-add-btn" data-code="' + escAttr(entry.code) + '">+ Add</button>';
			}

			h += '</div>';
			return h;
		}


		/* ================================================================
		   UTILITY — HTML escaping
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
