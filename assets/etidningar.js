/* Öppnar ISSUU-läsaren i en ruta när griddet använder oppna="ruta". */
(function () {
	document.addEventListener('click', function (e) {
		var link = e.target.closest && e.target.closest('a[data-met-embed]');
		var modal = document.querySelector('.met-modal');
		if (link && modal && link.getAttribute('data-met-embed')) {
			if (e.metaKey || e.ctrlKey || e.shiftKey) return; // låt ny flik fungera
			e.preventDefault();
			modal.querySelector('iframe').src = link.getAttribute('data-met-embed');
			modal.hidden = false;
			document.body.classList.add('met-modal-open');
			modal.querySelector('.met-modal__close').focus();
			modal._opener = link;
			return;
		}
		if (modal && !modal.hidden && (e.target === modal || e.target.closest('.met-modal__close'))) close(modal);
	});
	document.addEventListener('keydown', function (e) {
		var modal = document.querySelector('.met-modal');
		if (e.key === 'Escape' && modal && !modal.hidden) close(modal);
	});
	function close(modal) {
		modal.hidden = true;
		modal.querySelector('iframe').src = 'about:blank';
		document.body.classList.remove('met-modal-open');
		if (modal._opener) modal._opener.focus();
	}
})();
