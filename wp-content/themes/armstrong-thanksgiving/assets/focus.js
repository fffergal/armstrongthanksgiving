(function () {
	function initKeyboardFocus() {
		var body = document.body;
		if (!body) {
			return;
		}

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Tab') {
				body.classList.add('at-keyboard-focus');
			}
		}, true);
		document.addEventListener('pointerdown', function () {
			body.classList.remove('at-keyboard-focus');
		}, true);
	}

	if (document.body) {
		initKeyboardFocus();
	} else {
		document.addEventListener('DOMContentLoaded', initKeyboardFocus);
	}
}());
