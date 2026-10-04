(function () {
  'use strict';

  var form = document.querySelector('.at-rsvp-form');
  if (!form) return;

  var touched = form.querySelector('[name="_at_rsvp_touched"]');
  function markRsvpTouched(event) {
    var field = event.target;
    if (!touched || !field || !field.matches('input, select, textarea') || field.type === 'hidden') return;
	if (!/^(at_status|at_guest_count|at_guest_names|at_dietary|at_custom_food|at_custom_food_amount|at_notes)$/.test(field.name) && field.name.indexOf('at_food_amounts[') !== 0 && field.name.indexOf('at_food_offers[') !== 0) return;
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
