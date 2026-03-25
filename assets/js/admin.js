/**
 * GeoPrice for PMPro — Admin JavaScript
 *
 * @package   GeoPrice_For_PMPro
 * @copyright 2024-2026 GAVAMEDIA Corporation (https://gavamedia.com)
 * @license   GPL-2.0-or-later
 *
 * This script powers the interactive features of the country pricing table
 * on the PMPro membership level edit page. It handles:
 *
 *   1. SHOW/HIDE COUNTRIES:
 *      By default, only the top 20 countries (by population) are visible.
 *      The "Show All Countries" button reveals all ~195 countries.
 *      The "Show Top 20 Only" button hides them again (except rows with prices).
 *
 *   2. SEARCH/FILTER:
 *      A text input below the table filters countries by name in real-time.
 *      Typing "can" shows "Canada", typing "united" shows "United States",
 *      "United Kingdom", "United Arab Emirates". The filter searches ALL
 *      countries regardless of the show/hide state, so admins can find any
 *      country without expanding the full list.
 *
 *   3. ROW HIGHLIGHTING:
 *      Countries that have a price entered (in either field) get a green
 *      background via the 'geoprice-has-price' CSS class. This updates in
 *      real-time as the admin types, providing instant visual feedback about
 *      which countries have custom pricing configured.
 *
 * DEPENDENCIES:
 *   - jQuery (bundled with WordPress, always available in admin).
 *
 * CSS CLASSES USED (defined in admin.css):
 *   - .geoprice-top-country: Rows for the top 20 countries (always visible by default).
 *   - .geoprice-extra-country: Rows for all other countries (hidden by default).
 *   - .geoprice-has-price: Added dynamically to rows with non-empty price inputs.
 *   - .geoprice-price-input: The price <input> fields in each row.
 *
 * DOM ELEMENTS:
 *   - #geoprice-country-table: The main table containing all country rows.
 *   - #geoprice-show-more: "Show All Countries" button.
 *   - #geoprice-hide-more: "Show Top 20 Only" button (initially hidden).
 *   - #geoprice-filter: Country name search input.
 *
 * HOW VISIBILITY WORKS:
 *   Rows are shown/hidden using jQuery's .show()/.hide() methods, which set
 *   display:none inline. The initial visibility is set by PHP (admin-level-pricing.php)
 *   using inline style="display:none" on extra-country rows that have no saved prices.
 *
 *   The 'expanded' boolean tracks whether the full list is showing. When the user
 *   clicks "Show Top 20 Only", extra-country rows are hidden UNLESS they have the
 *   'geoprice-has-price' class. This prevents hiding a row where the admin just
 *   entered a price — they'd lose sight of their own work.
 */
