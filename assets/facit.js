/* Tidningen M – förstorar facit i rutnätet. Pilar/piltangenter bläddrar, Esc eller klick utanför stänger. */
(function () {
	var box = document.querySelector('.met-facit-lightbox');
	if (!box) return;
	var items = Array.prototype.slice.call(document.querySelectorAll('.met-facit-item'));
	var img = box.querySelector('.met-facit-lightbox__img');
	var caption = box.querySelector('.met-facit-lightbox__caption');
	var prev = box.querySelector('.met-facit-lightbox__prev');
	var next = box.querySelector('.met-facit-lightbox__next');
	var close = box.querySelector('.met-facit-lightbox__close');
	var current = -1, opener = null;

	function show(i) {
		if (i < 0 || i >= items.length) return;
		current = i;
		var item = items[i];
		var full = item.getAttribute('data-full');
		var thumb = item.querySelector('img');
		// Visa den redan laddade rutnätsbilden direkt och byt till full storlek när den är klar.
		img.src = (thumb && thumb.currentSrc) || full;
		if (img.src !== full) {
			var hi = new Image();
			hi.onload = function () { if (current === i) img.src = full; };
			hi.src = full;
		}
		img.alt = 'Facit till korsordet i ' + item.getAttribute('data-title');
		caption.textContent = item.getAttribute('data-title');
		prev.hidden = i === 0;
		next.hidden = i === items.length - 1;
		// Ladda grannarna i förväg så att bläddringen går snabbt.
		[i - 1, i + 1].forEach(function (n) {
			if (items[n]) (new Image()).src = items[n].getAttribute('data-full');
		});
	}

	function open(i) {
		opener = items[i];
		show(i);
		box.hidden = false;
		document.body.classList.add('met-facit-open');
		close.focus();
	}

	function shut() {
		box.hidden = true;
		img.removeAttribute('src');
		document.body.classList.remove('met-facit-open');
		if (opener) opener.focus();
	}

	items.forEach(function (item, i) {
		item.addEventListener('click', function () { open(i); });
	});
	prev.addEventListener('click', function (e) { e.stopPropagation(); show(current - 1); });
	next.addEventListener('click', function (e) { e.stopPropagation(); show(current + 1); });
	close.addEventListener('click', function (e) { e.stopPropagation(); shut(); });
	box.addEventListener('click', function (e) {
		if (!e.target.closest('.met-facit-lightbox__figure') || e.target === box) shut();
	});
	document.addEventListener('keydown', function (e) {
		if (box.hidden) return;
		if (e.key === 'Escape') shut();
		else if (e.key === 'ArrowLeft') show(current - 1);
		else if (e.key === 'ArrowRight') show(current + 1);
		else if (e.key === 'Tab') {
			// Håll fokus inne i förstoringen.
			var f = [close, prev, next].filter(function (b) { return !b.hidden; });
			var idx = f.indexOf(document.activeElement);
			e.preventDefault();
			f[(idx + (e.shiftKey ? f.length - 1 : 1)) % f.length].focus();
		}
	});
})();
