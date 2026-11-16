(() => {
	const invariantUnits = /^(kg|g|mg|lb|oz|l|ml|cl|dl|tsp|tbsp)$/i;
	const esEndings = /(bus|gas|alias|canvas|status|virus)$/i;

	function pluralize(unit) {
		return unit.replace(/[^\s]+$/u, word => {
			if (invariantUnits.test(word)) return word;
			if (/ss$/i.test(word)) return `${word}es`;
			if (esEndings.test(word)) return `${word}es`;
			if (/s$/i.test(word)) return word;
			if (/[^aeiou]y$/i.test(word)) return `${word.slice(0, -1)}ies`;
			if (/(s|x|z|ch|sh)$/i.test(word)) return `${word}es`;
			return `${word}s`;
		});
	}

	document.querySelectorAll('.at-food-plural-preview').forEach(preview => {
		const unitInput = document.getElementById(preview.dataset.unitInput);
		const output = preview.querySelector('span');
		if (!unitInput || !output) return;

		const updatePreview = () => {
			const unit = unitInput.value.trim();
			output.textContent = unit ? pluralize(unit) : 'Enter a unit or size';
		};

		unitInput.addEventListener('input', updatePreview);
		updatePreview();
	});
})();
