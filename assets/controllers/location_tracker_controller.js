import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'tronopay_worker_location';

/**
 * Keeps the worker's geo-location fresh on the cabinet pages. The first device
 * position is captured on the profile page (explicit consent), cached in
 * localStorage, and this controller re-reports it silently on page load and,
 * with keepalive, right before each cabinet action (take, save, complete,
 * remove file, release) — never blocking or delaying the action.
 *
 * When the worker has already granted the geolocation permission, the position
 * is re-read from the device on page load (no prompt, no user gesture needed)
 * so the stored location stays near real-time without visiting the profile.
 */
export default class extends Controller {
    static values = { token: String, url: String };

    connect() {
        this.submitListener = (event) => this.onSubmit(event);

        this.sendCached(false);
        this.refreshDevicePosition();

        document.addEventListener('submit', this.submitListener, true);
    }

    disconnect() {
        document.removeEventListener('submit', this.submitListener, true);
    }

    onSubmit(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const action = form.getAttribute('action') || '';
        if (!action.includes('/cabinet/')) {
            return;
        }

        this.sendCached(true);
    }

    /**
     * Re-reads the device position without prompting when the browser already
     * holds the geolocation permission, caches the result and reports it.
     * Errors and missing permission are ignored — the cached position (if any)
     * and the manual profile flow cover those cases.
     */
    async refreshDevicePosition() {
        if (!('geolocation' in navigator)) {
            return;
        }

        try {
            if (navigator.permissions?.query) {
                const { state } = await navigator.permissions.query({ name: 'geolocation' });
                if (state !== 'granted') {
                    return;
                }
            }
        } catch {
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) => {
                // Stored with the same precision the profile form uses.
                const lat = Number(position.coords.latitude.toFixed(6));
                const lng = Number(position.coords.longitude.toFixed(6));
                this.cache(lat, lng);
                this.send(lat, lng, false);
            },
            () => {
                // The permission was granted but the fix failed — the cached
                // position (if any) still covers actions on this page.
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60_000 },
        );
    }

    sendCached(keepalive) {
        const cached = this.readCache();
        if (!cached) {
            return;
        }

        this.send(cached.lat, cached.lng, keepalive);
    }

    cache(lat, lng) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
                lat,
                lng,
                ts: Date.now(),
            }));
        } catch {
            // localStorage unavailable — the current page still reports once.
        }
    }

    readCache() {
        try {
            const raw = window.localStorage.getItem(STORAGE_KEY);
            if (!raw) {
                return null;
            }

            const data = JSON.parse(raw);
            if (typeof data?.lat !== 'number' || typeof data?.lng !== 'number') {
                return null;
            }

            return data;
        } catch {
            return null;
        }
    }

    send(lat, lng, keepalive) {
        const body = new URLSearchParams();
        body.set('_token', this.tokenValue);
        body.set('lat', String(lat));
        body.set('lng', String(lng));

        const init = {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body,
        };

        if (keepalive) {
            init.keepalive = true;
        }

        return fetch(this.urlValue, init).catch(() => {});
    }
}