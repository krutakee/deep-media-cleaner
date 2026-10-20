/* global DMC, jQuery */
(function ($) {
	'use strict';

	var $notice = $('#dmc-notice');

	function post(action, data) {
		return $.post(DMC.ajax, $.extend({ action: action, nonce: DMC.nonce }, data || {}));
	}

	function notice(html, type) {
		$notice.html('<div class="notice notice-' + (type || 'info') + ' is-dismissible"><p>' + html + '</p></div>');
		$('html, body').animate({ scrollTop: 0 }, 150);
	}

	function esc(s) {
		return $('<div>').text(String(s)).html();
	}

	function report(res, reload) {
		var d = (res && res.data) || {};
		if (!res || !res.success) {
			notice(esc(d.message || DMC.i18n.error), 'error');
			return;
		}
		var msgs = d.messages || [];
		var html = esc(d.ok || 0) + ' ✓';
		if (msgs.length) {
			html += '<br>' + msgs.map(esc).join('<br>');
		}
		notice(html, msgs.length ? 'warning' : 'success');
		if (reload) {
			setTimeout(function () { window.location.reload(); }, msgs.length ? 2500 : 700);
		}
	}

	function selected() {
		return $('.dmc-cb:checked').map(function () { return this.value; }).get();
	}

	function busy(on) {
		$('.dmc-wrap button, .dmc-wrap .button').prop('disabled', !!on);
		$('body').toggleClass('dmc-busy', !!on);
	}

	/* ---------- Scan ---------- */

	var $progress = $('#dmc-progress');

	function showProgress(d) {
		$progress.prop('hidden', false);
		$progress.find('.dmc-bar span').css('width', (d.pct || 0) + '%');
		$progress.find('.dmc-step-label').text(d.label || DMC.i18n.working);
		$progress.find('.dmc-step-count').text(d.step ? '(' + d.step + ')' : '');
		$progress.find('.dmc-step-note').text(d.note || '');
	}

	var cancelled = false;
	var retries = 0;

	function loop(op) {
		post('dmc_scan', { op: op }).done(function (res) {
			retries = 0;
			if (!res || !res.success) {
				notice(esc((res && res.data && res.data.message) || DMC.i18n.error), 'error');
				$('#dmc-scan').prop('disabled', false);
				return;
			}
			showProgress(res.data);
			if (cancelled) { return; }
			if (res.data.running) {
				loop('step');
			} else {
				window.location.href = DMC.url;
			}
		}).fail(function () {
			if (cancelled) { return; }
			// Host hiccup / timeout: back off and retry a few times. The scan
			// state is saved server-side, so nothing is lost.
			retries++;
			if (retries > 5) {
				notice(esc(DMC.i18n.scanFailed), 'error');
				$('#dmc-scan').prop('disabled', false);
				return;
			}
			$progress.find('.dmc-step-note').text(DMC.i18n.retrying + ' (' + retries + '/5)…');
			setTimeout(function () { if (!cancelled) { loop(op === 'start' ? 'step' : op); } }, 3000 * retries);
		});
	}

	$('#dmc-scan').on('click', function () {
		$(this).prop('disabled', true);
		cancelled = false;
		showProgress({ pct: 0 });
		loop('start');
	});

	$('#dmc-cancel').on('click', function () {
		cancelled = true;
		post('dmc_scan', { op: 'cancel' }).always(function () { window.location.reload(); });
	});

	if (DMC.running) {
		showProgress({ pct: 0 });
		loop('step');
	}

	/* ---------- Selection ---------- */

	$('.dmc-check-all').on('change', function () {
		$('.dmc-cb').prop('checked', this.checked);
	});

	/* ---------- Server error details ---------- */

	// Only network problems / timeouts / gateway errors are worth retrying.
	function retryable(xhr) {
		return !xhr || xhr.status === 0 || xhr.status === 502 || xhr.status === 503 || xhr.status === 504 || xhr.statusText === 'timeout';
	}

	function failText(xhr) {
		var body = (xhr && xhr.responseText) || '';
		try {
			var j = JSON.parse(body.slice(body.indexOf('{')));
			if (j && j.data && j.data.message) { return j.data.message; }
		} catch (e) { /* not JSON */ }
		var text = $('<div>').html(body).text().replace(/\s+/g, ' ').trim().slice(0, 400);
		return DMC.i18n.serverError.replace('%s', xhr ? xhr.status : '?') + ' ' + (text || DMC.i18n.error);
	}

	/* ---------- Progress panel for long jobs ---------- */

	var job = null;

	function fmtTime(sec) {
		sec = Math.max(0, Math.round(sec));
		var m = Math.floor(sec / 60), s = sec % 60;
		return m ? m + DMC.i18n.min + ' ' + s + DMC.i18n.sec : s + DMC.i18n.sec;
	}

	function fmtNum(n) {
		return Number(n).toLocaleString();
	}

	function jobStart(op, total) {
		job = { op: op, total: total, done: 0, failed: 0, t0: Date.now(), stop: false };
		$('.dmc-job').remove();
		$('body').append(
			'<div class="dmc-job" role="status" aria-live="polite"><div class="dmc-job-box">' +
			'<p class="dmc-job-title"></p><div class="dmc-bar"><span></span></div>' +
			'<p class="dmc-job-count"></p><p class="dmc-job-eta"></p>' +
			'<p><button type="button" class="button dmc-job-stop"></button></p></div></div>'
		);
		$('.dmc-job-title').text((DMC.i18n.titles && DMC.i18n.titles[op]) || DMC.i18n.working);
		$('.dmc-job-stop').text(DMC.i18n.stop).on('click', function () {
			job.stop = true;
			$(this).prop('disabled', true).text(DMC.i18n.stopping);
		});
		busy(true);
		$(window).on('beforeunload.dmc', function () { return DMC.i18n.leaveWarning; });
		job.timer = setInterval(jobRender, 1000);
		jobRender();
	}

	function jobUpdate(done, failed, total) {
		job.done = done;
		job.failed = failed;
		if (typeof total === 'number') { job.total = total; }
		job.total = Math.max(job.total, done + failed);
		jobRender();
	}

	function jobRender() {
		if (!job) { return; }
		var processed = job.done + job.failed;
		var pct = job.total ? Math.min(100, Math.floor(100 * processed / job.total)) : 0;
		var elapsed = (Date.now() - job.t0) / 1000;
		$('.dmc-job .dmc-bar span').css('width', pct + '%');
		$('.dmc-job-count').text(
			fmtNum(processed) + ' / ' + fmtNum(job.total) + ' (' + pct + '%)' +
			(job.failed ? ' · ' + fmtNum(job.failed) + ' ' + DMC.i18n.skipped : '')
		);
		var eta = processed > 0 && processed < job.total
			? DMC.i18n.timeLeft.replace('%s', fmtTime(elapsed / processed * (job.total - processed)))
			: (processed === 0 ? DMC.i18n.calculating : '');
		$('.dmc-job-eta').text((eta ? eta + ' · ' : '') + DMC.i18n.elapsed.replace('%s', fmtTime(elapsed)));
	}

	function jobEnd(ok, messages) {
		var stopped = job && job.stop;
		var op = job && job.op;
		var skipped = job ? job.failed : 0;
		clearInterval(job && job.timer);
		$(window).off('beforeunload.dmc');
		job = null;

		// Nothing to report: close and refresh as before.
		if (!stopped && !messages.length) {
			$('.dmc-job').remove();
			busy(false);
			report({ success: true, data: { ok: ok, messages: [] } }, true);
			return;
		}

		// Otherwise keep the panel open as a summary until the user closes it.
		var $box = $('.dmc-job-box').addClass('dmc-job-summary');
		$box.find('.dmc-job-title').text(stopped ? DMC.i18n.stoppedTitle : DMC.i18n.finished);
		var lineKey = /^preview/.test(op) ? 'preview' : (/^restore/.test(op) ? 'restore' : (/^(delete|empty)/.test(op) ? 'delete' : (/^trash/.test(op) ? 'trash' : '')));
		var line = (lineKey && DMC.i18n.doneLines && DMC.i18n.doneLines[lineKey]) || DMC.i18n.doneLine;
		$box.find('.dmc-job-count').text(line.replace('%1$s', fmtNum(ok)).replace('%2$s', fmtNum(skipped)));
		$box.find('.dmc-job-eta').text(stopped ? DMC.i18n.stoppedEarly : '');
		$box.find('.dmc-job-stop').closest('p').remove();

		if (messages.length) {
			var order = ['in_use', 'error', 'moved_note', 'removed', 'left', 'would', 'keep', 'info'];
			var groups = {};
			messages.forEach(function (m) {
				var k = (m && typeof m === 'object' && m.kind) ? m.kind : (m && typeof m === 'object' ? 'info' : 'error');
				(groups[k] = groups[k] || []).push(m);
			});
			order.forEach(function (k) {
				if (!groups[k]) { return; }
				var label = (DMC.i18n.groups && DMC.i18n.groups[k]) || DMC.i18n.detailsHead;
				$box.append($('<p class="dmc-job-listhead">').addClass('dmc-g-' + k).text(label + ' (' + groups[k].length + ')'));
				var $ul = $('<ul class="dmc-job-list">');
				groups[k].forEach(function (m) {
					var $li = $('<li>');
					if (m && typeof m === 'object') {
						// html is built and escaped server-side (DMC_Reason::html).
						$li.append($('<strong>').text(m.name || '')).append($('<span>').html(m.html || esc(m.text || '')));
					} else {
						$li.append($('<span>').text(String(m)));
					}
					$ul.append($li);
				});
				$box.append($ul);
			});
			if (groups.in_use) {
				$box.append($('<p class="dmc-job-hint">').text(DMC.i18n.inUseHint));
			}
		}

		var $btns = $('<p class="dmc-job-btns">');
		$btns.append($('<button type="button" class="button button-primary">').text(DMC.i18n.closeRefresh).on('click', function () {
			window.location.reload();
		}));
		if (messages.some(function (m) { return m && m.kind === 'in_use'; })) {
			$btns.append(' ').append($('<a class="button">').attr('href', DMC.url + '&view=used').text(DMC.i18n.showUsed));
		}
		if (messages.length && navigator.clipboard) {
			$btns.append(' ').append($('<button type="button" class="button-link">').text(DMC.i18n.copyList).on('click', function () {
				var $b = $(this);
				navigator.clipboard.writeText(messages.map(function (m) {
					return (m && typeof m === 'object') ? (m.name + ' — ' + m.text) : String(m);
				}).join('\n')).then(function () { $b.text(DMC.i18n.copied); });
			}));
		}
		$box.append($btns);
		$btns.find('.button-primary').trigger('focus');
	}

	/* ---------- Selected items / row actions (sent in small batches) ---------- */

	function runOp(op, ids) {
		if (!ids.length) { notice(esc(DMC.i18n.noneSelected), 'warning'); return; }
		if (op === 'delete' && !window.confirm(DMC.i18n.confirmDelete)) { return; }
		if (op === 'trash_force' && !window.confirm(DMC.i18n.confirmForce)) { return; }
		if (op === 'trash_missing' && !window.confirm(DMC.i18n.confirmMissing)) { return; }
		if (op === 'trash_missing_unlink' && !window.confirm(DMC.i18n.confirmUnlink)) { return; }
		var size = (op === 'ignore' || op === 'unignore') ? 200 : 5;
		var i = 0, ok = 0, failed = 0, messages = [], netFails = 0;
		jobStart(op, ids.length);

		function next() {
			if (job.stop || i >= ids.length) { jobEnd(ok, messages); return; }
			var part = ids.slice(i, i + size);
			post('dmc_action', { op: op, ids: part }).done(function (res) {
				netFails = 0;
				i += part.length;
				if (res && res.debug) { messages.push(DMC.i18n.phpNotices + ' ' + res.debug); }
				if (!res || !res.success) {
					failed += part.length;
					messages.push((res && res.data && res.data.message) || DMC.i18n.error);
				} else {
					ok += res.data.ok || 0;
					failed += Math.max(0, part.length - (res.data.ok || 0));
					messages = messages.concat(res.data.messages || []);
				}
				jobUpdate(ok, failed);
				next();
			}).fail(function (xhr) {
				// Retry the same batch only for network problems / timeouts.
				if (retryable(xhr) && ++netFails <= 3) { setTimeout(next, 2000 * netFails); return; }
				failed += part.length;
				jobUpdate(ok, failed);
				messages.push(failText(xhr));
				jobEnd(ok, messages);
			});
		}
		next();
	}

	$('[data-dmc-bulk]').on('click', function () {
		runOp($(this).data('dmc-bulk'), selected());
	});

	$(document).on('click', '[data-dmc-row]', function (e) {
		e.preventDefault();
		runOp($(this).data('dmc-row'), [$(this).closest('tr').data('id')]);
	});

	/* ---------- "All" operations (server picks the next batch) ---------- */

	function batch(op, total, extra) {
		var done = 0, skip = [], messages = [], netFails = 0;
		jobStart(op, total || 0);

		function next() {
			if (job.stop) { jobEnd(done, messages); return; }
			post('dmc_action', $.extend({ op: op, skip: skip, limit: 5 }, extra || {})).done(function (res) {
				netFails = 0;
				if (!res || !res.success) {
					messages.push((res && res.data && res.data.message) || DMC.i18n.error);
					jobEnd(done, messages);
					return;
				}
				var d = res.data;
				if (res.debug) { messages.push(DMC.i18n.phpNotices + ' ' + res.debug); }
				done += d.ok || 0;
				skip = skip.concat(d.failed || []);
				messages = messages.concat(d.messages || []);
				jobUpdate(done, skip.length, done + skip.length + (d.remaining || 0));
				if (d.remaining > 0 && ((d.ok || 0) > 0 || (d.failed || []).length > 0)) {
					next();
				} else {
					jobEnd(done, messages);
				}
			}).fail(function (xhr) {
				if (retryable(xhr) && ++netFails <= 3) { setTimeout(next, 2000 * netFails); return; }
				messages.push(failText(xhr));
				jobEnd(done, messages);
			});
		}
		next();
	}

	$('#dmc-trash-all').on('click', function () {
		if (window.confirm(DMC.i18n.confirmAll)) { batch('trash_all', +$(this).data('total'), { type: $(this).data('type') || '' }); }
	});
	$('#dmc-trash-missing-all').on('click', function () {
		if (window.confirm(DMC.i18n.confirmMissing)) { batch('trash_missing_all', +$(this).data('total')); }
	});
	$('#dmc-unlink-missing-all').on('click', function () {
		if (window.confirm(DMC.i18n.confirmUnlink)) { batch('trash_missing_unlink_all', +$(this).data('total')); }
	});
	$('#dmc-empty').on('click', function () {
		if (window.confirm(DMC.i18n.confirmEmpty)) { batch('empty', +$(this).data('total')); }
	});
	$('#dmc-restore-all').on('click', function () {
		batch('restore_all', +$(this).data('total'));
	});
})(jQuery);
