import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'tronopay_worker_location';

/**
 * The profile page location editor. "Use my device location" reads the
 * browser geolocation into the latitude/longitude inputs (manual fallback
 * stays available), caches the position so the location tracker can re-use
 * it, and guides the user through the consent path.
 */
export default class extends Controller {
    static targets = ['latitude', 'longitude', 'status'];

    locate() {
        const statusText = this.statusTarget;

        if (!('geolocation' in navigator)) {
            statusText.textContent = 'Geolocation is not available — enter the coordinates manually.';
            return;
        }

        statusText.textContent = 'Requesting your position…';

        navigator.geolocation.getCurrentPosition(
            (position) => {
                const lat = position.coords.latitude.toFixed(6);
                const lng = position.coords.longitude.toFixed(6);
                this.latitudeTarget.value = lat;
                this.longitudeTarget.value = lng;

                try {
                    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
                        lat: Number(lat),
                        lng: Number(lng),
                        ts: Date.now(),
                    }));
                } catch {
                    // localStorage unavailable — the manual inputs are enough.
                }

                statusText.textContent = 'Device position captured — press Save.';
            },
            () => {
                statusText.textContent = 'Could not get the device position — enter the coordinates manually.';
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60_000 },
        );
    }
}