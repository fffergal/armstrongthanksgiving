(function () {
  'use strict';

  var form = document.querySelector('.at-rsvp-form');
  if (!form) return;

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
