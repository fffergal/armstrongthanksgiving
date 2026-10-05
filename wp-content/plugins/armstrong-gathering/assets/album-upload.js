(() => {
  const enhanceUploadForm = form => {
    const fileInput = form.querySelector('input[type="file"]');
    if (!fileInput || fileInput.dataset.atUploadEnhanced) return;

    fileInput.dataset.atUploadEnhanced = 'true';
    fileInput.setAttribute('aria-label', 'Choose photos to share');
    const fileButton = form.querySelector('input[id$="-display"]');
    fileButton?.setAttribute('aria-label', 'Choose photos');
    if (fileButton) fileButton.value = 'Choose photos';

    const title = form.querySelector('input[name="wppa-user-name"]');
    const description = form.querySelector('textarea[name="wppa-user-desc"]');
    title?.setAttribute('aria-label', 'Image name (optional)');
    description?.setAttribute('aria-label', 'Image description (optional)');

    const progress = form.querySelector('.wppa-percent');
    const uploadMessage = form.querySelector('.wppa-message');
    if (progress && uploadMessage) {
      const updateUploadStatus = () => {
        const status = form.querySelector('.at-album-upload-status');
        const progressText = progress.textContent?.trim() ?? '';
        const messageText = uploadMessage.textContent?.trim() ?? '';
        const hasSuccess = /successfully uploaded/i.test(messageText);
        const hasFailure = /uploads? failed|server error/i.test(`${progressText} ${messageText}`);
        if (status && hasFailure && hasSuccess) {
          status.textContent = 'Some photos uploaded, but others could not be shared. Please review the upload results.';
        } else if (status && hasFailure) {
          status.textContent = 'The photo could not be uploaded. Please try again.';
        } else if (status && hasSuccess) {
          status.textContent = 'Photo uploaded successfully. Refresh to see it in the shared album.';
        }
      };
      const uploadObserver = new MutationObserver(updateUploadStatus);
      const observerOptions = { childList: true, characterData: true, subtree: true };
      uploadObserver.observe(progress, observerOptions);
      uploadObserver.observe(uploadMessage, observerOptions);
    }

    fileInput.addEventListener('change', () => {
      const files = Array.from(fileInput.files ?? []);
      const submit = form.querySelector('input.wppa-user-submit');
      if (!submit) return;

      if (fileButton) fileButton.value = 'Choose photos';

      let status = form.querySelector('.at-album-upload-status');
      if (!status) {
        status = document.createElement('p');
        status.className = 'at-album-upload-status';
        status.setAttribute('role', 'status');
        form.querySelector('.wppa-upload-table')?.before(status);
      }

      if (files.length === 0) {
        status.remove();
        submit.value = 'Upload photo';
        return;
      }

      const count = files.length;
      const firstName = files[0].name;
      const more = count > 1 ? ` and ${count - 1} more ${count === 2 ? 'photo' : 'photos'}` : '';
      status.textContent = `${count} ${count === 1 ? 'photo' : 'photos'} selected: ${firstName}${more}. Review the details, then share below.`;
      submit.value = count === 1 ? 'Share photo' : `Share ${count} photos`;
    });
  };

  const enhanceAllForms = () => {
    document.querySelectorAll('.wppa-container form[id^="wppa-uplform-"]').forEach(enhanceUploadForm);
  };

  enhanceAllForms();
  new MutationObserver(enhanceAllForms).observe(document.body, { childList: true, subtree: true });
})();
