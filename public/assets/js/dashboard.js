(() => {
  const toast = document.getElementById('toast');
  let timer;
  const notify = (message) => {
    toast.textContent = message;
    toast.classList.add('visible');
    clearTimeout(timer);
    timer = setTimeout(() => toast.classList.remove('visible'), 3200);
  };
  document.getElementById('refresh-demo')?.addEventListener('click', () => notify('Demo view refreshed. No live data connection.'));
  document.getElementById('storm-button')?.addEventListener('click', () => {
    if (window.confirm('Demo only: show the STORM · TURN BACK action preview? No command will be sent.')) {
      notify('Demo preview only. No command was queued or transmitted.');
    }
  });
  document.querySelectorAll('.acknowledge').forEach((button) => button.addEventListener('click', () => {
    button.closest('.alert-item')?.classList.add('acknowledged');
    button.textContent = 'Demo alert acknowledged';
    button.disabled = true;
  }));
})();
