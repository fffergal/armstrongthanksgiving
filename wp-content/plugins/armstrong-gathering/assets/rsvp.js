(function () {
  'use strict';

  var form = document.querySelector('.at-rsvp-form');
  if (!form) return;

  var touched = form.querySelector('[name="_at_rsvp_touched"]');
  function markRsvpTouched(event) {
    var field = event.target;
    if (!touched || !field || !field.matches('input, select, textarea') || field.type === 'hidden') return;
    if (!/^(at_status|at_guest_count|at_guest_names|at_dietary|at_food\[\]|at_custom_food|at_notes)$/.test(field.name)) return;
    touched.value = '1';
  }
  form.addEventListener('input', markRsvpTouched);
  form.addEventListener('change', markRsvpTouched);

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
