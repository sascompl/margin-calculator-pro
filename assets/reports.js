jQuery(document).ready(function ($) {
	var reportData = null
	var reportCurrency = ''
	var currentSort = { key: null, dir: 'desc' }

	function decodeHtml(html) {
		var txt = document.createElement('textarea')
		txt.innerHTML = html
		return txt.value
	}

	function formatMoney(amount, symbol) {
		var formatted = parseFloat(amount)
			.toFixed(2)
			.replace(/\B(?=(\d{3})+(?!\d))/g, ' ')
		return formatted + ' ' + decodeHtml(symbol)
	}

	function renderRows(orders, sym) {
		var $tbody = $('#wcmc-report-tbody').empty()

		$.each(orders, function (i, row) {
			var marginCell, profitCell

			if (row.margin === null) {
				marginCell = '<td style="color:#999;">&#8212;</td>'
				profitCell = '<td style="color:#999;">&#8212;</td>'
			} else {
				var marginColor =
					row.margin >= 30
						? '#4CAF50'
						: row.margin >= 15
							? '#FB8C00'
							: '#C62828'
				var profitColor = row.profit >= 0 ? '#4CAF50' : '#C62828'
				marginCell =
					'<td style="color:' +
					marginColor +
					';font-weight:700;font-size:14px;">' +
					row.margin +
					'%</td>'
				profitCell =
					'<td style="color:' +
					profitColor +
					';font-weight:600;">' +
					formatMoney(row.profit, sym) +
					'</td>'
			}

			$tbody.append(
				'<tr>' +
					'<td><a href="' +
					row.edit_url +
					'">#' +
					row.order_number +
					'</a></td>' +
					'<td>' +
					row.date +
					'</td>' +
					'<td>' +
					$('<span>').text(row.customer).html() +
					'</td>' +
					'<td>' +
					formatMoney(row.revenue, sym) +
					'</td>' +
					'<td>' +
					(row.cost > 0
						? formatMoney(row.cost, sym)
						: '<span style="color:#999;">&#8212;</span>') +
					'</td>' +
					profitCell +
					marginCell +
					'</tr>'
			)
		})
	}

	function sortOrders(key) {
		if (!reportData) return

		if (currentSort.key === key) {
			currentSort.dir = currentSort.dir === 'desc' ? 'asc' : 'desc'
		} else {
			currentSort.key = key
			currentSort.dir = 'desc'
		}

		reportData.sort(function (a, b) {
			var valA = a[key] === null ? -Infinity : a[key]
			var valB = b[key] === null ? -Infinity : b[key]
			return currentSort.dir === 'desc' ? valB - valA : valA - valB
		})

		// Update sort indicators
		$('.wcmc-sortable').removeClass('wcmc-sort-asc wcmc-sort-desc')
		$('.wcmc-sortable[data-sort="' + key + '"]').addClass(
			currentSort.dir === 'desc' ? 'wcmc-sort-desc' : 'wcmc-sort-asc'
		)

		renderRows(reportData, reportCurrency)
	}

	function loadReport(dateFrom, dateTo) {
		$('#wcmc-report-results').hide()
		$('#wcmc-report-empty').hide()
		$('#wcmc-report-loading').show()
		reportData = null
		currentSort = { key: null, dir: 'desc' }
		$('.wcmc-sortable').removeClass('wcmc-sort-asc wcmc-sort-desc')

		$.ajax({
			url: wcmc.ajax_url,
			type: 'POST',
			data: {
				action: 'wcmc_get_report',
				nonce: wcmc.nonce,
				date_from: dateFrom,
				date_to: dateTo,
			},
			success: function (response) {
				$('#wcmc-report-loading').hide()

				if (!response.success) {
					$('#wcmc-report-empty')
						.text(response.data || 'Error loading report.')
						.show()
					return
				}

				if (response.data.total_orders === 0) {
					$('#wcmc-report-empty').show()
					return
				}

				var d = response.data
				var sym = d.currency

				reportData = d.orders
				reportCurrency = sym

				$('#wcmc-total-orders').text(d.total_orders)
				$('#wcmc-total-revenue').text(formatMoney(d.total_revenue, sym))
				$('#wcmc-total-cost').text(formatMoney(d.total_cost, sym))

				var $profit = $('#wcmc-total-profit')
				$profit
					.text(formatMoney(d.total_profit, sym))
					.removeClass('positive negative')
					.addClass(d.total_profit >= 0 ? 'positive' : 'negative')

				var $margin = $('#wcmc-avg-margin')
				$margin
					.text(d.avg_margin + '%')
					.removeClass('positive negative')
					.addClass(d.avg_margin >= 0 ? 'positive' : 'negative')

				renderRows(reportData, sym)
				$('#wcmc-report-results').show()
			},
			error: function (xhr, status, error) {
				$('#wcmc-report-loading').hide()
				$('#wcmc-report-empty')
					.text('Error: ' + (error || 'Connection failed'))
					.show()
			},
		})
	}

	// Sort on header click
	$(document).on('click', '.wcmc-sortable', function () {
		sortOrders($(this).data('sort'))
	})

	function getMonthRange(year, month) {
		var from = year + '-' + String(month).padStart(2, '0') + '-01'
		var lastDay = new Date(year, month, 0).getDate()
		var to =
			year +
			'-' +
			String(month).padStart(2, '0') +
			'-' +
			String(lastDay).padStart(2, '0')
		return { from: from, to: to }
	}

	// Quick filter: current month
	$('.wcmc-quick-filter[data-filter="current_month"]').on('click', function () {
		$('.wcmc-quick-filter').removeClass('active')
		$(this).addClass('active')

		var now = new Date()
		var range = getMonthRange(now.getFullYear(), now.getMonth() + 1)

		$('#wcmc-date-from').val(range.from)
		$('#wcmc-date-to').val(range.to)
		$('#wcmc-month').val(now.getMonth() + 1)
		$('#wcmc-year').val(now.getFullYear())

		loadReport(range.from, range.to)
	})

	// Quick filter: previous month
	$('.wcmc-quick-filter[data-filter="previous_month"]').on('click', function () {
		$('.wcmc-quick-filter').removeClass('active')
		$(this).addClass('active')

		var now = new Date()
		var prevMonth = now.getMonth() // 0-based = previous month
		var prevYear = now.getFullYear()
		if (prevMonth === 0) {
			prevMonth = 12
			prevYear--
		}

		var range = getMonthRange(prevYear, prevMonth)

		$('#wcmc-date-from').val(range.from)
		$('#wcmc-date-to').val(range.to)
		$('#wcmc-month').val(prevMonth)
		$('#wcmc-year').val(prevYear)

		loadReport(range.from, range.to)
	})

	// Apply month + year
	$('#wcmc-apply-month').on('click', function () {
		$('.wcmc-quick-filter').removeClass('active')

		var month = parseInt($('#wcmc-month').val())
		var year = parseInt($('#wcmc-year').val())
		var range = getMonthRange(year, month)

		$('#wcmc-date-from').val(range.from)
		$('#wcmc-date-to').val(range.to)

		loadReport(range.from, range.to)
	})

	// Apply custom date range
	$('#wcmc-apply-range').on('click', function () {
		$('.wcmc-quick-filter').removeClass('active')

		var from = $('#wcmc-date-from').val()
		var to = $('#wcmc-date-to').val()

		if (!from || !to) {
			alert('Please select both dates.')
			return
		}

		if (from > to) {
			alert('Start date must be before end date.')
			return
		}

		loadReport(from, to)
	})

	// Auto-load current month on page load
	$('.wcmc-quick-filter[data-filter="current_month"]').trigger('click')
})
