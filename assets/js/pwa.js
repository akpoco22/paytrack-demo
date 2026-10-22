/* Extol Global Service PWA bootstrap: service worker registration + install banner */
(function () {
  // Register service worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/public/sw.js').catch(err => console.log('SW error:', err));
    });
  }

  // Install banner (injected; skipped if page already has one)
  let deferredPrompt = null;

  function ensureBanner() {
    if (document.getElementById('installBanner')) return document.getElementById('installBanner');
    const bar = document.createElement('div');
    bar.id = 'installBanner';
    bar.className = 'hidden fixed top-0 left-0 right-0 bg-blue-600 text-white px-4 py-3 z-50 shadow-lg';
    bar.innerHTML =
      '<div class="flex items-center justify-between gap-3">' +
        '<div class="flex items-center gap-3">' +
          '<span class="text-xl">📱</span>' +
          '<div><p class="font-semibold text-sm">Install Extol</p>' +
          '<p class="text-xs text-blue-200">Add to your home screen for quick access</p></div>' +
        '</div>' +
        '<div class="flex items-center gap-2">' +
          '<button id="pwaInstallBtn" class="bg-white text-blue-600 px-3 py-1 rounded-lg text-sm font-bold">Install</button>' +
          '<button id="pwaDismissBtn" class="text-blue-200 text-lg leading-none px-1">✕</button>' +
        '</div>' +
      '</div>';
    document.body.prepend(bar);
    bar.querySelector('#pwaDismissBtn').addEventListener('click', () => bar.classList.add('hidden'));
    bar.querySelector('#pwaInstallBtn').addEventListener('click', installApp);
    return bar;
  }

  window.installApp = function () {
    if (deferredPrompt) {
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(() => {
        deferredPrompt = null;
        const bar = document.getElementById('installBanner');
        if (bar) bar.classList.add('hidden');
      });
    }
  };

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    ensureBanner().classList.remove('hidden');
  });
})();
