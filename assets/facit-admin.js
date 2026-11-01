/* Tidningen M – uppladdning av korsordsfacit (adminsidan och rutan på nummersidan). */
(function ($) {
	if (!window.METFACIT) return;

	function setStatus($slot, text, type) {
		$slot.find('.met-facit-status').text(text || '').attr('data-type', type || '');
	}

	function busy($slot, on) {
		$slot.toggleClass('is-busy', !!on).find('button, input').prop('disabled', !!on);
	}

	function done($slot, res) {
		busy($slot, false);
		if (!res || !res.success) {
			setStatus($slot, (res && res.data && res.data.message) || 'Något gick fel. Försök igen.', 'error');
			return;
		}
		$slot.find('.met-facit-preview').html(res.data.preview);
		$slot.toggleClass('has-facit', res.data.has);
		$slot.find('.met-facit-remove').prop('hidden', !res.data.has);
		$slot.find('.met-facit-upload-label').text(res.data.has ? 'Byt facit' : 'Ladda upp facit');
		setStatus($slot, res.data.message, 'success');
	}

	function post(data) {
		var fd = new FormData();
		fd.append('nonce', METFACIT.nonce);
		Object.keys(data).forEach(function (k) {
			if (data[k] instanceof Blob) fd.append(k, data[k], data[k].name || 'facit.jpg');
			else fd.append(k, data[k]);
		});
		return fetch(METFACIT.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); });
	}

	/* PDF → bild: första sidan renderas i webbläsaren, vit kant beskärs bort. */
	function pdfToJpeg(source) {
		var lib = window.pdfjsLib;
		if (!lib) return Promise.reject(new Error('PDF-stödet kunde inte laddas.'));
		lib.GlobalWorkerOptions.workerSrc = METFACIT.pdfWorker;
		var bytes = source instanceof Blob
			? source.arrayBuffer()
			: fetch(source, { credentials: 'same-origin' }).then(function (r) {
				if (!r.ok) throw new Error('PDF-filen kunde inte hämtas.');
				return r.arrayBuffer();
			});
		return bytes
			.then(function (data) { return lib.getDocument({ data: data }).promise; })
			.then(function (pdf) { return pdf.getPage(1); })
			.then(function (page) {
				var base = page.getViewport({ scale: 1 });
				var scale = 2200 / Math.max(base.width, base.height);
				var vp = page.getViewport({ scale: scale });
				var canvas = document.createElement('canvas');
				canvas.width = Math.round(vp.width);
				canvas.height = Math.round(vp.height);
				var ctx = canvas.getContext('2d');
				ctx.fillStyle = '#fff';
				ctx.fillRect(0, 0, canvas.width, canvas.height);
				return page.render({ canvasContext: ctx, viewport: vp }).promise.then(function () {
					return trim(canvas);
				});
			})
			.then(function (canvas) {
				return new Promise(function (resolve, reject) {
					canvas.toBlob(function (b) { b ? resolve(b) : reject(new Error('Bilden kunde inte skapas.')); }, 'image/jpeg', 0.9);
				});
			});
	}

	// Beskär tom vit marginal runt facit och lämnar lite luft kvar.
	function trim(canvas) {
		var w = canvas.width, h = canvas.height;
		var px = canvas.getContext('2d').getImageData(0, 0, w, h).data;
		var top = h, left = w, right = -1, bottom = -1;
		for (var y = 0; y < h; y++) {
			for (var x = 0; x < w; x++) {
				var i = (y * w + x) * 4;
				if (px[i] < 235 || px[i + 1] < 235 || px[i + 2] < 235) {
					if (x < left) left = x;
					if (x > right) right = x;
					if (y < top) top = y;
					if (y > bottom) bottom = y;
				}
			}
		}
		if (right < 0) return canvas; // Helt tom sida – lämna orörd.
		var pad = Math.round(Math.max(w, h) * 0.02);
		left = Math.max(0, left - pad); top = Math.max(0, top - pad);
		right = Math.min(w - 1, right + pad); bottom = Math.min(h - 1, bottom + pad);
		var cw = right - left + 1, ch = bottom - top + 1;
		if (cw > w * 0.97 && ch > h * 0.97) return canvas;
		var out = document.createElement('canvas');
		out.width = cw; out.height = ch;
		out.getContext('2d').drawImage(canvas, left, top, cw, ch, 0, 0, cw, ch);
		return out;
	}

	function isPdf(file) {
		return file && (file.type === 'application/pdf' || /\.pdf$/i.test(file.name || ''));
	}

	function uploadFile($slot, file) {
		if (!file) return;
		var ok = isPdf(file) || /^image\/(jpeg|png|webp|gif)$/.test(file.type);
		if (!ok) {
			setStatus($slot, 'Välj en bild (JPG eller PNG) eller en PDF.', 'error');
			return;
		}
		busy($slot, true);
		var step = isPdf(file)
			? (setStatus($slot, 'Gör om PDF till bild …'), pdfToJpeg(file))
			: Promise.resolve(file);
		step
			.then(function (blob) {
				setStatus($slot, 'Laddar upp …');
				if (!blob.name) blob.name = 'facit.jpg';
				return post({ action: 'met_facit_upload', post_id: $slot.data('post'), file: blob });
			})
			.then(function (res) { done($slot, res); })
			.catch(function (e) { busy($slot, false); setStatus($slot, e.message || 'Något gick fel.', 'error'); });
	}

	$(document).on('change', '.met-facit-slot input[type=file]', function () {
		var $slot = $(this).closest('.met-facit-slot');
		uploadFile($slot, this.files && this.files[0]);
		this.value = '';
	});

	// Dra och släpp.
	$(document).on('dragover dragenter', '.met-facit-slot', function (e) {
		e.preventDefault();
		$(this).addClass('is-dragover');
	});
	$(document).on('dragleave dragend drop', '.met-facit-slot', function () {
		$(this).removeClass('is-dragover');
	});
	$(document).on('drop', '.met-facit-slot', function (e) {
		e.preventDefault();
		var dt = e.originalEvent.dataTransfer;
		if (dt && dt.files && dt.files.length && !$(this).hasClass('is-busy')) uploadFile($(this), dt.files[0]);
	});

	// Välj en befintlig fil i mediabiblioteket.
	$(document).on('click', '.met-facit-library', function () {
		var $slot = $(this).closest('.met-facit-slot');
		var frame = wp.media({
			title: 'Välj facit för ' + $slot.data('title'),
			library: { type: ['image', 'application/pdf'] },
			button: { text: 'Använd som facit' },
			multiple: false
		});
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			busy($slot, true);
			if (att.mime === 'application/pdf') {
				setStatus($slot, 'Gör om PDF till bild …');
				pdfToJpeg(att.url)
					.then(function (blob) {
						setStatus($slot, 'Laddar upp …');
						blob.name = 'facit.jpg';
						return post({ action: 'met_facit_upload', post_id: $slot.data('post'), source_pdf: att.id, file: blob });
					})
					.then(function (res) { done($slot, res); })
					.catch(function (e) { busy($slot, false); setStatus($slot, e.message || 'Något gick fel.', 'error'); });
			} else {
				post({ action: 'met_facit_set', post_id: $slot.data('post'), attachment_id: att.id })
					.then(function (res) { done($slot, res); })
					.catch(function () { busy($slot, false); setStatus($slot, 'Något gick fel.', 'error'); });
			}
		});
		frame.open();
	});

	$(document).on('click', '.met-facit-remove', function () {
		var $slot = $(this).closest('.met-facit-slot');
		if (!window.confirm('Ta bort facit för ' + $slot.data('title') + ' från sajten? Bilden ligger kvar i mediabiblioteket.')) return;
		busy($slot, true);
		post({ action: 'met_facit_remove', post_id: $slot.data('post') })
			.then(function (res) { done($slot, res); })
			.catch(function () { busy($slot, false); setStatus($slot, 'Något gick fel.', 'error'); });
	});
})(jQuery);
