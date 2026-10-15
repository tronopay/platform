import { Controller } from '@hotwired/stimulus';

/*
 * Prepares the shared "remove file" modal for the file the worker clicked.
 *
 * The X button of each file row carries the per-file action URL, CSRF token and
 * original name as data attributes. Bootstrap exposes the clicked button as the
 * `show.bs.modal` event's relatedTarget, so the modal form is pointed at the
 * right URL and token right before it is shown — no per-file markup needed.
 */
export default class extends Controller {
    static targets = ['form', 'token', 'name'];

    connect() {
        this.boundPrepare = this.prepare.bind(this);
        this.element.addEventListener('show.bs.modal', this.boundPrepare);
    }

    disconnect() {
        this.element.removeEventListener('show.bs.modal', this.boundPrepare);
    }

    prepare(event) {
        const trigger = event.relatedTarget;
        if (!(trigger instanceof Element) || !trigger.dataset.fileRemoveUrl) {
            return;
        }
        this.formTarget.action = trigger.dataset.fileRemoveUrl;
        this.tokenTarget.value = trigger.dataset.fileRemoveToken ?? '';
        this.nameTarget.textContent = trigger.dataset.fileRemoveName ?? '';
    }
}