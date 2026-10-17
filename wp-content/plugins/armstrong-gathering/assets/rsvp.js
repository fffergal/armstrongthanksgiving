(function () {
  'use strict';

  var form = document.querySelector('.at-rsvp-form');
  if (!form) return;

  var roster = form.querySelector('.at-party-roster');
  if (roster) {
    var choices = Array.from(roster.querySelectorAll('input[type="checkbox"]'));
    var selected = document.createElement('div');
    selected.className = 'at-party-selected';
    var search = document.createElement('input');
    search.type = 'text';
    search.id = 'at-party-search';
    search.placeholder = 'Search or browse names';
    search.setAttribute('role', 'combobox');
    search.setAttribute('aria-label', 'Add an adult');
    search.setAttribute('aria-autocomplete', 'list');
    search.setAttribute('aria-controls', 'at-party-options');
    search.setAttribute('autocomplete', 'off');
    var list = document.createElement('div');
    list.id = 'at-party-options';
    list.className = 'at-party-options';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', 'Adults to add');
    var message = document.createElement('p');
    message.className = 'at-field-help';
    message.setAttribute('role', 'status');
    var active = -1;
    var matches = [];
    var touchStart = null;
    choices.forEach(function (choice) { choice.parentElement.hidden = true; });
    roster.classList.add('at-party-enhanced');
    roster.append(selected, search, list, message);

    function closeOptions() {
      list.hidden = true;
      search.setAttribute('aria-expanded', 'false');
      search.removeAttribute('aria-activedescendant');
      active = -1;
    }
    function renderSelected() {
      selected.replaceChildren();
      choices.filter(function (choice) { return choice.checked; }).forEach(function (choice) {
        var chip = document.createElement(choice.disabled ? 'span' : 'button');
        chip.className = 'at-party-chip';
        chip.textContent = choice.parentElement.textContent.trim();
        if (!choice.disabled) {
          chip.type = 'button';
          chip.textContent += ' ×';
          chip.setAttribute('aria-label', 'Remove ' + choice.parentElement.textContent.trim());
          chip.addEventListener('click', function () {
            choice.checked = false;
            choice.dispatchEvent(new Event('change', { bubbles: true }));
            renderSelected();
            search.focus();
          });
        }
        selected.append(chip);
      });
    }
    function renderOptions() {
      active = -1;
      search.removeAttribute('aria-activedescendant');
      matches = choices.filter(function (choice) {
        return !choice.checked && choice.parentElement.textContent.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase());
      });
      list.replaceChildren();
      matches.forEach(function (choice) {
        var option = document.createElement('div');
        option.id = 'at-party-option-' + choice.value;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-disabled', String(choice.disabled));
        option.setAttribute('aria-selected', 'false');
        if (choice.disabled) option.className = 'is-unavailable';
        option.textContent = choice.parentElement.textContent.trim();
        if (!choice.disabled) {
          option.addEventListener('pointerdown', function (event) {
            if (event.pointerType === 'mouse') {
              event.preventDefault();
              return;
            }
            touchStart = { id: choice.value, x: event.clientX, y: event.clientY };
          });
          option.addEventListener('pointerup', function (event) {
            if (!touchStart || touchStart.id !== choice.value) return;
            var moved = Math.hypot(event.clientX - touchStart.x, event.clientY - touchStart.y);
            touchStart = null;
            if (moved < 10) addChoice(choice);
            else closeOptions();
          });
          option.addEventListener('pointercancel', function () {
            touchStart = null;
            closeOptions();
          });
          option.addEventListener('click', function () { addChoice(choice); });
        }
        list.append(option);
      });
      list.hidden = false;
      search.setAttribute('aria-expanded', 'true');
      var availableCount = matches.filter(function (choice) { return !choice.disabled; }).length;
      message.textContent = matches.length ? (availableCount ? '' : 'These guests already have an RSVP and can’t be added.') : 'No matching adults to add.';
    }
    function addChoice(choice) {
      if (!choice || choice.disabled || choice.checked) return;
      choice.checked = true;
      choice.dispatchEvent(new Event('change', { bubbles: true }));
      search.value = '';
      renderSelected();
      message.textContent = 'Adult added to your party.';
      closeOptions();
      search.focus({ preventScroll: true });
    }
    search.addEventListener('focus', renderOptions);
    search.addEventListener('click', renderOptions);
    search.addEventListener('input', renderOptions);
    search.addEventListener('blur', function () {
      if (!touchStart) closeOptions();
    });
    search.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { event.preventDefault(); closeOptions(); }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (list.hidden) renderOptions();
        var available = matches.map(function (choice, index) { return choice.disabled ? -1 : index; }).filter(function (index) { return index >= 0; });
        if (!available.length) return;
        var current = available.indexOf(active);
        var next = current < 0 ? (event.key === 'ArrowDown' ? 0 : available.length - 1) : (current + (event.key === 'ArrowDown' ? 1 : -1) + available.length) % available.length;
        active = available[next];
        Array.from(list.children).forEach(function (option, index) { option.setAttribute('aria-selected', String(index === active)); });
        search.setAttribute('aria-activedescendant', list.children[active].id);
        list.children[active].scrollIntoView({ block: 'nearest' });
      }
      if (event.key === 'Enter') {
        event.preventDefault();
        if (!list.hidden) {
          var firstAvailable = matches.findIndex(function (choice) { return !choice.disabled; });
          addChoice(matches[active >= 0 ? active : firstAvailable]);
        }
      }
    });
    renderSelected();
    closeOptions();
  }

  var touched = form.querySelector('[name="_at_rsvp_touched"]');
  function markRsvpTouched(event) {
    var field = event.target;
    if (!touched || !field || !field.matches('input, select, textarea') || field.type === 'hidden') return;
	if (!/^(at_status|at_children_count|at_guest_count|at_guest_names|at_dietary|at_custom_food|at_custom_food_amount|at_notes)$/.test(field.name) && field.name !== 'at_party_guest_ids[]' && field.name.indexOf('at_food_amounts[') !== 0 && field.name.indexOf('at_food_offers[') !== 0) return;
    touched.value = '1';
  }
  form.addEventListener('input', markRsvpTouched);
  form.addEventListener('change', markRsvpTouched);

  function syncFoodOffer(event) {
    var field = event.target;
    var card = field.closest('.at-food-choice');
    if (!card) return;

    var offer = card.querySelector('[name^="at_food_offers["]');
    var amount = card.querySelector('[name^="at_food_amounts["]');
    if (!offer || !amount) return;

    if (field === offer) {
      if (offer.checked && Number(amount.value) < 1) amount.value = '1';
      if (!offer.checked) amount.value = '0';
    } else if (field === amount) {
      offer.checked = Number(amount.value) > 0;
    }
  }
  form.addEventListener('input', syncFoodOffer);
  form.addEventListener('change', syncFoodOffer);

  var guestCount = form.querySelector('[name="at_guest_count"]');
  var guestNames = form.querySelector('[name="at_guest_names"]');
  var groupSignupHint = form.querySelector('[data-at-rsvp-group-hint]');
  function updateGroupSignupHint() {
    if (!guestCount || !guestNames || !groupSignupHint) return;
    var hasMultiplePeople = Number(guestCount.value) > 1;
    var namesMentionMultiplePeople = /\band\b/i.test(guestNames.value);
    groupSignupHint.hidden = !(hasMultiplePeople || namesMentionMultiplePeople);
  }
  if (guestCount) guestCount.addEventListener('change', updateGroupSignupHint);
  if (guestNames) guestNames.addEventListener('input', updateGroupSignupHint);
  updateGroupSignupHint();
}());