(function($) {
	'use strict';

	$(function() {
		/* Cache DOM references for performance (avoid repeated lookups). */
		var $table     = $('#geoprice-country-table');  // The country pricing table.
		var $showMore  = $('#geoprice-show-more');      // "Show All Countries" button.
		var $hideMore  = $('#geoprice-hide-more');      // "Show Top 20 Only" button.
		var $filter    = $('#geoprice-filter');          // Country search input.
		var expanded   = false;                        // Tracks full-list visibility state.

		/**
		 * Update the green highlight on rows that have prices entered.
		 *
		 * Iterates through every row in the table body. For each row, checks
		 * whether ANY of its price input fields have a non-empty value. If so,
		 * adds the 'geoprice-has-price' class (green background in CSS). If
		 * all fields are empty, removes the class.
		 *
		 * WHEN THIS RUNS:
		 *   - Once on page load (initial state from saved data).
		 *   - On every keystroke in any price input field (real-time feedback).
		 */
		function updateRowHighlights() {
			$table.find('tbody tr').each(function() {
				var $row = $(this);
				var hasValue = false;

				/*
				 * Check each price input in this row. If any has a non-empty
				 * value, mark the row as having a price. 'return false' breaks
				 * out of the inner .each() loop early (optimization — no need
				 * to check the second input if the first has a value).
				 */
				$row.find('.geoprice-price-input').each(function() {
					if ($(this).val().trim() !== '') {
						hasValue = true;
						return false; // Break out of inner .each() loop.
					}
				});

				/* Add or remove the highlight class based on whether a price exists. */
				$row.toggleClass('geoprice-has-price', hasValue);
			});
		}

		/**
		 * "Show All Countries" button click handler.
		 *
		 * Reveals all hidden .geoprice-extra-country rows, swaps the button
		 * labels (show → hide), clears any active filter, and updates the
		 * expanded state flag.
		 */
		$showMore.on('click', function() {
			expanded = true;
			$table.find('.geoprice-extra-country').show(); // Reveal all hidden rows.
			$showMore.hide();                              // Hide "Show All" button.
			$hideMore.show();                              // Show "Show Top 20" button.
			$filter.val('').trigger('input');               // Clear any active filter.
		});

		/**
		 * "Show Top 20 Only" button click handler.
		 *
		 * Hides .geoprice-extra-country rows, but KEEPS rows that have prices
		 * entered visible. This prevents hiding the admin's own configured prices
		 * when they collapse the list.
		 */
		$hideMore.on('click', function() {
			expanded = false;
			$table.find('.geoprice-extra-country').each(function() {
				var $row = $(this);
				/*
				 * Only hide rows that DON'T have a price set.
				 * Rows with prices remain visible so the admin can still see
				 * all countries they've configured, even in the collapsed view.
				 */
				if (!$row.hasClass('geoprice-has-price')) {
					$row.hide();
				}
			});
			$hideMore.hide();     // Hide "Show Top 20" button.
			$showMore.show();     // Show "Show All" button.
			$filter.val('');      // Clear the filter input.
		});

		/**
		 * Country search/filter input handler.
		 *
		 * Fires on every keystroke (input event). Performs case-insensitive
		 * substring matching against the country name stored in each row's
		 * data-country attribute (set by PHP, e.g., data-country="canada").
		 *
		 * BEHAVIOR:
		 *   - Empty filter: reset to the current expand/collapse state.
		 *   - Non-empty filter: search ALL rows (including hidden extras)
		 *     and show only matches. This lets the admin find any country
		 *     by name without needing to click "Show All Countries" first.
		 */
		$filter.on('input', function() {
			var query = $(this).val().toLowerCase().trim();

			if (query === '') {
				/*
				 * Filter cleared — restore the current expand/collapse state.
				 * If expanded, show everything. If collapsed, show top 20
				 * plus any extra-country rows that have prices.
				 */
				if (expanded) {
					$table.find('tbody tr').show();
				} else {
					$table.find('.geoprice-top-country').show();
					$table.find('.geoprice-extra-country').each(function() {
						var $row = $(this);
						if (!$row.hasClass('geoprice-has-price')) {
							$row.hide();
						}
					});
				}
				return;
			}

			/*
			 * Active filter: iterate ALL rows and show/hide based on match.
			 * The data-country attribute contains the lowercase country name
			 * (e.g., "united states", "brazil", "germany"). We use indexOf
			 * for substring matching: typing "ger" matches "germany",
			 * "nig" matches both "niger" and "nigeria".
			 */
			$table.find('tbody tr').each(function() {
				var $row = $(this);
				var countryName = $row.data('country') || '';
				if (countryName.indexOf(query) !== -1) {
					$row.show();
				} else {
					$row.hide();
				}
			});
		});

		/**
		 * Price input change handler (delegated event).
		 *
		 * When the admin types in any price input field, update the row
		 * highlighting for all rows. Uses event delegation (bound to the table,
		 * listening for input events on .geoprice-price-input children) for
		 * efficiency — one handler instead of ~390 individual handlers.
		 */
		$table.on('input', '.geoprice-price-input', function() {
			updateRowHighlights();
		});

		/*
		 * Initial highlight pass on page load.
		 * If the level already has saved prices, those rows need to be
		 * highlighted green immediately (not just when the admin types).
		 */
		updateRowHighlights();
	});
})(jQuery);
