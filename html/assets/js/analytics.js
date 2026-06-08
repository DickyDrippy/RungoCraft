(function () {
    const csrf = document.querySelector('input[name="_csrf"]')?.value;
    if (!csrf || !navigator.sendBeacon) return;

    function send(eventType, entityType, entityId, payload) {
        const data = new FormData();
        data.append('_csrf', csrf);
        data.append('action', 'analytics_track');
        data.append('event_type', eventType);
        if (entityType) data.append('entity_type', entityType);
        if (entityId) data.append('entity_id', String(entityId));
        if (payload) data.append('payload', JSON.stringify(payload));
        navigator.sendBeacon('/', data);
    }

    document.addEventListener('click', function (event) {
        const target = event.target.closest('[data-analytics-event]');
        if (!target) return;

        send(
            target.dataset.analyticsEvent,
            target.dataset.analyticsEntity || '',
            target.dataset.analyticsId || '',
            { text: target.textContent.trim().slice(0, 80), href: target.getAttribute('href') || '' }
        );
    });

    let maxScroll = 0;
    window.addEventListener('scroll', function () {
        const height = document.documentElement.scrollHeight - window.innerHeight;
        if (height <= 0) return;
        const percent = Math.round((window.scrollY / height) * 100);
        if (percent >= 75 && maxScroll < 75) {
            maxScroll = 75;
            send('scroll_75', 'page', '', { url: location.pathname });
        }
    }, { passive: true });
})();
