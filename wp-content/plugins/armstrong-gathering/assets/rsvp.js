(function () {
  'use strict';

  var form = document.querySelector('.at-rsvp-form');
  var roster = form ? form.querySelector('.at-party-roster') : null;
  if (roster) {
    var app = form.closest('.at-rsvp-app');
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
    var invitePrompt = document.createElement('div');
    invitePrompt.className = 'at-party-invite-prompt';
    invitePrompt.hidden = true;
    var invitePromptText = document.createElement('span');
    invitePromptText.textContent = 'Can’t find who you’re looking for?';
    var inviteOpen = document.createElement('button');
    inviteOpen.type = 'button';
    inviteOpen.className = 'at-link-button';
    inviteOpen.textContent = 'Request an invite';
    invitePrompt.append(invitePromptText, inviteOpen);
    var inviteFields = document.createElement('div');
    inviteFields.className = 'at-party-invite-fields';
    inviteFields.hidden = true;
    inviteFields.setAttribute('role', 'group');
    inviteFields.setAttribute('aria-label', 'Request an invitation');
    var inviteIntro = document.createElement('p');
    inviteIntro.textContent = 'We’ll ask the hosts to review this request. If approved, they’ll email a sign-up link. Once the person claims it, they’ll appear here as a guest.';
    var inviteAlternative = document.createElement('p');
    inviteAlternative.className = 'at-party-invite-alternative';
    inviteAlternative.textContent = 'Don’t have their email? Ask them to contact the hosts for an invitation. Once they claim it, you can add them from this list.';
    var inviteNameLabel = document.createElement('label');
    inviteNameLabel.textContent = 'Their name';
    var inviteName = document.createElement('input');
    inviteName.type = 'text';
    inviteName.name = 'at_party_invite_name';
    inviteName.autocomplete = 'name';
    inviteNameLabel.append(inviteName);
    var inviteEmailLabel = document.createElement('label');
    inviteEmailLabel.textContent = 'Their email';
    var inviteEmail = document.createElement('input');
    inviteEmail.type = 'email';
    inviteEmail.name = 'at_party_invite_email';
    inviteEmail.autocomplete = 'email';
    inviteEmailLabel.append(inviteEmail);
    var inviteActions = document.createElement('div');
    inviteActions.className = 'at-party-invite-actions';
    var inviteSubmit = document.createElement('button');
    inviteSubmit.type = 'button';
    inviteSubmit.className = 'at-button';
    inviteSubmit.textContent = 'Request invite';
    var inviteCancel = document.createElement('button');
    inviteCancel.type = 'button';
    inviteCancel.className = 'at-link-button';
    inviteCancel.textContent = 'Cancel';
    inviteActions.append(inviteSubmit, inviteCancel);
    var inviteStatus = document.createElement('p');
    inviteStatus.className = 'at-party-invite-status';
    inviteStatus.setAttribute('role', 'status');
    inviteStatus.setAttribute('aria-live', 'polite');
    inviteFields.append(inviteIntro, inviteAlternative, inviteNameLabel, inviteEmailLabel, inviteActions, inviteStatus);
    var active = -1;
    var matches = [];
    var touchStart = null;
    choices.forEach(function (choice) { choice.parentElement.hidden = true; });
    roster.classList.add('at-party-enhanced');
    roster.append(selected, search, list, invitePrompt, inviteFields, message);

    function closeOptions() {
      list.hidden = true;
      invitePrompt.hidden = true;
      search.setAttribute('aria-expanded', 'false');
      search.removeAttribute('aria-activedescendant');
      active = -1;
    }
    function renderSelected() {
      selected.replaceChildren();
      choices.filter(function (choice) { return choice.checked; }).forEach(function (choice) {
        var nameNode = choice.parentElement.querySelector('[data-party-name]') || choice.parentElement.querySelector('span');
        var name = (nameNode ? nameNode.textContent : choice.parentElement.textContent).trim();
        var status = choice.parentElement.querySelector('small');
        var isOwner = choice.parentElement.dataset.partyOwner === 'true';
        var chip = document.createElement(isOwner || choice.disabled ? 'span' : 'button');
        chip.className = 'at-party-chip' + (choice.parentElement.dataset.partyState ? ' is-pending' : '');
        chip.textContent = name + (status ? ' · ' + status.textContent.trim() : '') + (isOwner || choice.disabled ? '' : ' ×');
        if (!isOwner && !choice.disabled) {
          chip.type = 'button';
          chip.setAttribute('aria-label', 'Remove ' + name + ' from your party' + (status ? ' — ' + status.textContent.trim() : ''));
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
        return !choice.checked && !choice.parentElement.dataset.partyState && choice.parentElement.textContent.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase());
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
      invitePrompt.hidden = false;
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
    inviteOpen.addEventListener('pointerdown', function (event) { event.preventDefault(); });
    inviteOpen.addEventListener('click', function () {
      inviteFields.hidden = false;
      invitePrompt.hidden = true;
      inviteStatus.textContent = '';
      inviteName.focus();
    });
    inviteCancel.addEventListener('click', function () {
      inviteFields.hidden = true;
      inviteName.value = '';
      inviteEmail.value = '';
      inviteStatus.textContent = '';
      search.focus();
    });
    inviteSubmit.addEventListener('click', function () {
      if (!inviteName.value.trim() || !inviteEmail.value.trim() || !inviteEmail.checkValidity()) {
        inviteStatus.textContent = 'Enter their name and a valid email address.';
        if (!inviteName.value.trim()) inviteName.focus();
        else inviteEmail.focus();
        return;
      }
      inviteSubmit.disabled = true;
      inviteStatus.textContent = 'Sending request to the hosts…';
      var payload = new FormData();
      payload.set('action', 'at_gathering_request_party_invitation');
      payload.set('nonce', app.dataset.partyInviteNonce);
      payload.set('name', inviteName.value.trim());
      payload.set('email', inviteEmail.value.trim());
      fetch(app.dataset.partyInviteUrl, { method: 'POST', credentials: 'same-origin', body: payload })
        .then(function (response) { return response.json().then(function (body) { return { ok: response.ok, body: body }; }); })
        .then(function (result) {
          if (!result.ok || !result.body.success) throw new Error(result.body.data && result.body.data.message ? result.body.data.message : 'We couldn’t request that invitation. Please try again or ask the hosts to help.');
          var guest = result.body.data.guest;
          var existingChoice = choices.find(function (choice) { return choice.value === String(guest.id); });
          if (existingChoice) {
            existingChoice.checked = true;
            existingChoice.dispatchEvent(new Event('change', { bubbles: true }));
            renderSelected();
            inviteName.value = '';
            inviteEmail.value = '';
            inviteStatus.textContent = result.body.data.reused ? guest.name + ' was added back to your party.' : guest.name + ' is already in your party.';
            inviteName.focus();
            return;
          }
          var label = document.createElement('label');
          label.className = 'at-party-pending-choice';
          label.dataset.partyState = guest.state;
          label.hidden = true;
          var checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.name = 'at_party_guest_ids[]';
          checkbox.value = String(guest.id);
          checkbox.checked = true;
          var labelText = document.createElement('span');
          var guestName = document.createElement('span');
          guestName.dataset.partyName = '';
          guestName.textContent = guest.name;
          var guestStatus = document.createElement('small');
          guestStatus.textContent = guest.state === 'invited' ? 'Invitation sent · waiting for sign-up' : 'Host review pending';
          labelText.append(guestName, document.createTextNode(' '), guestStatus);
          label.append(checkbox, labelText);
          roster.insertBefore(label, selected);
          choices.push(checkbox);
          checkbox.dispatchEvent(new Event('change', { bubbles: true }));
          renderSelected();
          inviteName.value = '';
          inviteEmail.value = '';
          inviteStatus.textContent = result.body.data.reused ? guest.name + ' is already pending in your party.' : 'The hosts will review this request. Once approved, ' + guest.name + ' will receive a sign-up link.';
          inviteName.focus();
        })
        .catch(function (error) { inviteStatus.textContent = error.message; })
        .finally(function () { inviteSubmit.disabled = false; });
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

  if (form) {
  var touched = form.querySelector('[name="_at_rsvp_touched"]');
  function markRsvpTouched(event) {
    var field = event.target;
    if (!touched || !field || !field.matches('input, select, textarea') || field.type === 'hidden') return;
	if (!/^(at_status|at_children_count|at_dietary|at_custom_food|at_custom_food_amount|at_notes)$/.test(field.name) && field.name !== 'at_party_guest_ids[]' && field.name.indexOf('at_food_amounts[') !== 0 && field.name.indexOf('at_food_offers[') !== 0) return;
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

  }

  var leaveButton = document.querySelector('[data-party-leave]');
  if (leaveButton) {
    leaveButton.addEventListener('click', function () {
      var panel = leaveButton.closest('[data-party-leave-panel]');
      var status = panel.querySelector('.at-party-leave-status');
      var app = leaveButton.closest('.at-rsvp-app');
      leaveButton.disabled = true;
      status.textContent = 'Removing you from their party…';
      var payload = new FormData();
      payload.set('action', 'at_gathering_leave_party');
      payload.set('nonce', app.dataset.partyLeaveNonce);
      fetch(app.dataset.partyInviteUrl, { method: 'POST', credentials: 'same-origin', body: payload })
        .then(function (response) { return response.json().then(function (body) { return { ok: response.ok, body: body }; }); })
        .then(function (result) {
          if (!result.ok || !result.body.success) throw new Error(result.body.data && result.body.data.message ? result.body.data.message : 'We couldn’t remove you. Please refresh and try again.');
          status.textContent = result.body.data.message;
          window.setTimeout(function () { window.location.reload(); }, 600);
        })
        .catch(function (error) { status.textContent = error.message; leaveButton.disabled = false; });
    });
  }
}());
