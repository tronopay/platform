import { Controller } from '@hotwired/stimulus';

/*
 * Live countdown to a task deadline.
 *
 * Uses the server-provided deadline/now pair and only measures the client-side
 * elapsed time, so the user's clock cannot skew the remaining time.
 *
 * The urgency colour is switched here too: the server picks the alert class for
 * the moment the page was rendered, so a worker who leaves the page open would
 * otherwise never see the switch to "danger" when the last 15 minutes begin.
 */
export default class extends Controller {
    static targets = ['value'];
    static values = {
        deadline: String,
        now: String,
        urgencyThreshold: { type: Number, default: 900 },
    };

    connect() {
        this.offset = Date.now() - new Date(this.nowValue).getTime();
        this.initialRemaining = this.remaining();
        this.reloaded = false;
        this.render();

        this.interval = setInterval(() => this.render(), 1000);
    }

    disconnect() {
        clearInterval(this.interval);
    }

    remaining() {
        return new Date(this.deadlineValue).getTime() - (Date.now() - this.offset);
    }

    render() {
        const remaining = this.remaining();

        if (remaining <= 0) {
            this.valueTarget.textContent = '00:00:00';
            this.setUrgent(true);

            // The server finalizes an expired task while rendering this very
            // page, so a page that *arrives* already expired must not reload
            // itself — if finalization had failed, that would loop forever.
            // Only a crossing observed while the page is open reloads, and
            // only once: the server needs the fresh request to finalize.
            if (this.initialRemaining > 0 && !this.reloaded) {
                this.reloaded = true;
                window.location.reload();
            }

            return;
        }

        const total = Math.floor(remaining / 1000);
        const hours = String(Math.floor(total / 3600)).padStart(2, '0');
        const minutes = String(Math.floor((total % 3600) / 60)).padStart(2, '0');
        const seconds = String(total % 60).padStart(2, '0');

        this.valueTarget.textContent = `${hours}:${minutes}:${seconds}`;
        this.setUrgent(remaining < this.urgencyThresholdValue * 1000);
    }

    setUrgent(urgent) {
        this.element.classList.toggle('alert-danger', urgent);
        this.element.classList.toggle('alert-warning', !urgent);
    }
}
