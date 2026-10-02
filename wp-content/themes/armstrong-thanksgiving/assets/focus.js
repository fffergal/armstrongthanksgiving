(function () {
	function initKeyboardFocus() {
		var body = document.body;
		if (!body) {
			return;
		}

		var keyboardNavigationKeys = ['Tab', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];
		document.addEventListener('keydown', function (event) {
			if (keyboardNavigationKeys.indexOf(event.key) !== -1) {
				body.classList.add('at-keyboard-focus');
			}
		}, true);
		document.addEventListener('pointerdown', function () {
			body.classList.remove('at-keyboard-focus');
		}, true);
		body.classList.add('at-keyboard-focus');
		body.classList.add('at-focus-script-ready');
	}

	if (document.body) {
		initKeyboardFocus();
	} else {
		document.addEventListener('DOMContentLoaded', initKeyboardFocus);
	}
}());
