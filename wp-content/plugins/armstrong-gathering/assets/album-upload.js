(() => {
  const enhanceUploadForm = form => {
    const fileInput = form.querySelector('input[type="file"]');
    if (!fileInput || fileInput.dataset.atUploadEnhanced) return;

    fileInput.dataset.atUploadEnhanced = 'true';
    fileInput.setAttribute('aria-label', 'Choose photos to share');

    const title = form.querySelector('input[name="wppa-user-name"]');
    const description = form.querySelector('textarea[name="wppa-user-desc"]');
    title?.setAttribute('aria-label', 'Photo title (optional)');
    description?.setAttribute('aria-label', 'Photo description (optional)');

    const progress = form.querySelector('.wppa-percent');
    if (progress) {
      new MutationObserver(() => {
        const status = form.querySelector('.at-album-upload-status');
        if (progress.textContent?.trim() === 'Done!' && status) {
          status.textContent = 'Photo uploaded successfully. Refresh to see it in the shared album.';
        }
      }).observe(progress, { childList: true, characterData: true, subtree: true });
    }

    fileInput.addEventListener('change', () => {
      const files = Array.from(fileInput.files ?? []);
      const submit = form.querySelector('input.wppa-user-submit');
      if (!submit) return;

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
