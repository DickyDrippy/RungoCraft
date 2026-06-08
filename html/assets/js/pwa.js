(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  let deferredPrompt = null;

  function post(action, data = {}) {
    const body = new URLSearchParams({ _csrf: csrf, action, ...data });
    return fetch('/', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body
    }).catch(() => null);
  }

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/service-worker.js').catch(() => null);
    });
  }

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
    document.documentElement.classList.add('pwa-install-ready');
    post('pwa_install_event', { event_type: 'prompt_ready', platform: navigator.platform || 'web' });
  });

  window.addEventListener('appinstalled', () => {
    document.documentElement.classList.add('pwa-installed');
    post('pwa_install_event', { event_type: 'installed', platform: navigator.platform || 'web' });
  });

  document.addEventListener('click', async (event) => {
    const installButton = event.target.closest('[data-pwa-install]');
    if (installButton) {
      if (!deferredPrompt) {
        if (window.showToast) {
          window.showToast('Браузер поки не запропонував встановлення. Спробуйте меню браузера: Додати на головний екран.');
        }
        post('pwa_install_event', { event_type: 'manual_hint', platform: navigator.platform || 'web' });
        return;
      }

      deferredPrompt.prompt();
      const result = await deferredPrompt.userChoice.catch(() => ({ outcome: 'unknown' }));
      post('pwa_install_event', { event_type: 'prompt_' + result.outcome, platform: navigator.platform || 'web' });
      deferredPrompt = null;
    }

    const pushButton = event.target.closest('[data-pwa-push]');
    if (pushButton) {
      if (!('Notification' in window)) {
        if (window.showToast) window.showToast('Цей браузер не підтримує push-сповіщення.');
        return;
      }

      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        if (window.showToast) window.showToast('Push-сповіщення не дозволені.');
        return;
      }

      post('pwa_install_event', { event_type: 'push_permission_granted', platform: navigator.platform || 'web' });
      if (window.showToast) window.showToast('Дозвіл на сповіщення отримано.');
    }
  });
})();
