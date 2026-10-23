(() => {
  'use strict';
  document.querySelectorAll('[data-player]').forEach((wrapper) => {
    const video = wrapper.querySelector('[data-video]');
    const status = wrapper.querySelector('[data-player-status]');
    const retry = wrapper.querySelector('[data-player-retry]');
    if (!video || !wrapper.dataset.mediaUrl) return;
    let expiresAt = 0;
    let refreshing = false;
    let historyRecorded = false;
    let historyPending = false;
    let lastErrorRefresh = 0;
    let timer = null;
    const csrf = () => wrapper.dataset.csrf || wrapper.querySelector('[name="_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content || '';
    const say = (message) => { if (status) status.textContent = message; };
    const fail = (message) => { say(message); if (retry) retry.hidden = false; };

    const renew = async (initial = false) => {
      if (refreshing) return;
      refreshing = true;
      if (retry) retry.hidden = true;
      const position = initial ? 0 : (video.currentTime || 0);
      const resume = !initial && !video.paused && !video.ended;
      try {
        if (initial) say('Preparando la reproducción…');
        const response = await fetch(wrapper.dataset.mediaUrl, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.url) {
          const message = typeof data.error === 'string' ? data.error : data.message;
          throw new Error(message || (response.status === 401 ? 'Tu sesión ha vencido. Inicia sesión de nuevo para continuar.' : 'No se pudo obtener este video.'));
        }
        expiresAt = Date.now() + Number(data.expires_in || 300) * 1000;
        const restore = () => {
          if (position > 0 && Number.isFinite(video.duration)) video.currentTime = Math.min(position, Math.max(0, video.duration - 0.1));
          if (resume) video.play().catch(() => say('Pulsa reproducir para continuar.'));
          else say('');
        };
        video.onloadedmetadata = restore;
        video.src = data.url;
        video.load();
        clearTimeout(timer);
        // Renew while active before expiry. Paused players renew when resumed.
        timer = setTimeout(() => {
          if (!video.paused && !video.ended) renew();
        }, Math.max(30000, (Number(data.expires_in || 300) - 60) * 1000));
      } catch (error) {
        fail(error.message || 'No se pudo preparar la reproducción.');
      } finally {
        refreshing = false;
      }
    };

    const record = async () => {
      if (historyRecorded || historyPending || wrapper.dataset.historyEnabled !== '1' || !wrapper.dataset.historyToken) return;
      historyPending = true;
      try {
        const response = await fetch(wrapper.dataset.historyUrl, {
          method: 'POST', credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() },
          body: new URLSearchParams({ _token: csrf(), pelicula_id: wrapper.dataset.movieId, tipo: wrapper.dataset.kind, playback_token: wrapper.dataset.historyToken }),
        });
        if (response.ok) historyRecorded = true;
      } catch { /* A history failure must not interrupt valid playback; next played retries. */ }
      finally { historyPending = false; }
    };

    video.addEventListener('playing', () => { say(''); record(); });
    video.addEventListener('play', () => {
      if (expiresAt && Date.now() > expiresAt - 30000) renew();
    });
    video.addEventListener('seeking', () => {
      if (expiresAt && Date.now() > expiresAt - 30000) renew();
    });
    video.addEventListener('error', () => {
      if (!video.src || refreshing) return;
      // Expired EC2 role credentials may invalidate the URL before its requested TTL.
      if (Date.now() - lastErrorRefresh > 30000 && video.error?.code !== MediaError.MEDIA_ERR_DECODE) {
        lastErrorRefresh = Date.now();
        renew();
      } else {
        fail('No se pudo reproducir el archivo. Reintenta; si continúa, comprueba que sea MP4 compatible con tu navegador.');
      }
    });
    retry?.addEventListener('click', () => renew(!video.currentSrc));
    window.addEventListener('pagehide', () => clearTimeout(timer), { once: true });
    renew(true);
  });
})();
