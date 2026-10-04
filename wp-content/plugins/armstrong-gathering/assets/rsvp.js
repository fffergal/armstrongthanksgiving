(function () {
  'use strict';

  var storageKey = 'armstrong-thanksgiving-rsvp-draft';
  if (new URLSearchParams(window.location.search).get('at_rsvp') === 'saved') {
    try { sessionStorage.removeItem(storageKey); } catch (error) {}
  }
  var editableFields = [
    'at_status',
    'at_guest_count',
    'at_guest_names',
    'at_dietary',
    'at_food[]',
    'at_custom_food',
    'at_notes',
    'at_display_name',
    'at_email'
  ];
  var form = document.querySelector('.at-rsvp-form');
  if (!form) return;

  var serverTimeAtLoad = Number(form.getAttribute('data-rsvp-server-now')) || 0;
  var clientTimeAtLoad = Date.now();
  var performanceTimeAtLoad = window.performance && typeof window.performance.now === 'function' ? window.performance.now() : 0;
  var serverClockOffset = serverTimeAtLoad ? serverTimeAtLoad - clientTimeAtLoad : 0;

  function currentServerTime() {
    if (!serverTimeAtLoad) return Date.now();
    var elapsed = window.performance && typeof window.performance.now === 'function'
      ? window.performance.now() - performanceTimeAtLoad
      : Date.now() - clientTimeAtLoad;
    return serverTimeAtLoad + elapsed;
  }

  function isDraftField(field) {
    return editableFields.indexOf(field.name) !== -1;
  }

  function saveDraft() {
    var values = {};
    form.querySelectorAll('[name]').forEach(function (field) {
      if (!isDraftField(field) || field.type === 'password' || field.type === 'hidden' || field.type === 'submit') return;
      var key = field.name;
      if (field.type === 'checkbox') key += '::' + field.value;
      if (field.type === 'radio' && !field.checked) return;
      values[key] = field.type === 'checkbox' ? field.checked : field.value;
    });
    try {
      sessionStorage.setItem(storageKey, JSON.stringify({ path: window.location.pathname, savedAt: currentServerTime(), clock: 'server', values: values }));
    } catch (error) {
      // Storage can be unavailable in private browsing; the form still works normally.
    }
  }

  function restoreDraft() {
    var draft;
    try {
      draft = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
    } catch (error) {
      draft = null;
    }
    var draftSavedAt = draft && Number(draft.savedAt);
    if (draft && draft.clock !== 'server') draftSavedAt += serverClockOffset;
    var age = currentServerTime() - draftSavedAt;
    if (!draft || draft.path !== window.location.pathname || age > 15 * 60 * 1000) {
      if (draft && age > 15 * 60 * 1000) {
        try { sessionStorage.removeItem(storageKey); } catch (error) {}
      }
      return;
    }
    var rsvpUpdatedAt = Number(form.getAttribute('data-rsvp-updated-at')) || 0;
    if (rsvpUpdatedAt && draftSavedAt < rsvpUpdatedAt) {
      try { sessionStorage.removeItem(storageKey); } catch (error) {}
      return;
    }
    form.querySelectorAll('[name]').forEach(function (field) {
      if (!isDraftField(field) || field.type === 'password' || field.type === 'hidden' || field.type === 'submit') return;
      var key = field.name;
      if (field.type === 'checkbox') key += '::' + field.value;
      if (field.type === 'radio' && draft.values[field.name] !== field.value) return;
      if (!(key in draft.values) && !(field.type === 'radio' && field.name in draft.values)) return;
      if (field.type === 'checkbox') field.checked = Boolean(draft.values[key]);
      else if (field.type === 'radio') field.checked = draft.values[field.name] === field.value;
      else field.value = draft.values[key];
      field.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  var loginLink = form.querySelector('[data-at-rsvp-login]');
  if (loginLink) loginLink.addEventListener('click', saveDraft);

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
  restoreDraft();
  updateGroupSignupHint();
}());
